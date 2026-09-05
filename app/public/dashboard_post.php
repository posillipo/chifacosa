<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); // il profilo su cui si sta agendo (proprio, o co-gestito)
$activeTab = 'post';
$pageTitle = 'Timeline';

$feedError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        // Link personalizzato per il feed (ex dashboard_feed.php, ora vive qui): impostazione
        // del profilo, non del singolo post — si aggiorna comunque ogni volta che si pubblica,
        // indipendentemente dal fatto che il post stesso vada a buon fine, così cambiare o
        // svuotare il link non richiede per forza di scrivere qualcosa di nuovo.
        $customFeedGuid = trim($_POST['custom_feed_guid'] ?? '');
        if ($customFeedGuid !== '' && !filter_var($customFeedGuid, FILTER_VALIDATE_URL)) {
            $feedError = 'Il link personalizzato per il feed non è un URL valido.';
        } else {
            $customFeedGuid = $customFeedGuid ?: null;
            $customFeedGuidSince = $profile['custom_feed_guid_since'] ?? null;
            if ($customFeedGuid !== ($profile['custom_feed_guid'] ?? null)) {
                $customFeedGuidSince = $customFeedGuid ? date('Y-m-d H:i:s') : null;
            }
            $stmt = getDB()->prepare('UPDATE profiles SET custom_feed_guid=?, custom_feed_guid_since=? WHERE user_id=?');
            $stmt->execute([$customFeedGuid, $customFeedGuidSince, $profile['id']]);
            $user = currentUser();
            $profile = getActingProfile($user);
        }

        $testo = trim($_POST['testo'] ?? '');
        // Fino a 10 foto: la prima resta su image_path (quella sola che compare nel Feed, come
        // sempre), le eventuali altre (fino a 9) alimentano il carosello nella pagina di dettaglio.
        $uploadedPhotos = handleMultiCoverUpload($profile['slug'], 'images', 10);
        $imagePath = $uploadedPhotos[0] ?? null;
        $extraPhotos = array_slice($uploadedPhotos, 1);

        // Miniatura leggera per la lista/feed, generata nel browser (canvas) al momento della
        // selezione del file — vedi commento analogo per il ritaglio avatar in
        // dashboard_profile.php: niente elaborazione lato server, non serve GD/Imagick. La foto
        // originale caricata sopra resta intatta ed è quella mostrata aprendo il post.
        $imageThumbPath = null;
        if ($imagePath) {
            $thumbData = $_POST['image_thumb_data'] ?? '';
            if ($thumbData !== '' && preg_match('#^data:image/jpeg;base64,#', $thumbData)) {
                $raw = base64_decode(substr($thumbData, strpos($thumbData, ',') + 1), true);
                if ($raw !== false && strlen($raw) > 0 && strlen($raw) < 2 * 1024 * 1024) {
                    $fname = 'thumb_' . bin2hex(random_bytes(6)) . '.jpg';
                    $dir = __DIR__ . '/uploads/images/' . $profile['slug'];
                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                    if (file_put_contents($dir . '/' . $fname, $raw) !== false) {
                        $imageThumbPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
                    }
                }
            }
        }

        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $scheduleRaw = trim($_POST['publish_at'] ?? '');
        $publishAt = null;
        if ($scheduleRaw !== '') {
            $ts = strtotime($scheduleRaw);
            if ($ts && $ts > time()) {
                $publishAt = date('Y-m-d H:i:s', $ts);
            }
        }

        if ($testo === '' && !$imagePath) {
            $error = 'Scrivi qualcosa o allega una foto.';
        } else {
            $stmt = getDB()->prepare('INSERT INTO timeline_posts (user_id, testo, image_path, image_thumb_path, visibility, publish_at) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$profile['id'], $testo ?: null, $imagePath, $imageThumbPath, $visibility, $publishAt]);
            $newPostId = (int) getDB()->lastInsertId();

            if ($extraPhotos) {
                $insPhoto = getDB()->prepare('INSERT INTO timeline_post_photos (post_id, image_path, sort_order) VALUES (?,?,?)');
                foreach ($extraPhotos as $photoIndex => $photoPath) {
                    $insPhoto->execute([$newPostId, $photoPath, $photoIndex]);
                }
            }

            logAdminAction((int) $profile['id'], (int) $user['id'], 'Nuovo aggiornamento in Timeline', $testo !== '' ? textExcerpt($testo, 60) : 'Foto pubblicata');

            // Niente notifica ai follower se il post è privato o programmato per il futuro —
            // scatterà semmai in futuro, quando sarà davvero pubblicato (non gestito automaticamente
            // oggi: la notifica per i post programmati va eventualmente rivista quando arriva il momento).
            if ($visibility === 'public' && !$publishAt) {
                $anteprima = $testo !== '' ? textExcerpt($testo, 80) : 'Nuova foto pubblicata';
                $timelineUrl = siteUrl('/' . $profile['slug'] . '/timeline');
                notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'timeline', $anteprima, $timelineUrl);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM timeline_posts WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteCoverFile($row['image_thumb_path']);
            foreach (getTimelinePostPhotos($id) as $extraPath) {
                deleteCoverFile($extraPath);
            }
        }
        // timeline_post_photos ha ON DELETE CASCADE: le righe spariscono da sole, qui sopra
        // servivano solo per cancellare i FILE dal disco prima che spariscano i riferimenti.
        $stmt = getDB()->prepare('DELETE FROM timeline_posts WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        logAdminAction((int) $profile['id'], (int) $user['id'], 'Aggiornamento eliminato dalla Timeline');
    }
}

