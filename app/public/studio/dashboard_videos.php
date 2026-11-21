<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
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
?>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['regen'])): ?>
    <div class="alert <?= (int) $_GET['regen'] > 0 ? 'success' : 'error' ?>"><?= (int) $_GET['regen'] > 0 ? 'Anteprime generate: ' . (int) $_GET['regen'] . '.' : 'Nessuna anteprima generata (controlla che i file video siano leggibili).' ?></div>
  <?php endif; ?>
  <style>
  /* Pagina a due colonne: modulo di inserimento a sinistra, elenco di quanto già inserito a destra.
     Sotto i 760px le colonne si impilano (prima il modulo, poi l'elenco). */
  .container { max-width: 1240px; }
  .dash-two-col { display: block; }
  .dash-two-col > div { min-width: 0; }
  @media (min-width: 760px) {
    .dash-two-col { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; align-items: start; }
  }
  </style>

  <div class="dash-two-col">
  <div class="dash-two-col-main">
  <?php
    $missingThumbs = count(array_filter($videos, fn($v) => empty($v['thumb_path'])));
  ?>
  <?php if (!videoHasFfmpeg()): ?>
    <div class="alert error">Le anteprime automatiche non sono disponibili: <strong>ffmpeg non è installato</strong> in questo container (serve ricostruire l'immagine dell'app). Nel frattempo i video si riproducono comunque, ma senza immagine di copertina.</div>
  <?php elseif ($missingThumbs > 0): ?>
    <form method="post" class="card" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="regen_missing">
      <span><?= $missingThumbs ?> video senza anteprima.</span>
      <button class="btn small" type="submit">Genera le anteprime mancanti</button>
    </form>
  <?php endif; ?>

  <form class="card" id="vid-upload-form" onsubmit="return false;">
    <?= csrfField() ?>
    <div id="vid-single-fields">
      <label>Titolo del video</label>
      <input type="text" id="vid-title-main" maxlength="150" placeholder="es. Live al Teatro, 12 giugno">
      <label>Descrizione (opzionale)</label>
      <textarea id="vid-desc-main" rows="4" placeholder="Racconta il video..."></textarea>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 14px;">
        <button type="button" class="btn small secondary" id="vid-ai-toggle">✨ Genera con AI</button>
      </div>
      <div id="vid-ai-panel" class="card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-8px 0 14px;">
        <label>Qualche parola chiave o istruzione per l'AI</label>
        <textarea id="vid-ai-keywords" rows="2" placeholder="es. concerto estivo 2026, tappa di Milano"></textarea>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn small" id="vid-ai-generate">Genera testo</button>
          <button type="button" class="btn small secondary" id="vid-ai-cancel">Annulla</button>
        </div>
        <p id="vid-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
      </div>
    </div>

    <label>Video (puoi sceglierne più di uno, fino a <?= $maxMb ?> MB ciascuno)</label>
    <input type="file" id="vid-files" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm,.m4v" multiple>
    <p id="vid-multi-note" style="display:none;color:var(--text-muted);font-size:12.5px;margin-top:6px;">Hai scelto più video: titolo e descrizione si scrivono per ciascuno qui sotto.</p>
    <div id="vid-rows" style="margin-top:12px;"></div>

    <div id="vid-options">
      <label>Privacy (per tutti i video di questo caricamento)</label>
      <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
        <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
          <input type="radio" name="visibility" value="public" checked style="width:auto;"> Pubblico
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
          <input type="radio" name="visibility" value="private" style="width:auto;"> Solo io
        </label>
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="checkbox" id="vid-in-feed" checked style="width:auto;"> Includi nel Feed
      </label></div>
      <label>Pubblica sui social (opzionale)</label>
      <input type="datetime-local" id="vid-publish-at">
      <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicare subito.</p>
      <button type="button" class="btn" id="vid-start" style="margin-top:10px;">Carica il video</button>
      <p id="vid-summary" style="color:var(--text-muted);font-size:13px;margin:10px 0 0;"></p>
    </div>
  </form>

  </div>

  <div class="dash-two-col-side">
  <div class="section-title">I tuoi video (<?= count($videos) ?>)</div>
  <?php if (!$videos): ?><div class="card">Nessun video ancora.</div><?php endif; ?>
  <?php foreach ($videos as $i => $v): ?>
    <?php
      $isScheduled = $v['publish_at'] && strtotime($v['publish_at']) > time();
      $dur = videoDurationLabel($v['duration_seconds'] !== null ? (int) $v['duration_seconds'] : null);
    ?>
    <div class="event-item" style="display:flex;gap:14px;align-items:flex-start;">
      <?php if ($v['thumb_path']): ?>
        <img src="/<?= e($v['thumb_path']) ?>" style="width:112px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;background:#000;">
      <?php else: ?>
        <div style="width:112px;height:64px;border-radius:8px;background:#222;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fa-solid fa-film"></i></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <strong><?= e($v['title']) ?></strong> <?= authorBadgeHtml($v['created_by'] ?? null) ?>
        <span style="color:var(--text-muted);font-size:12.5px;"> · <?= $dur ? e($dur) . ' · ' : '' ?><?= number_format((int) $v['size_bytes'] / 1048576, 1, ',', '') ?> MB</span>
        <?php if (!(int) $v['is_public']): ?>
          <div style="color:var(--text-muted);font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-lock"></i> Solo io</div>
        <?php elseif ($isScheduled): ?>
          <div style="color:#f0ad4e;font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-clock"></i> Programmato per il <?= e(formatLocalDateTime($v['publish_at'], $profile)) ?></div>
        <?php else: ?>
          <div style="color:#2e7d32;font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-circle-check"></i> Pubblico<?= (int) ($v['in_feed'] ?? 1) ? '' : ' · non nel Feed' ?></div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center;">
          <a href="/<?= e($profile['slug']) ?>/video/<?= (int) $v['id'] ?>" target="_blank" class="btn small secondary">Vedi pagina pubblica</a>
          <form method="post" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="move_up"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="btn small secondary" type="submit" title="Sposta su" <?= $i === 0 ? 'disabled' : '' ?>>↑</button></form>
          <form method="post" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="move_down"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="btn small secondary" type="submit" title="Sposta giù" <?= $i === count($videos) - 1 ? 'disabled' : '' ?>>↓</button></form>
          <?php if (videoHasFfmpeg()): ?>
            <form method="post" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="regen_thumb"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="btn small secondary" type="submit" title="Rigenera l'anteprima dal video">🖼 Anteprima</button></form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Eliminare questo video? Il file verrà cancellato.');" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        </div>

        <details style="margin-top:8px;">
          <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica</summary>
          <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
            <input type="hidden" name="tz_offset_minutes" value="">
            <label>Titolo</label>
            <input type="text" name="title" value="<?= e($v['title']) ?>" maxlength="150" required>
            <label>Descrizione (opzionale)</label>
            <textarea name="description" rows="4"><?= e($v['description'] ?? '') ?></textarea>
            <label>Immagine di anteprima (opzionale — sostituisce quella attuale)</label>
            <input type="file" name="thumb" accept="image/*">
            <label>Privacy</label>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="visibility" value="public" <?= (int) $v['is_public'] === 1 ? 'checked' : '' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="visibility" value="private" <?= (int) $v['is_public'] === 0 ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
              <input type="checkbox" name="in_feed" value="1" <?= (int) ($v['in_feed'] ?? 1) ? 'checked' : '' ?> style="width:auto;"> Includi nel Feed
            </label></div>
            <label>Pubblica sui social (opzionale)</label>
            <input type="datetime-local" name="publish_at" value="<?= e(localDateTimeInputValue($v['publish_at'], $profile)) ?>">
            <button type="submit" class="btn small" style="margin-top:10px;">Salva modifiche</button>
          </form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  </div>

  <script>
  (function () {
    const MAX = <?= (int) VIDEO_MAX_BYTES ?>;
    const fileInput = document.getElementById('vid-files');
    const rowsBox = document.getElementById('vid-rows');
    const singleFields = document.getElementById('vid-single-fields');
    const titleMain = document.getElementById('vid-title-main');
    const descMain = document.getElementById('vid-desc-main');
    const multiNote = document.getElementById('vid-multi-note');
    const startBtn = document.getElementById('vid-start');
    const summary = document.getElementById('vid-summary');
    const csrf = document.querySelector('#vid-upload-form input[name="csrf"]').value;
    let items = [];

    function esc(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function mb(n) { return (n / 1048576).toFixed(1).replace('.', ',') + ' MB'; }
    function baseTitle(name) { return name.replace(/\.[^.]+$/, '').replace(/[_\-]+/g, ' ').trim(); }

    fileInput.addEventListener('change', function () {
      items = Array.from(fileInput.files).map(function (f) { return { file: f, state: 'ready' }; });
      rowsBox.innerHTML = '';
      const multi = items.length > 1;
      // Un solo video: titolo e descrizione sono nei campi in alto. Più video: ognuno ha i suoi, qui sotto.
      singleFields.style.display = multi ? 'none' : 'block';
      multiNote.style.display = multi ? 'block' : 'none';
      startBtn.textContent = multi ? 'Carica i video' : 'Carica il video';
      if (items.length === 1) titleMain.placeholder = baseTitle(items[0].file.name) || titleMain.placeholder;
      items.forEach(function (it, i) {
        const tooBig = it.file.size > MAX;
        if (tooBig) it.state = 'error';
        const div = document.createElement('div');
        div.className = 'card';
        div.style.cssText = 'background:var(--bg-alt,#f7f7f9);margin:0 0 10px;';
        div.innerHTML = '<strong>' + esc(it.file.name) + '</strong> <span style="color:var(--text-muted);font-size:12.5px;">· ' + mb(it.file.size) + '</span>'
          + (tooBig ? '<div style="color:#c0392b;font-size:13px;">Supera il limite di <?= $maxMb ?> MB: non verrà caricato.</div>' : '')
          + (multi ? '<label>Titolo</label><input type="text" class="vid-title" maxlength="150" value="' + esc(baseTitle(it.file.name)) + '">'
          + '<label>Descrizione (opzionale)</label><textarea class="vid-desc" rows="3"></textarea>' : '')
          + '<div class="vid-bar" style="display:none;height:8px;background:#ddd;border-radius:4px;overflow:hidden;margin-top:8px;"><div style="height:100%;width:0;background:var(--accent,#6C5CE7);"></div></div>'
          + '<div class="vid-status" style="font-size:12.5px;color:var(--text-muted);margin-top:4px;"></div>';
        rowsBox.appendChild(div);
        it.el = div;
      });
    });

    function uploadOne(it) {
      return new Promise(function (resolve) {
        const fd = new FormData();
        fd.set('csrf', csrf);
        fd.set('action', 'upload');
        fd.set('ajax', '1');
        fd.set('video', it.file);
        const rowTitle = it.el.querySelector('.vid-title');
        const rowDesc = it.el.querySelector('.vid-desc');
        fd.set('title', rowTitle ? rowTitle.value : titleMain.value);
        fd.set('description', rowDesc ? rowDesc.value : descMain.value);
        fd.set('visibility', document.querySelector('#vid-upload-form input[name="visibility"]:checked').value);
        if (document.getElementById('vid-in-feed').checked) fd.set('in_feed', '1');
        fd.set('publish_at', document.getElementById('vid-publish-at').value);
        fd.set('tz_offset_minutes', new Date().getTimezoneOffset());
        const bar = it.el.querySelector('.vid-bar');
        const fill = bar.firstElementChild;
        const status = it.el.querySelector('.vid-status');
        bar.style.display = 'block';
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/dashboard_videos.php');
        xhr.upload.onprogress = function (e) {
          if (e.lengthComputable) {
            fill.style.width = Math.round(e.loaded / e.total * 100) + '%';
            status.textContent = 'Caricamento… ' + Math.round(e.loaded / e.total * 100) + '%';
          }
        };
        xhr.onload = function () {
          let data = {};
          try { data = JSON.parse(xhr.responseText); } catch (e) {}
          if (data.ok) {
            fill.style.width = '100%';
            status.textContent = '✅ Caricato';
            it.state = 'done';
          } else {
            status.textContent = '❌ ' + (data.error || ('Errore (' + xhr.status + ')' + (xhr.status === 413 ? ': file troppo grande per il server' : '')));
            status.style.color = '#c0392b';
            it.state = 'error';
          }
          resolve();
        };
        xhr.onerror = function () {
          status.textContent = '❌ Errore di connessione';
          status.style.color = '#c0392b';
          it.state = 'error';
          resolve();
        };
        xhr.send(fd);
      });
    }

    startBtn.addEventListener('click', async function () {
      const todo = items.filter(function (it) { return it.state === 'ready'; });
      if (!todo.length) { summary.textContent = items.length ? 'Nessun video valido da caricare.' : 'Scegli prima il video da caricare.'; return; }
      startBtn.disabled = true;
      fileInput.disabled = true;
      for (let i = 0; i < todo.length; i++) {
        summary.textContent = 'Video ' + (i + 1) + ' di ' + todo.length + '…';
        await uploadOne(todo[i]);
      }
      const ok = items.filter(function (it) { return it.state === 'done'; }).length;
      const ko = items.filter(function (it) { return it.state === 'error'; }).length;
      summary.textContent = ok + ' caricati' + (ko ? ', ' + ko + ' con errore (vedi sopra)' : '') + '.';
      if (ok && !ko) setTimeout(function () { location.reload(); }, 1200);
      else { startBtn.textContent = 'Ricarica la pagina'; startBtn.disabled = false; startBtn.onclick = function () { location.reload(); }; }
    });
  })();

  // "Genera con AI" per la descrizione (stesso assistente di Album e Timeline).
  (function () {
    const toggleBtn = document.getElementById('vid-ai-toggle');
    const panel = document.getElementById('vid-ai-panel');
    const cancelBtn = document.getElementById('vid-ai-cancel');
    const generateBtn = document.getElementById('vid-ai-generate');
    const keywordsInput = document.getElementById('vid-ai-keywords');
    const statusEl = document.getElementById('vid-ai-status');
    const target = document.getElementById('vid-desc-main');
    const csrf = document.querySelector('#vid-upload-form input[name="csrf"]').value;
    toggleBtn.addEventListener('click', function () {
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
      if (panel.style.display === 'block') keywordsInput.focus();
    });
    cancelBtn.addEventListener('click', function () { panel.style.display = 'none'; statusEl.textContent = ''; });
    generateBtn.addEventListener('click', function () {
      const keywords = keywordsInput.value.trim();
      if (!keywords) { statusEl.textContent = 'Scrivi almeno qualche parola chiave.'; return; }
      generateBtn.disabled = true;
      statusEl.textContent = 'Generazione in corso...';
      const body = new URLSearchParams();
      body.set('csrf', csrf);
      body.set('keywords', keywords);
      fetch('/dashboard_ai_caption.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          generateBtn.disabled = false;
          if (data.ok) { target.value = data.text; statusEl.textContent = 'Fatto! Puoi modificare il testo prima di caricare.'; }
          else { statusEl.textContent = data.error || 'Qualcosa è andato storto.'; }
        })
        .catch(function () { generateBtn.disabled = false; statusEl.textContent = 'Errore di connessione. Riprova.'; });
    });
  })();
  </script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
