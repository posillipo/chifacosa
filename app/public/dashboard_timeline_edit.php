<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'post';
$pageTitle = 'Modifica aggiornamento';
$error = null;

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = getDB()->prepare('SELECT * FROM timeline_posts WHERE id=? AND user_id=?');
$stmt->execute([$id, $profile['id']]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    exit('Aggiornamento non trovato.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        deleteCoverFile($post['image_path']);
        deleteFeedShareImage($post['image_path']);
        deleteCoverFile($post['image_thumb_path']);
        foreach (getTimelinePostPhotos($id) as $extraPath) {
            deleteCoverFile($extraPath);
        }
        // timeline_post_photos ha ON DELETE CASCADE: le righe spariscono da sole, qui sopra
        // servivano solo per cancellare i FILE dal disco prima che spariscano i riferimenti.
        getDB()->prepare('DELETE FROM timeline_posts WHERE id=? AND user_id=?')->execute([$id, $profile['id']]);
        logAdminAction((int) $profile['id'], (int) $user['id'], 'Aggiornamento eliminato dalla Timeline');
        header('Location: /dashboard_post.php');
        exit;
    } elseif ($action === 'delete_photo') {
        $photoId = (int) ($_POST['photo_id'] ?? -1);
        $result = deleteSingleGalleryPhoto('timeline_posts', 'image_path', 'timeline_post_photos', 'post_id', $id, (int) $profile['id'], $photoId, 'image_thumb_path');
        if (!$result['ok']) {
            $error = $result['error'];
        }
        if (!$error) {
            header('Location: /dashboard_timeline_edit.php?id=' . $id);
            exit;
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $testo = trim($_POST['testo'] ?? '');
        $hashtags = trim($_POST['hashtags'] ?? '');
        $callToAction = trim($_POST['call_to_action'] ?? '');
        // A differenza del "Link personalizzato per il feed" (impostazione del profilo, con
        // soglia temporale — vedi dashboard_post.php), questo è fisso e vale solo per QUESTO
        // post, per sempre — vedi emitPostRedirectLink() in functions.php.
        $redirectLink = trim($_POST['redirect_link'] ?? '');
        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        if ($publishAt && strtotime($publishAt) <= time()) {
            $publishAt = null;
        }

        if ($redirectLink !== '' && !filter_var($redirectLink, FILTER_VALIDATE_URL)) {
            $error = 'Il link di reindirizzamento per questo post non è un URL valido.';
        } elseif ($title === '' && $testo === '') {
            $error = 'Scrivi almeno un titolo o un testo.';
        } else {
            $stmt = getDB()->prepare('UPDATE timeline_posts SET title=?, testo=?, hashtags=?, call_to_action=?, redirect_link=?, visibility=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$title !== '' ? $title : null, $testo !== '' ? $testo : null, $hashtags !== '' ? $hashtags : null, $callToAction !== '' ? $callToAction : null, $redirectLink !== '' ? $redirectLink : null, $visibility, $inFeed, $publishAt, $id, $profile['id']]);
            logAdminAction((int) $profile['id'], (int) $user['id'], 'Aggiornamento Timeline modificato');
            header('Location: /dashboard_post.php');
            exit;
        }

        // Ridisegna il form con quanto appena scritto invece dei vecchi valori del database.
        $post = array_merge($post, ['title' => $title, 'testo' => $testo, 'hashtags' => $hashtags, 'call_to_action' => $callToAction, 'redirect_link' => $redirectLink, 'visibility' => $visibility, 'in_feed' => $inFeed, 'publish_at' => $publishAt]);
    }
}

$extraPhotosStmt = getDB()->prepare('SELECT id, image_path FROM timeline_post_photos WHERE post_id=? ORDER BY sort_order ASC, id ASC');
$extraPhotosStmt->execute([$id]);
$extraPhotos = $extraPhotosStmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <p><a href="/dashboard_post.php"><i class="fa-solid fa-arrow-left"></i> Torna alla Timeline</a></p>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="section-title">Modifica aggiornamento</div>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
    <input type="hidden" name="tz_offset_minutes" value="">

    <label>Titolo (opzionale)</label>
    <input type="text" name="title" value="<?= e($post['title'] ?? '') ?>">

    <label>Testo</label>
    <textarea name="testo" id="tl-edit-testo" rows="6"><?= e($post['testo'] ?? '') ?></textarea>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 14px;">
      <button type="button" class="btn small secondary" id="tl-ai-toggle">✨ Genera con AI</button>
    </div>
    <div id="tl-ai-panel" class="card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-8px 0 14px;">
      <label>Qualche parola chiave o istruzione per l'AI</label>
      <textarea id="tl-ai-keywords" rows="2" placeholder="es. annuncio nuovo concerto sabato 14 a Milano"></textarea>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn small" id="tl-ai-generate">Genera testo</button>
        <button type="button" class="btn small secondary" id="tl-ai-cancel">Annulla</button>
      </div>
      <p id="tl-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
    </div>

    <label>Hashtag (opzionale)</label>
    <input type="text" name="hashtags" value="<?= e($post['hashtags'] ?? '') ?>">

    <label>Call to action (opzionale)</label>
    <input type="text" name="call_to_action" value="<?= e($post['call_to_action'] ?? '') ?>">

    <label>Link di reindirizzamento per questo post (opzionale)</label>
    <input type="url" name="redirect_link" value="<?= e($post['redirect_link'] ?? '') ?>" placeholder="https://...">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">
      Diverso dal "Link personalizzato per il feed" (impostazione del profilo): questo vale solo per
      <strong>questo post</strong>, per sempre — chi apre questa pagina viene reindirizzato subito lì,
      indipendentemente da altri post o dalle impostazioni del profilo. Lascia vuoto per usare la pagina normale.
    </p>

    <label>Privacy</label>
    <div style="display:flex;gap:16px;margin-bottom:14px;">
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="visibility" value="public" <?= ($post['visibility'] ?? 'public') === 'private' ? '' : 'checked' ?> style="width:auto;"> Pubblico
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="visibility" value="private" <?= ($post['visibility'] ?? 'public') === 'private' ? 'checked' : '' ?> style="width:auto;"> Solo io
      </label>
    </div>

    <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
      <input type="checkbox" name="in_feed" value="1" <?= ($post['in_feed'] ?? 1) ? 'checked' : '' ?> style="width:auto;"> Includi nel Feed
    </label>
    <p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Non riguarda la Timeline del sito (quella segue solo Pubblico/Solo io): serve solo per le automazioni social (es. Metricool) che leggono il feed RSS del profilo.</p>

    <label>Programma la pubblicazione (opzionale)</label>
    <input type="datetime-local" name="publish_at" value="<?= e(localDateTimeInputValue($post['publish_at'], $profile)) ?>">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>

    <button type="submit" class="btn">Salva modifiche</button>
    <a href="/dashboard_post.php" class="btn secondary" style="margin-left:8px;">Annulla</a>
  </form>

  <?php if ($post['image_path']): ?>
  <div class="card">
    <div class="section-title" style="margin-top:0;">Foto</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <div style="position:relative;width:84px;">
        <img src="/<?= e($post['image_path']) ?>" class="tl-photo-thumb" data-src="/<?= e($post['image_path']) ?>" style="width:84px;height:84px;border-radius:8px;object-fit:cover;cursor:pointer;">
        <span style="position:absolute;top:2px;left:2px;background:rgba(0,0,0,0.6);color:#fff;font-size:10px;padding:1px 5px;border-radius:4px;">Copertina</span>
        <form method="post" onsubmit="return confirm('Eliminare la copertina? La prossima foto la sostituirà.');" style="display:contents;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_photo">
          <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
          <input type="hidden" name="photo_id" value="0">
          <button type="submit" title="Elimina questa foto" style="position:absolute;top:2px;right:2px;width:20px;height:20px;border-radius:50%;border:none;background:rgba(220,53,69,0.9);color:#fff;font-size:12px;line-height:1;cursor:pointer;">×</button>
        </form>
      </div>
      <?php foreach ($extraPhotos as $ph): ?>
        <div style="position:relative;width:84px;">
          <img src="/<?= e($ph['image_path']) ?>" class="tl-photo-thumb" data-src="/<?= e($ph['image_path']) ?>" style="width:84px;height:84px;border-radius:8px;object-fit:cover;cursor:pointer;">
          <form method="post" onsubmit="return confirm('Eliminare questa foto?');" style="display:contents;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_photo">
            <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
            <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
            <button type="submit" title="Elimina questa foto" style="position:absolute;top:2px;right:2px;width:20px;height:20px;border-radius:50%;border:none;background:rgba(220,53,69,0.9);color:#fff;font-size:12px;line-height:1;cursor:pointer;">×</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <p style="color:var(--text-muted);font-size:12px;margin:10px 0 0;">Clicca su una foto per vederla a tutto schermo, sulla × per eliminarla singolarmente senza toccare le altre.</p>
  </div>
  <div id="tl-photo-lightbox" style="display:none;position:fixed;inset:0;width:100vw;height:100vh;background:rgba(0,0,0,0.92);z-index:2000;">
    <button type="button" id="tl-photo-lightbox-close" aria-label="Chiudi" style="position:fixed;top:16px;right:16px;z-index:2010;width:40px;height:40px;border-radius:50%;border:none;background:rgba(255,255,255,0.15);color:#fff;font-size:18px;cursor:pointer;">✕</button>
    <img id="tl-photo-lightbox-img" src="" alt="" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);max-width:94vw;max-height:94vh;object-fit:contain;">
  </div>
  <?php endif; ?>

  <?php if (($post['visibility'] ?? 'public') !== 'private'): ?>
    <p><a href="/<?= e($profile['slug']) ?>/timeline/<?= (int) $post['id'] ?>" target="_blank">Vedi pagina pubblica ↗</a></p>
  <?php endif; ?>

  <div class="card">
    <div class="section-title" style="margin-top:0;color:#dc3545;">Zona pericolosa</div>
    <form method="post" onsubmit="return confirm('Eliminare definitivamente questo aggiornamento? L\'operazione non si può annullare.');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
      <button class="btn danger" type="submit">🗑️ Elimina aggiornamento</button>
    </form>
  </div>

  <script>
    (function () {
      const toggleBtn = document.getElementById('tl-ai-toggle');
      const panel = document.getElementById('tl-ai-panel');
      const cancelBtn = document.getElementById('tl-ai-cancel');
      const generateBtn = document.getElementById('tl-ai-generate');
      const keywordsInput = document.getElementById('tl-ai-keywords');
      const statusEl = document.getElementById('tl-ai-status');
      const textarea = document.getElementById('tl-edit-testo');
      const csrfInput = document.querySelector('#tl-ai-toggle').closest('form').querySelector('input[name="csrf"]');

      toggleBtn.addEventListener('click', function () {
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') keywordsInput.focus();
      });
      cancelBtn.addEventListener('click', function () {
        panel.style.display = 'none';
        statusEl.textContent = '';
      });

      generateBtn.addEventListener('click', function () {
        const keywords = keywordsInput.value.trim();
        if (!keywords) {
          statusEl.textContent = 'Scrivi almeno qualche parola chiave.';
          return;
        }
        generateBtn.disabled = true;
        statusEl.textContent = 'Generazione in corso...';

        const body = new URLSearchParams();
        body.set('csrf', csrfInput.value);
        body.set('keywords', keywords);

        fetch('/dashboard_ai_caption.php', { method: 'POST', body: body })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            generateBtn.disabled = false;
            if (data.ok) {
              textarea.value = data.text;
              statusEl.textContent = 'Fatto! Puoi modificare il testo prima di salvare.';
            } else {
              statusEl.textContent = data.error || 'Qualcosa è andato storto.';
            }
          })
          .catch(function () {
            generateBtn.disabled = false;
            statusEl.textContent = 'Errore di connessione. Riprova.';
          });
      });
    })();

    (function () {
      const lightbox = document.getElementById('tl-photo-lightbox');
      if (!lightbox) return;
      const lightboxImg = document.getElementById('tl-photo-lightbox-img');
      const closeBtn = document.getElementById('tl-photo-lightbox-close');

      function open(src) {
        lightboxImg.src = src;
        lightbox.style.display = 'block';
        document.body.style.overflow = 'hidden';
      }
      function close() {
        lightbox.style.display = 'none';
        lightboxImg.src = '';
        document.body.style.overflow = '';
      }

      document.querySelectorAll('.tl-photo-thumb').forEach(function (img) {
        img.addEventListener('click', function () { open(img.dataset.src); });
      });
      closeBtn.addEventListener('click', close);
      lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox) close();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && lightbox.style.display === 'block') close();
      });
    })();
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
