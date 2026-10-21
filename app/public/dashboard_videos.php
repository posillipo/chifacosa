<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/videos.php';
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

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Carica i tuoi video (MP4, MOV o WebM, fino a <?= $maxMb ?> MB ciascuno) e dai a ciascuno un
      titolo e una descrizione. Compaiono nella sezione pubblica "Video" del tuo profilo, ognuno con
      la sua pagina condivisibile. Puoi selezionarne più d'uno insieme: vengono caricati uno alla volta.
    </p>
    <p style="color:var(--text-muted)">
      Pubblico/Solo io e la programmazione funzionano come nel resto del sito. Se hai attivato
      "Social Automatico", un video pubblicato subito viene messo in coda per Metricool con titolo,
      descrizione e file. L'anteprima è presa dal video stesso<?= videoHasFfmpeg() ? '' : ' (non disponibile su questo server: puoi caricarne una a mano da "Modifica")' ?>.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form class="card" id="vid-upload-form" onsubmit="return false;">
    <?= csrfField() ?>
    <label>Video (puoi sceglierne più di uno)</label>
    <input type="file" id="vid-files" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm,.m4v" multiple>
    <div id="vid-rows" style="margin-top:12px;"></div>

    <div id="vid-options" style="display:none;">
      <label>Privacy (per tutti i video di questo caricamento)</label>
      <div style="display:flex;gap:16px;margin-bottom:14px;">
        <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
          <input type="radio" name="visibility" value="public" checked style="width:auto;"> Pubblico
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
          <input type="radio" name="visibility" value="private" style="width:auto;"> Solo io
        </label>
      </div>
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
        <input type="checkbox" id="vid-in-feed" checked style="width:auto;"> Includi nel Feed
      </label>
      <p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Non riguarda la Timeline del sito: serve solo per le automazioni social che leggono il feed RSS del profilo.</p>
      <label>Programma la pubblicazione (opzionale)</label>
      <input type="datetime-local" id="vid-publish-at">
      <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicare subito.</p>
      <button type="button" class="btn" id="vid-start" style="margin-top:10px;">Carica i video</button>
      <p id="vid-summary" style="color:var(--text-muted);font-size:13px;margin:10px 0 0;"></p>
    </div>
  </form>

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
        <strong><?= e($v['title']) ?></strong>
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
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="visibility" value="public" <?= (int) $v['is_public'] === 1 ? 'checked' : '' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="visibility" value="private" <?= (int) $v['is_public'] === 0 ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>
            <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
              <input type="checkbox" name="in_feed" value="1" <?= (int) ($v['in_feed'] ?? 1) ? 'checked' : '' ?> style="width:auto;"> Includi nel Feed
            </label>
            <label>Programma la pubblicazione (opzionale)</label>
            <input type="datetime-local" name="publish_at" value="<?= e(localDateTimeInputValue($v['publish_at'], $profile)) ?>">
            <button type="submit" class="btn small" style="margin-top:10px;">Salva modifiche</button>
          </form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>

  <script>
  (function () {
    const MAX = <?= (int) VIDEO_MAX_BYTES ?>;
    const fileInput = document.getElementById('vid-files');
    const rowsBox = document.getElementById('vid-rows');
    const options = document.getElementById('vid-options');
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
      items.forEach(function (it, i) {
        const tooBig = it.file.size > MAX;
        if (tooBig) it.state = 'error';
        const div = document.createElement('div');
        div.className = 'card';
        div.style.cssText = 'background:var(--bg-alt,#f7f7f9);margin:0 0 10px;';
        div.innerHTML = '<strong>' + esc(it.file.name) + '</strong> <span style="color:var(--text-muted);font-size:12.5px;">· ' + mb(it.file.size) + '</span>'
          + (tooBig ? '<div style="color:#c0392b;font-size:13px;">Supera il limite di <?= $maxMb ?> MB: non verrà caricato.</div>' : '')
          + '<label>Titolo</label><input type="text" class="vid-title" maxlength="150" value="' + esc(baseTitle(it.file.name)) + '">'
          + '<label>Descrizione (opzionale)</label><textarea class="vid-desc" rows="3"></textarea>'
          + '<div class="vid-bar" style="display:none;height:8px;background:#ddd;border-radius:4px;overflow:hidden;margin-top:8px;"><div style="height:100%;width:0;background:var(--accent,#6C5CE7);"></div></div>'
          + '<div class="vid-status" style="font-size:12.5px;color:var(--text-muted);margin-top:4px;"></div>';
        rowsBox.appendChild(div);
        it.el = div;
      });
      options.style.display = items.length ? 'block' : 'none';
    });

    function uploadOne(it) {
      return new Promise(function (resolve) {
        const fd = new FormData();
        fd.set('csrf', csrf);
        fd.set('action', 'upload');
        fd.set('ajax', '1');
        fd.set('video', it.file);
        fd.set('title', it.el.querySelector('.vid-title').value);
        fd.set('description', it.el.querySelector('.vid-desc').value);
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
      if (!todo.length) return;
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
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