$stmt = getDB()->prepare('SELECT * FROM timeline_posts WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$profile['id']]);
$posts = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Un modo rapido per condividere un pensiero, un annuncio breve, o una foto — senza dover
      scrivere un articolo completo come nel Blog. Puoi renderlo pubblico o visibile solo a te,
      e programmarne la pubblicazione per una data futura.
    </p>
    <p style="color:var(--text-muted)">
      Il link personalizzato per il feed è opzionale: se lo compili, chi clicca sui post
      pubblicati <strong>da questo momento in poi</strong> (dalla Timeline, dal feed RSS o da
      un'automazione tipo Metricool) viene reindirizzato lì invece che alla pagina del post su
      <?= e(siteName()) ?>. Il feed RSS continua comunque a esporre il permalink standard e
      l'immagine caricata qui, per restare compatibile con strumenti come Metricool. Vale finché
      non lo modifichi o lo svuoti — non serve ripeterlo a ogni pubblicazione.
    </p>
    <p style="color:var(--text-muted)">
      Il pulsante <strong>✨ Genera con AI</strong> scrive una bozza di testo a partire da poche
      parole chiave: scrivi cosa vuoi comunicare, l'AI propone un testo pronto che puoi modificare
      liberamente prima di pubblicare.
    </p>
  </details>

  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($feedError)): ?><div class="alert error"><?= e($feedError) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <label>Cosa vuoi condividere?</label>
    <textarea name="testo" id="ai-testo" rows="3" placeholder="Scrivilo qui..."></textarea>
    <div id="ai-caption-box" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 14px;">
      <button type="button" class="btn small secondary" id="ai-caption-toggle">✨ Genera con AI</button>
    </div>
    <div id="ai-caption-panel" class="card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-8px 0 14px;">
      <label>Qualche parola chiave o istruzione per l'AI</label>
      <textarea id="ai-caption-keywords" rows="2" placeholder="es. annuncio nuovo concerto sabato 14 a Milano"></textarea>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn small" id="ai-caption-generate">Genera testo</button>
        <button type="button" class="btn small secondary" id="ai-caption-cancel">Annulla</button>
      </div>
      <p id="ai-caption-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
    </div>
    <label>Foto (fino a 10, opzionale)</label>
    <input type="file" name="images[]" id="post-image-input" accept="image/*" multiple>
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">
      Se ne carichi più di una, sulla pagina del post appariranno in un carosello scorrevole
      (come su Instagram) — nel Feed e in Timeline continua a comparire solo la prima.
    </p>
    <input type="hidden" name="image_thumb_data" id="post-image-thumb-data">

    <label>Privacy</label>
    <div style="display:flex;gap:16px;margin-bottom:14px;">
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="visibility" value="public" checked style="width:auto;"> Pubblico
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="visibility" value="private" style="width:auto;"> Solo io
      </label>
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;">
      <div style="flex:1;min-width:220px;">
        <label>Programma la pubblicazione (opzionale)</label>
        <input type="datetime-local" name="publish_at">
        <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicare subito.</p>
      </div>
      <div style="flex:1;min-width:220px;">
        <label>Link personalizzato per il feed (opzionale)</label>
        <input type="url" name="custom_feed_guid" value="<?= e($profile['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
        <?php if (!empty($profile['custom_feed_guid_since'])): ?>
          <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">
            Attivo dal <?= e(date('d/m/Y H:i', strtotime($profile['custom_feed_guid_since']))) ?>.
          </p>
        <?php else: ?>
          <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per usare sempre la pagina normale.</p>
        <?php endif; ?>
      </div>
    </div>

    <button type="submit" class="btn">Pubblica</button>
  </form>

  <div class="card">
    <strong>Il tuo feed:</strong><br>
    <a href="/<?= e($profile['slug']) ?>/feed" target="_blank"><?= e(siteName()) ?>/<?= e($profile['slug']) ?>/feed</a>
  </div>

  <div class="section-title">I tuoi aggiornamenti (<?= count($posts) ?>)</div>
  <?php foreach ($posts as $p): ?>
    <?php
      $isScheduled = $p['publish_at'] && strtotime($p['publish_at']) > time();
      $isPrivate = $p['visibility'] === 'private';
      $stmt = getDB()->prepare('SELECT COUNT(*) c FROM timeline_post_photos WHERE post_id=?');
      $stmt->execute([$p['id']]);
      $extraPhotoCount = (int) $stmt->fetch()['c'];
    ?>
    <div class="card" style="display:flex;gap:14px;align-items:flex-start;<?= $isScheduled ? 'border:1px solid #f0ad4e;' : '' ?>">
      <?php if ($p['image_path']): ?>
        <img src="/<?= e($p['image_thumb_path'] ?: $p['image_path']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px;">
          <?php if ($isScheduled): ?>
            <span style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">
              ⏰ Programmato per il <?= date('d/m/Y H:i', strtotime($p['publish_at'])) ?>
            </span>
          <?php endif; ?>
          <?php if ($isPrivate): ?>
            <span style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io</span>
          <?php endif; ?>
          <?php if ($extraPhotoCount > 0): ?>
            <span style="background:var(--accent);color:var(--accent-text);font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">📷 +<?= $extraPhotoCount ?> foto</span>
          <?php endif; ?>
        </div>
        <small style="color:var(--text-muted)"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></small>
        <?php if ($p['testo']): ?><p style="margin:4px 0;"><?= nl2br(e($p['testo'])) ?></p><?php endif; ?>
        <?php if (!$isPrivate): ?>
          <a href="/<?= e($profile['slug']) ?>/timeline/<?= (int)$p['id'] ?>" target="_blank" style="font-size:13px;">Vedi pagina pubblica ↗</a>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Eliminare questo aggiornamento?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn small danger" type="submit">Elimina</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <script>
    // Genera nel browser una miniatura JPEG leggera (max 320px, qualità 0.82) dalla foto
    // selezionata, per alleggerire la lista/feed — l'originale caricato resta a piena qualità.
    (function () {
      const imageInput = document.getElementById('post-image-input');
      const thumbDataInput = document.getElementById('post-image-thumb-data');
      if (!imageInput || !thumbDataInput) return;

      imageInput.addEventListener('change', function () {
        thumbDataInput.value = '';
        const file = imageInput.files && imageInput.files[0];
        if (!file) return;

        const img = new Image();
        const reader = new FileReader();
        reader.onload = function (e) {
          img.onload = function () {
            const maxDim = 320;
            const scale = Math.min(1, maxDim / Math.max(img.width, img.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.width * scale);
            canvas.height = Math.round(img.height * scale);
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            thumbDataInput.value = canvas.toDataURL('image/jpeg', 0.82);
          };
          img.src = e.target.result;
        };
        reader.readAsDataURL(file);
      });
    })();

    (function () {
      const toggleBtn = document.getElementById('ai-caption-toggle');
      const panel = document.getElementById('ai-caption-panel');
      const cancelBtn = document.getElementById('ai-caption-cancel');
      const generateBtn = document.getElementById('ai-caption-generate');
      const keywordsInput = document.getElementById('ai-caption-keywords');
      const statusEl = document.getElementById('ai-caption-status');
      const textarea = document.getElementById('ai-testo');
      const csrfInput = document.querySelector('#ai-caption-toggle').closest('form').querySelector('input[name="csrf"]');

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
              statusEl.textContent = 'Fatto! Puoi modificare il testo prima di pubblicare.';
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
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
