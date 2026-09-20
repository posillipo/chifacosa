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
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
        // Interpretato nel fuso orario scelto dal profilo (Dashboard -> Profilo e anagrafica),
        // non in quello del server — vedi parseLocalDateTime() in functions.php.
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        if ($publishAt && strtotime($publishAt) <= time()) {
            $publishAt = null;
        }

        if ($testo === '' && !$imagePath) {
            $error = 'Scrivi qualcosa o allega una foto.';
        } else {
            $stmt = getDB()->prepare('INSERT INTO timeline_posts (user_id, testo, image_path, image_thumb_path, visibility, in_feed, publish_at) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$profile['id'], $testo ?: null, $imagePath, $imageThumbPath, $visibility, $inFeed, $publishAt]);
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
    } elseif ($action === 'edit') {
        // Pannello "✏️ Gestisci pubblicazione" per un post già esistente — stessa logica di
        // modifica già disponibile per i moduli Che Amo (dashboard_fan_*.php): fino ad oggi qui
        // si poteva solo eliminare il post o le sue foto, non correggere testo/privacy/
        // programmazione dopo la pubblicazione.
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $testo = trim($_POST['testo'] ?? '');
        $hashtags = trim($_POST['hashtags'] ?? '');
        $callToAction = trim($_POST['call_to_action'] ?? '');
        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        if ($publishAt && strtotime($publishAt) <= time()) {
            $publishAt = null;
        }

        $isAjax = !empty($_POST['ajax']);
        if ($title === '' && $testo === '') {
            $error = 'Scrivi almeno un titolo o un testo.';
        } else {
            $stmt = getDB()->prepare('UPDATE timeline_posts SET title=?, testo=?, hashtags=?, call_to_action=?, visibility=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$title !== '' ? $title : null, $testo !== '' ? $testo : null, $hashtags !== '' ? $hashtags : null, $callToAction !== '' ? $callToAction : null, $visibility, $inFeed, $publishAt, $id, $profile['id']]);
            logAdminAction((int) $profile['id'], (int) $user['id'], 'Aggiornamento Timeline modificato');
        }

        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT * FROM timeline_posts WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => empty($error) && (bool) $row, 'item' => $row, 'error' => $error]);
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM timeline_posts WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteFeedShareImage($row['image_path']);
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
    } elseif ($action === 'delete_photo') {
        $id = (int) ($_POST['id'] ?? 0);
        $photoId = (int) ($_POST['photo_id'] ?? -1);
        $result = deleteSingleGalleryPhoto('timeline_posts', 'image_path', 'timeline_post_photos', 'post_id', $id, (int) $profile['id'], $photoId, 'image_thumb_path');
        if (!$result['ok']) {
            $error = $result['error'];
        }
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
    <input type="hidden" name="tz_offset_minutes" value="">
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
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:6px;">
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
    <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
      <input type="checkbox" name="in_feed" value="1" checked style="width:auto;"> Includi nel Feed
    </label>
    <p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Non riguarda la Timeline del sito (quella segue solo Pubblico/Solo io): serve solo per le automazioni social (es. Metricool) che leggono il feed RSS del profilo.</p>

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
            Attivo dal <?= e(formatLocalDateTime($profile['custom_feed_guid_since'], $profile)) ?>.
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
  <div id="tl-posts-list">
  <?php foreach ($posts as $p): ?>
    <?php
      $isScheduled = $p['publish_at'] && strtotime($p['publish_at']) > time();
      $isPrivate = $p['visibility'] === 'private';
      $stmt = getDB()->prepare('SELECT COUNT(*) c FROM timeline_post_photos WHERE post_id=?');
      $stmt->execute([$p['id']]);
      $extraPhotoCount = (int) $stmt->fetch()['c'];
    ?>
    <div class="card" data-tl-post="<?= (int) $p['id'] ?>" style="display:flex;gap:14px;align-items:flex-start;<?= $isScheduled ? 'border:1px solid #f0ad4e;' : '' ?>">
      <?php if ($p['image_path']): ?>
        <img src="/<?= e($p['image_thumb_path'] ?: $p['image_path']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div class="tl-badges" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px;">
          <?php if ($isScheduled): ?>
            <span class="tl-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">
              ⏰ Programmato per il <?= formatLocalDateTime($p['publish_at'], $profile) ?>
            </span>
          <?php endif; ?>
          <?php if ($isPrivate): ?>
            <span class="tl-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io</span>
          <?php elseif (!(int) ($p['in_feed'] ?? 1)): ?>
            <span class="tl-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">Non nel Feed</span>
          <?php endif; ?>
          <?php if ($extraPhotoCount > 0): ?>
            <span style="background:var(--accent);color:var(--accent-text);font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">📷 +<?= $extraPhotoCount ?> foto</span>
          <?php endif; ?>
          <?php if (($p['source'] ?? 'dashboard') === 'api'): ?>
            <span style="background:#20c997;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔌 Creato via API</span>
          <?php endif; ?>
        </div>
        <small style="color:var(--text-muted)"><?= formatLocalDateTime($p['created_at'], $profile) ?></small>
        <div class="tl-text-block">
          <?php if (!empty($p['title'])): ?><p class="tl-title" style="margin:4px 0;font-weight:700;"><?= e($p['title']) ?></p><?php endif; ?>
          <?php if ($p['testo']): ?><p class="tl-testo" style="margin:4px 0;"><?= nl2br(e($p['testo'])) ?></p><?php endif; ?>
          <?php if (!empty($p['hashtags'])): ?><p class="tl-hashtags" style="margin:4px 0;color:var(--accent);font-size:13px;"><?= e($p['hashtags']) ?></p><?php endif; ?>
          <?php if (!empty($p['call_to_action'])): ?><p class="tl-cta" style="margin:4px 0;font-style:italic;font-size:13px;"><?= e($p['call_to_action']) ?></p><?php endif; ?>
        </div>
        <?php if (!$isPrivate): ?>
          <a href="/<?= e($profile['slug']) ?>/timeline/<?= (int)$p['id'] ?>" target="_blank" style="font-size:13px;">Vedi pagina pubblica ↗</a>
        <?php endif; ?>

        <div class="tl-pub-block" style="margin-top:8px;">
          <button type="button" class="btn small secondary tl-pub-toggle">✏️ Gestisci pubblicazione</button>
          <form class="tl-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">
            <label>Titolo (opzionale)</label>
            <input type="text" class="tl-pub-title" value="<?= e($p['title'] ?? '') ?>">

            <label>Testo</label>
            <textarea class="tl-pub-textarea" rows="3"><?= e($p['testo'] ?? '') ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">
              <button type="button" class="btn small secondary tl-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="tl-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="tl-ai-keywords" rows="2" placeholder="es. annuncio nuovo concerto sabato 14 a Milano"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small tl-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary tl-ai-cancel">Annulla</button>
              </div>
              <p class="tl-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>

            <label>Hashtag (opzionale)</label>
            <input type="text" class="tl-pub-hashtags" value="<?= e($p['hashtags'] ?? '') ?>">

            <label>Call to action (opzionale)</label>
            <input type="text" class="tl-pub-cta" value="<?= e($p['call_to_action'] ?? '') ?>">

            <label>Privacy</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="tl-pub-visibility" name="tl-visibility-<?= (int) $p['id'] ?>" value="public" <?= $isPrivate ? '' : 'checked' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="tl-pub-visibility" name="tl-visibility-<?= (int) $p['id'] ?>" value="private" <?= $isPrivate ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>

            <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
              <input type="checkbox" class="tl-pub-in-feed" <?= ($p['in_feed'] ?? 1) ? 'checked' : '' ?> style="width:auto;"> Includi nel Feed
            </label>
            <p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Non riguarda la Timeline del sito (quella segue solo Pubblico/Solo io): serve solo per le automazioni social (es. Metricool) che leggono il feed RSS del profilo.</p>

            <label>Programma la pubblicazione (opzionale)</label>
            <input type="datetime-local" class="tl-pub-publish-at" value="<?= e(localDateTimeInputValue($p['publish_at'], $profile)) ?>">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>

            <p class="tl-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small tl-pub-save">Salva</button>
              <button type="button" class="btn small secondary tl-pub-cancel">Annulla</button>
            </div>
          </form>
        </div>

        <?php if ($p['image_path']):
          $tlExtraPhotos = getDB()->prepare('SELECT id, image_path FROM timeline_post_photos WHERE post_id=? ORDER BY sort_order ASC, id ASC');
          $tlExtraPhotos->execute([(int) $p['id']]);
          $tlExtraPhotos = $tlExtraPhotos->fetchAll();
        ?>
        <details class="help-box" style="margin:8px 0;">
          <summary>🖼️ Gestisci foto</summary>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
            <div style="position:relative;width:72px;">
              <img src="/<?= e($p['image_path']) ?>" style="width:72px;height:72px;border-radius:8px;object-fit:cover;">
              <span style="position:absolute;top:2px;left:2px;background:rgba(0,0,0,0.6);color:#fff;font-size:10px;padding:1px 5px;border-radius:4px;">Copertina</span>
              <form method="post" onsubmit="return confirm('Eliminare la copertina? La prossima foto la sostituirà.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_photo">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="photo_id" value="0">
                <button type="submit" title="Elimina questa foto" style="position:absolute;top:2px;right:2px;width:20px;height:20px;border-radius:50%;border:none;background:rgba(220,53,69,0.9);color:#fff;font-size:12px;line-height:1;cursor:pointer;">×</button>
              </form>
            </div>
            <?php foreach ($tlExtraPhotos as $ph): ?>
              <div style="position:relative;width:72px;">
                <img src="/<?= e($ph['image_path']) ?>" style="width:72px;height:72px;border-radius:8px;object-fit:cover;">
                <form method="post" onsubmit="return confirm('Eliminare questa foto?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_photo">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
                  <button type="submit" title="Elimina questa foto" style="position:absolute;top:2px;right:2px;width:20px;height:20px;border-radius:50%;border:none;background:rgba(220,53,69,0.9);color:#fff;font-size:12px;line-height:1;cursor:pointer;">×</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
          <p style="color:var(--text-muted);font-size:12px;margin:6px 0 0;">Clicca la × su una foto per eliminarla singolarmente, senza toccare le altre.</p>
        </details>
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
  </div>

  <script>
    // Genera nel browser una miniatura JPEG leggera (max 600px, qualità 0.82) dalla foto
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
            const maxDim = 600;
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

    // Pannello "✏️ Gestisci pubblicazione" per i post già pubblicati (delegato: funziona per
    // tutte le card presenti al caricamento della pagina).
    (function () {
      const listBox = document.getElementById('tl-posts-list');
      if (!listBox) return;
      const csrfInput = document.querySelector('form input[name="csrf"]');

      function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      }

      function post(formData) {
        formData.set('csrf', csrfInput.value);
        formData.set('ajax', '1');
        return fetch('/dashboard_post.php', { method: 'POST', body: formData }).then(r => r.json());
      }

      listBox.addEventListener('click', function (e) {
        const toggleBtn = e.target.closest('.tl-pub-toggle');
        const cancelBtn = e.target.closest('.tl-pub-cancel');
        const saveBtn = e.target.closest('.tl-pub-save');
        const aiToggleBtn = e.target.closest('.tl-ai-toggle');
        const aiCancelBtn = e.target.closest('.tl-ai-cancel');
        const aiGenerateBtn = e.target.closest('.tl-ai-generate');

        if (toggleBtn) {
          const block = toggleBtn.closest('.tl-pub-block');
          block.querySelector('.tl-pub-editor').style.display = 'block';
          block.querySelector('.tl-pub-textarea').focus();
          return;
        }

        if (cancelBtn) {
          cancelBtn.closest('.tl-pub-editor').style.display = 'none';
          return;
        }

        if (aiToggleBtn) {
          const panel = aiToggleBtn.closest('.tl-pub-editor').querySelector('.tl-ai-panel');
          panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
          if (panel.style.display === 'block') panel.querySelector('.tl-ai-keywords').focus();
          return;
        }

        if (aiCancelBtn) {
          const panel = aiCancelBtn.closest('.tl-ai-panel');
          panel.style.display = 'none';
          panel.querySelector('.tl-ai-status').textContent = '';
          return;
        }

        if (aiGenerateBtn) {
          const panel = aiGenerateBtn.closest('.tl-ai-panel');
          const editor = aiGenerateBtn.closest('.tl-pub-editor');
          const keywords = panel.querySelector('.tl-ai-keywords').value.trim();
          const statusEl = panel.querySelector('.tl-ai-status');
          if (!keywords) {
            statusEl.textContent = 'Scrivi almeno qualche parola chiave.';
            return;
          }
          aiGenerateBtn.disabled = true;
          statusEl.textContent = 'Generazione in corso...';
          const body = new URLSearchParams();
          body.set('csrf', csrfInput.value);
          body.set('keywords', keywords);
          fetch('/dashboard_ai_caption.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              aiGenerateBtn.disabled = false;
              if (data.ok) {
                editor.querySelector('.tl-pub-textarea').value = data.text;
                statusEl.textContent = 'Fatto! Puoi modificare il testo prima di salvare.';
              } else {
                statusEl.textContent = data.error || 'Qualcosa è andato storto.';
              }
            })
            .catch(function () {
              aiGenerateBtn.disabled = false;
              statusEl.textContent = 'Errore di connessione. Riprova.';
            });
          return;
        }

        if (saveBtn) {
          const editor = saveBtn.closest('.tl-pub-editor');
          const row = saveBtn.closest('[data-tl-post]');
          const id = row.getAttribute('data-tl-post');
          const title = editor.querySelector('.tl-pub-title').value;
          const testo = editor.querySelector('.tl-pub-textarea').value;
          const hashtags = editor.querySelector('.tl-pub-hashtags').value;
          const callToAction = editor.querySelector('.tl-pub-cta').value;
          const visibility = editor.querySelector('.tl-pub-visibility:checked').value;
          const inFeed = editor.querySelector('.tl-pub-in-feed').checked;
          const publishAt = editor.querySelector('.tl-pub-publish-at').value;
          const statusEl = editor.querySelector('.tl-pub-status');

          const formData = new FormData();
          formData.set('action', 'edit');
          formData.set('id', id);
          formData.set('title', title);
          formData.set('testo', testo);
          formData.set('hashtags', hashtags);
          formData.set('call_to_action', callToAction);
          formData.set('visibility', visibility);
          formData.set('in_feed', inFeed ? '1' : '');
          formData.set('publish_at', publishAt);
          formData.set('tz_offset_minutes', new Date().getTimezoneOffset());

          saveBtn.disabled = true;
          statusEl.textContent = 'Salvataggio...';
          post(formData).then(function (data) {
            saveBtn.disabled = false;
            if (!data.ok) {
              statusEl.textContent = data.error || 'Salvataggio non riuscito, riprova.';
              return;
            }
            statusEl.textContent = '';
            const textBlock = row.querySelector('.tl-text-block');
            let html = '';
            if (title.trim() !== '') html += '<p class="tl-title" style="margin:4px 0;font-weight:700;">' + escapeHtml(title) + '</p>';
            if (testo.trim() !== '') html += '<p class="tl-testo" style="margin:4px 0;">' + escapeHtml(testo).replace(/\n/g, '<br>') + '</p>';
            if (hashtags.trim() !== '') html += '<p class="tl-hashtags" style="margin:4px 0;color:var(--accent);font-size:13px;">' + escapeHtml(hashtags) + '</p>';
            if (callToAction.trim() !== '') html += '<p class="tl-cta" style="margin:4px 0;font-style:italic;font-size:13px;">' + escapeHtml(callToAction) + '</p>';
            textBlock.innerHTML = html;

            const isScheduled = data.item.publish_at && new Date(data.item.publish_at.replace(' ', 'T')).getTime() > Date.now();
            const isPrivate = data.item.visibility === 'private';
            const badgesBox = row.querySelector('.tl-badges');
            let badgesHtml = '';
            if (isScheduled) badgesHtml += '<span class="tl-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il ' + escapeHtml(data.item.publish_at) + '</span>';
            if (isPrivate) {
              badgesHtml += '<span class="tl-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io</span>';
            } else if (!parseInt(data.item.in_feed || 1, 10)) {
              badgesHtml += '<span class="tl-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">Non nel Feed</span>';
            }
            const existingBadges = Array.prototype.slice.call(badgesBox.children).filter(function (el) {
              return !el.classList.contains('tl-badge-scheduled') && !el.classList.contains('tl-badge-private');
            });
            badgesBox.innerHTML = badgesHtml;
            existingBadges.forEach(function (el) { badgesBox.appendChild(el); });

            editor.style.display = 'none';
          }).catch(function () {
            saveBtn.disabled = false;
            statusEl.textContent = 'Errore di connessione. Riprova.';
          });
          return;
        }
      });
    })();

    // Arrivo diretto su un post da modificare (es. dal link "Modifica" di dashboard_schedule.php,
    // /dashboard_post.php?edit=ID): scorre fino a quella card e apre subito il suo pannello, senza
    // dover cercare il post a mano nell'elenco — stessa comodità di dashboard_blog_edit.php, che
    // invece ha una pagina dedicata per singolo articolo.
    (function () {
      const params = new URLSearchParams(window.location.search);
      const editId = params.get('edit');
      if (!editId) return;
      const row = document.querySelector('[data-tl-post="' + CSS.escape(editId) + '"]');
      if (!row) return;
      const toggleBtn = row.querySelector('.tl-pub-toggle');
      if (toggleBtn) toggleBtn.click();
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      row.style.transition = 'box-shadow 0.3s ease';
      row.style.boxShadow = '0 0 0 3px var(--accent)';
      setTimeout(function () { row.style.boxShadow = ''; }, 2000);
    })();
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
