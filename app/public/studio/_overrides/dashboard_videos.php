<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale (compreso il servizio di caricamento usato dal popup), cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/videos.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'videos';
$pageTitle = 'Video';
$error = null;

function videoJson(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_POST['ajax']);

    if ($action === 'upload') {
        // Un video per richiesta (il browser li invia uno alla volta, con avanzamento): così il
        // limite di dimensione vale per il singolo file e un errore non perde gli altri.
        $f = $_FILES['video'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $msg = match ($f['error'] ?? UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Il file supera il limite di dimensione del server.',
                UPLOAD_ERR_NO_FILE => 'Nessun file ricevuto.',
                default => 'Caricamento non riuscito, riprova.',
            };
            videoJson(['ok' => false, 'error' => $msg], 422);
        }
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            $title = trim(preg_replace('/[_\-]+/', ' ', pathinfo($f['name'], PATHINFO_FILENAME)));
        }
        if ($title === '') {
            videoJson(['ok' => false, 'error' => 'Il titolo è obbligatorio.'], 422);
        }
        $check = videoValidateFile($f['tmp_name'], $f['name'], (int) $f['size']);
        if (isset($check['error'])) {
            videoJson(['ok' => false, 'error' => $check['error']], 422);
        }
        $rel = videoStoreFile($f['tmp_name'], $profile['slug'], $check['ext'], true);
        if (!$rel) {
            videoJson(['ok' => false, 'error' => 'Salvataggio del file non riuscito.'], 500);
        }
        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        $id = videoCreateRecord((int) $profile['id'], $profile['slug'], $rel, $check['mime'], (int) $f['size'], [
            'title' => $title,
            'description' => trim($_POST['description'] ?? ''),
            'is_public' => $visibility === 'public',
            'in_feed' => !empty($_POST['in_feed']),
            'publish_at' => $publishAt,
        ]);
        setContentAuthor('videos', $id, dashboardAuthorSignature($user));
        $stmt = getDB()->prepare('SELECT * FROM videos WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($visibility === 'public' && !$publishAt) {
            notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'video', $title, videoPublicUrl($profile['slug'], $id));
            videoEnqueueSocial((int) $profile['id'], $profile['slug'], $row);
        }
        videoJson(['ok' => true, 'id' => $id, 'title' => $title]);
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $stmt = getDB()->prepare('SELECT * FROM videos WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            $error = 'Video non trovato.';
        } elseif ($title === '') {
            $error = 'Il titolo è obbligatorio.';
        } else {
            $isPublic = ($_POST['visibility'] ?? 'public') === 'private' ? 0 : 1;
            $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
            $thumb = $row['thumb_path'];
            if (!empty($_FILES['thumb']['tmp_name']) && $_FILES['thumb']['error'] === UPLOAD_ERR_OK) {
                $newThumb = videoStoreThumbFromBytes((string) file_get_contents($_FILES['thumb']['tmp_name']), $profile['slug']);
                if ($newThumb) {
                    videoDeleteFile($thumb);
                    $thumb = $newThumb;
                }
            }
            getDB()->prepare('UPDATE videos SET title=?, description=?, thumb_path=?, is_public=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?')
                ->execute([mb_substr($title, 0, 150), trim($_POST['description'] ?? '') ?: null, $thumb, $isPublic, !empty($_POST['in_feed']) ? 1 : 0, $publishAt, $id, $profile['id']]);
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT * FROM videos WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            videoDeleteFiles($row);
            getDB()->prepare('DELETE FROM videos WHERE id=? AND user_id=?')->execute([$id, $profile['id']]);
        }
    } elseif ($action === 'regen_thumb' || $action === 'regen_missing') {
        if (!videoHasFfmpeg()) {
            $error = 'ffmpeg non è installato in questo container: le anteprime automatiche non sono possibili finché l\'immagine dell\'app non viene ricostruita. Puoi caricare un\'immagine a mano da "Modifica".';
        } else {
            if ($action === 'regen_thumb') {
                $stmt = getDB()->prepare('SELECT * FROM videos WHERE id=? AND user_id=?');
                $stmt->execute([(int) ($_POST['id'] ?? 0), $profile['id']]);
            } else {
                $stmt = getDB()->prepare("SELECT * FROM videos WHERE user_id=? AND (thumb_path IS NULL OR thumb_path = '')");
                $stmt->execute([$profile['id']]);
            }
            $made = 0;
            foreach ($stmt->fetchAll() as $row) {
                [$d, $w, $h] = videoProbe('/var/www/html/' . $row['video_path']);
                $newThumb = videoMakeThumbnail($row['video_path'], $profile['slug'], $d);
                if ($newThumb) {
                    videoDeleteFile($row['thumb_path']);
                    getDB()->prepare('UPDATE videos SET thumb_path=?, duration_seconds=COALESCE(duration_seconds, ?), width=COALESCE(width, ?), height=COALESCE(height, ?) WHERE id=? AND user_id=?')
                        ->execute([$newThumb, $d, $w, $h, $row['id'], $profile['id']]);
                    $made++;
                }
            }
            header('Location: /dashboard_videos.php?regen=' . $made);
            exit;
        }
    } elseif ($action === 'move_up' || $action === 'move_down') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id FROM videos WHERE user_id=? ORDER BY sort_order DESC, id DESC');
        $stmt->execute([$profile['id']]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        $idx = array_search($id, $ids, true);
        if ($idx !== false) {
            $swap = $action === 'move_up' ? $idx - 1 : $idx + 1;
            if (isset($ids[$swap])) {
                [$ids[$idx], $ids[$swap]] = [$ids[$swap], $ids[$idx]];
                videoApplyOrder((int) $profile['id'], $ids);
            }
        }
    }
    if (!$error) {
        header('Location: /dashboard_videos.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM videos WHERE user_id=? ORDER BY sort_order DESC, id DESC');
$stmt->execute([$profile['id']]);
$videos = $stmt->fetchAll();
$maxMb = (int) (VIDEO_MAX_BYTES / 1048576);

include __DIR__ . '/../_dash_header.php';
$missingThumbs = count(array_filter($videos, fn($v) => empty($v['thumb_path'])));
$schedN = count(array_filter($videos, fn($v) => $v['publish_at'] && strtotime($v['publish_at']) > time()));
$privN = count(array_filter($videos, fn($v) => !(int) $v['is_public']));
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['regen'])): ?>
    <div class="alert <?= (int) $_GET['regen'] > 0 ? 'success' : 'error' ?>"><?= (int) $_GET['regen'] > 0 ? 'Anteprime generate: ' . (int) $_GET['regen'] . '.' : 'Nessuna anteprima generata (controlla che i file video siano leggibili).' ?></div>
  <?php endif; ?>
  <?php if (!videoHasFfmpeg()): ?>
    <div class="alert error">Le anteprime automatiche non sono disponibili: <strong>ffmpeg non è installato</strong> in questo container. I video si riproducono comunque, ma senza immagine di copertina.</div>
  <?php elseif ($missingThumbs > 0): ?>
    <form method="post" class="blx-add">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="regen_missing">
      <span><?= $missingThumbs ?> video senza anteprima.</span>
      <button class="blx-new" type="submit">Genera le anteprime mancanti</button>
    </form>
  <?php endif; ?>

  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($videos) ?></b><span>Video</span></div>
      <div class="tlx-kpi"><b><?= $schedN ?></b><span>Programmati</span></div>
      <div class="tlx-kpi"><b><?= $privN ?></b><span>Solo io</span></div>
      <div class="tlx-kpi"><b><?= $maxMb ?> MB</b><span>Limite per file</span></div>
    </div>

    <?php studioComposer($profile, ['label' => 'Carica un video o scrivi qualcosa', 'open' => !empty($error)]); ?>

    <div class="tlx-listhead"><h2>I tuoi video <small>(<?= count($videos) ?>)</small></h2></div>
    <?php if (!$videos): ?>
      <div class="tlx-empty"><i class="fa-solid fa-film"></i><b>Nessun video ancora</b><span>Usa la barra qui sopra: scegli un video e pubblicalo come un aggiornamento.</span></div>
    <?php endif; ?>

    <?php foreach ($videos as $i => $v):
      $isScheduled = $v['publish_at'] && strtotime($v['publish_at']) > time();
      $dur = videoDurationLabel($v['duration_seconds'] !== null ? (int) $v['duration_seconds'] : null);
      $pub = siteUrl('/' . $profile['slug'] . '/video/' . (int) $v['id']);
      $share = json_encode(['t' => $v['title'], 'u' => $pub], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
      <article class="stc stc-list">
        <span class="stc-thumb"><?php if ($v['thumb_path']): ?><img src="/<?= e($v['thumb_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="stc-ph"><i class="fa-solid fa-film"></i></span><?php endif; ?><i class="fa-solid fa-circle-play blx-play"></i></span>
        <div class="stc-body">
          <div class="stc-meta1">
            <span class="stc-type stc-blue">Video</span>
            <?php if (!(int) $v['is_public']): ?><span class="stc-status stc-s-grey"><i class="fa-solid fa-lock"></i> Solo io</span>
            <?php elseif ($isScheduled): ?><span class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> Programmato · <?= e(formatLocalDateTime($v['publish_at'], $profile)) ?></span>
            <?php else: ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Pubblicato</span><?php endif; ?>
            <?php if ((int) $v['is_public'] && !(int) ($v['in_feed'] ?? 1)): ?><span class="tlx-tag">Fuori dal Feed</span><?php endif; ?>
            <?= authorBadgeHtml($v['created_by'] ?? null) ?>
          </div>
          <h3 class="stc-title"><?= e($v['title']) ?></h3>
          <div class="stc-meta2"><?= $dur ? e($dur) . ' · ' : '' ?><?= number_format((int) $v['size_bytes'] / 1048576, 1, ',', '') ?> MB</div>
          <div class="stc-actions">
            <a class="stc-act primary" href="/<?= e($profile['slug']) ?>/video/<?= (int) $v['id'] ?>" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Pagina pubblica</span></a>
            <button type="button" class="stc-act" data-share='<?= e($share) ?>' title="Condividi"><i class="fa-solid fa-share-nodes"></i> <span>Condividi</span></button>
            <form method="post" class="vdx-inline"><?= csrfField() ?><input type="hidden" name="action" value="move_up"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="stc-act" type="submit" title="Sposta su" <?= $i === 0 ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-up"></i></button></form>
            <form method="post" class="vdx-inline"><?= csrfField() ?><input type="hidden" name="action" value="move_down"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="stc-act" type="submit" title="Sposta giù" <?= $i === count($videos) - 1 ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-down"></i></button></form>
            <?php if (videoHasFfmpeg()): ?>
              <form method="post" class="vdx-inline"><?= csrfField() ?><input type="hidden" name="action" value="regen_thumb"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="stc-act" type="submit" title="Rigenera l'anteprima dal video"><i class="fa-regular fa-image"></i> <span>Anteprima</span></button></form>
            <?php endif; ?>
            <form method="post" class="vdx-inline" data-confirm="Eliminare questo video? Il file verrà cancellato.">
              <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
              <button class="stc-act danger" type="submit" title="Elimina"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button>
            </form>
          </div>

          <details class="vdx-edit">
            <summary><i class="fa-solid fa-pen"></i> Modifica</summary>
            <form method="post" enctype="multipart/form-data" class="vdx-form">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="tz_offset_minutes" value="">
              <label>Titolo</label>
              <input type="text" name="title" value="<?= e($v['title']) ?>" maxlength="150" required>
              <label>Descrizione (opzionale)</label>
              <textarea name="description" rows="4"><?= e($v['description'] ?? '') ?></textarea>
              <label>Immagine di anteprima (opzionale, sostituisce quella attuale)</label>
              <input type="file" name="thumb" accept="image/*">
              <div class="vdx-row">
                <div class="tlx-seg" role="radiogroup">
                  <label><input type="radio" name="visibility" value="public" <?= (int) $v['is_public'] === 1 ? 'checked' : '' ?>><span><i class="fa-solid fa-earth-europe"></i> Pubblico</span></label>
                  <label><input type="radio" name="visibility" value="private" <?= (int) $v['is_public'] === 0 ? 'checked' : '' ?>><span><i class="fa-solid fa-lock"></i> Solo io</span></label>
                </div>
                <label class="tlx-switch"><input type="checkbox" name="in_feed" value="1" <?= (int) ($v['in_feed'] ?? 1) ? 'checked' : '' ?>><i></i><span>Mostra nel Feed</span></label>
              </div>
              <label>Data di pubblicazione (opzionale)</label>
              <input type="datetime-local" name="publish_at" value="<?= e(localDateTimeInputValue($v['publish_at'], $profile)) ?>">
              <button type="submit" class="tlx-submit">Salva modifiche</button>
            </form>
          </details>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
