<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'albums';
$pageTitle = 'Album';
$error = null;

// Fino a 50 foto per album — molte di più delle 10 già previste per i post Timeline/Viaggi, dato
// che qui è proprio lo scopo del modulo (un album fotografico, non un singolo aggiornamento).
const ALBUM_MAX_PHOTOS = 50;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = $action === 'edit' ? (int) ($_POST['id'] ?? 0) : 0;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $isPublic = $visibility === 'public' ? 1 : 0;
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
        // Interpretato nel fuso orario reale di chi sta scrivendo in questo momento (offset del
        // browser), con il fuso del profilo solo come ripiego — vedi parseLocalDateTime().
        // Niente più azzeramento se la data è già passata: equivale a NULL per la visibilità
        // pubblica, azzerarla qui cancellava la programmazione originale ad ogni salvataggio di
        // un elemento già pubblicato (stesso bug corretto in dashboard_timeline_edit.php).
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());

        if ($title === '') {
            $error = 'Il titolo è obbligatorio.';
        } else {
            // In modifica le nuove foto si aggiungono a quelle esistenti (vedi sotto).
            $uploadedPhotos = handleMultiCoverUpload($profile['slug'], 'images', ALBUM_MAX_PHOTOS);
            $newCoverPath = $uploadedPhotos[0] ?? null;
            $extraPhotos = array_slice($uploadedPhotos, 1);

            if ($action === 'add') {
                if (!$newCoverPath) {
                    $error = 'Carica almeno una foto.';
                } else {
                    $stmt = getDB()->prepare('INSERT INTO photo_albums (user_id, title, description, cover_path, is_public, in_feed, publish_at, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM photo_albums WHERE user_id=?) t))');
                    $stmt->execute([$profile['id'], $title, $description ?: null, $newCoverPath, $isPublic, $inFeed, $publishAt, $profile['id']]);
                    $newId = (int) getDB()->lastInsertId();
                    setContentAuthor('photo_albums', $newId, dashboardAuthorSignature($user));
                    if ($extraPhotos) {
                        $insPhoto = getDB()->prepare('INSERT INTO photo_album_photos (album_id, image_path, sort_order) VALUES (?,?,?)');
                        foreach ($extraPhotos as $i => $p) {
                            $insPhoto->execute([$newId, $p, $i]);
                        }
                    }

                    if ($visibility === 'public' && !$publishAt) {
                        $albumUrl = siteUrl('/' . $profile['slug'] . '/album/' . $newId);
                        notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'album_foto', $title, $albumUrl);
                        require_once __DIR__ . '/../../src/metricool_queue.php';
                        socialQueueEnqueueIfEnabled((int) $profile['id'], 'album', [
                            'content_id' => $newId,
                            'title'      => $title,
                            'body'       => $description,
                            'image_url'  => $newCoverPath ? siteUrl($newCoverPath) : null,
                            'public_url' => $albumUrl,
                        ]);
                    } elseif ($visibility === 'public' && $publishAt) {
                        // Programmato: si accoda subito con la data di pubblicazione (prima restava fuori dalla coda).
                        $queueUrl = siteUrl('/' . $profile['slug'] . '/album/' . $newId);
                        require_once __DIR__ . '/../../src/metricool_queue.php';
                        socialQueueEnqueueIfEnabled((int) $profile['id'], 'album', [
                            'content_id' => $newId,
                            'title'      => $title,
                            'body'       => $description,
                            'image_url'  => $newCoverPath ? siteUrl($newCoverPath) : null,
                            'public_url' => $queueUrl,
                        ], $publishAt);
                    }
                }
            } else {
                // In modifica le nuove foto si AGGIUNGONO a quelle già presenti. Se l'album è senza
                // copertina (eliminata), la prima nuova foto diventa la copertina.
                if ($newCoverPath) {
                    $stmt = getDB()->prepare('SELECT cover_path FROM photo_albums WHERE id=? AND user_id=?');
                    $stmt->execute([$id, $profile['id']]);
                    $cur = $stmt->fetch();
                    if ($cur) {
                        $hasCover = !empty($cur['cover_path']);
                        $existingExtras = count(getAlbumPhotos($id));
                        $room = max(0, ALBUM_MAX_PHOTOS - ($hasCover ? 1 : 0) - $existingExtras);
                        $toAdd = $uploadedPhotos;
                        if (!$hasCover) {
                            $newCover = array_shift($toAdd);
                            getDB()->prepare('UPDATE photo_albums SET cover_path=? WHERE id=? AND user_id=?')->execute([$newCover, $id, $profile['id']]);
                            $room = max(0, $room - 1);
                        }
                        foreach (array_slice($toAdd, $room) as $excess) {
                            deleteCoverFile($excess);
                        }
                        $toAdd = array_slice($toAdd, 0, $room);
                        $maxSort = (int) getDB()->query('SELECT COALESCE(MAX(sort_order), -1) FROM photo_album_photos WHERE album_id=' . (int) $id)->fetchColumn();
                        $insPhoto = getDB()->prepare('INSERT INTO photo_album_photos (album_id, image_path, sort_order) VALUES (?,?,?)');
                        foreach ($toAdd as $i => $p) {
                            $insPhoto->execute([$id, $p, $maxSort + 1 + $i]);
                        }
                    }
                }
                $stmt = getDB()->prepare('UPDATE photo_albums SET title=?, description=?, is_public=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $description ?: null, $isPublic, $inFeed, $publishAt, $id, $profile['id']]);
            }
        }
    } elseif ($action === 'delete_photo') {
        $id = (int) ($_POST['id'] ?? 0);
        $photoId = (int) ($_POST['photo_id'] ?? -1);
        $result = deleteSingleGalleryPhoto('photo_albums', 'cover_path', 'photo_album_photos', 'album_id', $id, (int) $profile['id'], $photoId);
        if (!$result['ok']) {
            $error = $result['error'];
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM photo_albums WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
            deleteFeedShareImage($row['cover_path']);
            foreach (getAlbumPhotos($id) as $extraPath) {
                deleteCoverFile($extraPath);
            }
        }
        // photo_album_photos ha ON DELETE CASCADE: le righe spariscono da sole, qui sopra
        // servivano solo per cancellare i FILE dal disco prima che spariscano i riferimenti.
        $stmt = getDB()->prepare('DELETE FROM photo_albums WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    }
    if (!$error) {
        header('Location: /dashboard_albums.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM photo_albums WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$albums = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
$alPub = count(array_filter($albums, fn ($a) => (int) $a['is_public'] === 1));
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($albums) ?></b><span>Album</span></div>
      <div class="tlx-kpi"><b><?= $alPub ?></b><span>Pubblici</span></div>
      <div class="tlx-kpi"><b><?= count($albums) - $alPub ?></b><span>Solo io</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>/foto" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>Pagina pubblica</span></a>
    </div>

    <button type="button" class="cmp-trigger" data-dlg="al-dlg"><span class="tlx-av"><i class="fa-regular fa-images"></i></span><span class="cmp-trigger-t">Crea un nuovo album…</span><span class="cmp-trigger-i"><i class="fa-regular fa-image"></i></span></button>

    <div class="tlx-listhead"><h2>I tuoi album <small>(<?= count($albums) ?>)</small></h2>
      <div class="tlx-filters" data-filters="#al-list"><button type="button" class="on" data-f="all">Tutti</button><button type="button" data-f="public">Pubblici</button><button type="button" data-f="scheduled">Programmati</button><button type="button" data-f="private">Solo io</button></div>
    </div>
    <?php if (!$albums): ?><div class="tlx-empty"><i class="fa-regular fa-images"></i><b>Nessun album ancora</b><span>Crea il primo con le tue foto (fino a <?= ALBUM_MAX_PHOTOS ?>): comparirà sulla tua pagina e nel Feed.</span></div><?php endif; ?>
    <div class="tlx-empty" data-filter-empty hidden><i class="fa-regular fa-circle-check"></i><b>Niente da mostrare con questo filtro</b></div>

    <div id="al-list">
    <?php foreach ($albums as $al):
      $isScheduled = $al['publish_at'] && strtotime($al['publish_at']) > time();
      $extra = getDB()->prepare('SELECT id, image_path FROM photo_album_photos WHERE album_id=? ORDER BY sort_order ASC, id ASC');
      $extra->execute([(int) $al['id']]); $extra = $extra->fetchAll();
      $photoCount = ($al['cover_path'] ? 1 : 0) + count($extra);
      $state = !(int) $al['is_public'] ? 'private' : ($isScheduled ? 'scheduled' : 'public');
      $pub = siteUrl('/' . $profile['slug'] . '/album/' . (int) $al['id']);
      $share = json_encode(['t' => $al['title'], 'u' => $pub], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
      $fill = json_encode(['id' => (int) $al['id'], 'title' => $al['title'], 'description' => $al['description'] ?? '', 'visibility' => (int) $al['is_public'] === 1 ? 'public' : 'private',
        'in_feed' => (int) ($al['in_feed'] ?? 1), 'publish_at' => localDateTimeInputValue($al['publish_at'], $profile)], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
      <article class="stc stc-list kit-item" data-state="<?= e($state) ?>">
        <span class="stc-thumb"><?php if ($al['cover_path']): ?><img src="/<?= e($al['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="stc-ph"><i class="fa-regular fa-images"></i></span><?php endif; ?></span>
        <div class="stc-body">
          <div class="stc-meta1">
            <span class="stc-type stc-violet">Album</span>
            <?php if (!(int) $al['is_public']): ?><span class="stc-status stc-s-grey"><i class="fa-solid fa-lock"></i> Solo io</span>
            <?php elseif ($isScheduled): ?><span class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> Programmato · <?= e(formatLocalDateTime($al['publish_at'], $profile)) ?></span>
            <?php else: ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Pubblicato</span><?php endif; ?>
            <?php if ((int) $al['is_public'] && !(int) ($al['in_feed'] ?? 1)): ?><span class="tlx-tag">Fuori dal Feed</span><?php endif; ?>
            <?= authorBadgeHtml($al['created_by'] ?? null) ?>
          </div>
          <h3 class="stc-title"><?= e($al['title']) ?></h3>
          <div class="stc-meta2"><i class="fa-regular fa-images"></i> <?= $photoCount ?> foto</div>
          <div class="stc-actions">
            <button type="button" class="stc-act primary" data-dlg="al-dlg" data-fill='<?= e($fill) ?>'><i class="fa-solid fa-pen"></i> <span>Modifica</span></button>
            <a class="stc-act" href="/<?= e($profile['slug']) ?>/album/<?= (int) $al['id'] ?>" target="_blank" title="Pagina pubblica"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Pagina pubblica</span></a>
            <button type="button" class="stc-act" data-share='<?= e($share) ?>' title="Condividi"><i class="fa-solid fa-share-nodes"></i> <span>Condividi</span></button>
            <form method="post" class="ibx-inline" data-confirm="Eliminare questo album? Tutte le foto verranno cancellate."><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $al['id'] ?>"><button class="stc-act danger" type="submit" title="Elimina"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button></form>
          </div>
          <?php if ($photoCount > 0): ?>
          <details class="vdx-edit"><summary><i class="fa-regular fa-images"></i> Gestisci le foto (<?= $photoCount ?>)</summary>
            <div class="kit-gallery">
              <?php if ($al['cover_path']): ?>
                <div class="tlx-ph"><img src="/<?= e($al['cover_path']) ?>" alt="" data-lightbox="/<?= e($al['cover_path']) ?>"><span>Copertina</span>
                  <form method="post" data-confirm="Eliminare la copertina? La prossima foto la sostituirà."><?= csrfField() ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="id" value="<?= (int) $al['id'] ?>"><input type="hidden" name="photo_id" value="0"><button type="submit" title="Elimina questa foto" aria-label="Elimina la copertina">&times;</button></form></div>
              <?php endif; ?>
              <?php foreach ($extra as $ph): ?>
                <div class="tlx-ph"><img src="/<?= e($ph['image_path']) ?>" alt="" data-lightbox="/<?= e($ph['image_path']) ?>">
                  <form method="post" data-confirm="Eliminare questa foto?"><?= csrfField() ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="id" value="<?= (int) $al['id'] ?>"><input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>"><button type="submit" title="Elimina questa foto" aria-label="Elimina la foto">&times;</button></form></div>
              <?php endforeach; ?>
            </div>
            <p class="tlx-hint">Tocca una foto per vederla a schermo intero, la × per toglierla senza toccare le altre.</p>
          </details>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </div>

  <?php studioDlgOpen('al-dlg', ['new_title' => 'Nuovo album', 'edit_title' => 'Modifica album', 'action_new' => 'add', 'action_edit' => 'edit']); ?>
    <label for="al-title">Titolo dell'album</label>
    <input type="text" name="title" id="al-title" required placeholder="es. Il nostro tour 2026">
    <label for="al-desc">Descrizione <small>(opzionale)</small></label>
    <textarea name="description" id="al-desc" rows="4" data-ai-target placeholder="Racconta l'album..."></textarea>
    <?php studioAiBox('es. foto del tour estivo, tono nostalgico'); ?>
    <label>Foto <small>(fino a <?= ALBUM_MAX_PHOTOS ?>; la prima è la copertina. In modifica le nuove foto si aggiungono a quelle esistenti)</small></label>
    <input type="file" name="images[]" accept="image/*" multiple data-req-new data-preview="#al-prev">
    <div id="al-prev" class="kit-gallery" data-preview-box hidden></div>
    <label>Chi lo vede</label>
    <?php studioSeg('visibility', ['public' => '<i class="fa-solid fa-earth-europe"></i> Pubblico', 'private' => '<i class="fa-solid fa-lock"></i> Solo io'], 'public'); ?>
    <?php studioSwitch('in_feed', 'Mostra nel Feed', true); ?>
    <label for="al-when">Pubblica sui social dal <small>(opzionale, vuoto = subito)</small></label>
    <input type="datetime-local" name="publish_at" id="al-when">
  <?php studioDlgClose('Salva album'); ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
