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
        $imagePath = handleCoverUpload($profile['slug'], 'image');
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
            $stmt = getDB()->prepare('INSERT INTO timeline_posts (user_id, testo, image_path, visibility, publish_at) VALUES (?,?,?,?,?)');
            $stmt->execute([$profile['id'], $testo ?: null, $imagePath, $visibility, $publishAt]);
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
        $stmt = getDB()->prepare('SELECT image_path FROM timeline_posts WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
        }
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
      <input type="text" id="ai-caption-keywords" placeholder="es. annuncio nuovo concerto sabato 14 a Milano">
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn small" id="ai-caption-generate">Genera testo</button>
        <button type="button" class="btn small secondary" id="ai-caption-cancel">Annulla</button>
      </div>
      <p id="ai-caption-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
    </div>
    <label>Foto (opzionale)</label>
    <input type="file" name="image" accept="image/*">

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
    ?>
    <div class="card" style="display:flex;gap:14px;align-items:flex-start;<?= $isScheduled ? 'border:1px solid #f0ad4e;' : '' ?>">
      <?php if ($p['image_path']): ?>
        <img src="/<?= e($p['image_path']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
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
