<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Nuovo articolo';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $albumId = (int) ($_POST['album_id'] ?? 0) ?: null;
    $tagsRaw = trim($_POST['tags'] ?? '');
    $tags = $tagsRaw !== '' ? implode(', ', array_filter(array_map('trim', explode(',', $tagsRaw)), fn ($t) => $t !== '')) : null;
    $tags = $tags !== '' ? $tags : null;
    $categoryIds = array_filter(array_map('intval', $_POST['category_ids'] ?? []));
    // Stesso pattern di dashboard_albums.php: interpretata nel fuso orario reale di chi sta
    // scrivendo in questo momento (offset del browser), campo vuoto = pubblica subito.
    $publishedAt = parseLocalDateTime($_POST['published_at'] ?? '', $profile, browserTzOffsetFromRequest()) ?: date('Y-m-d H:i:s');

    if ($title === '' || $content === '') {
        $error = 'Titolo e contenuto sono obbligatori.';
    } else {
        // Verifica che l'album e le categorie appartengano davvero a questo utente, per evitare
        // che un ID arbitrario nel form li associ a roba di qualcun altro.
        if ($albumId) {
            $albStmt = getDB()->prepare('SELECT id FROM photo_albums WHERE id=? AND user_id=?');
            $albStmt->execute([$albumId, $profile['id']]);
            if (!$albStmt->fetch()) {
                $albumId = null;
            }
        }
        if ($categoryIds) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $catStmt = getDB()->prepare("SELECT id FROM blog_categories WHERE user_id=? AND id IN ($placeholders)");
            $catStmt->execute(array_merge([$profile['id']], $categoryIds));
            $categoryIds = array_column($catStmt->fetchAll(), 'id');
        }

        $excerpt = textExcerpt($content, 200);
        $coverPath = handleCoverUpload($profile['slug']);

        $slug = generateUniquePostSlug((int) $profile['id'], $title);
        $stmt = getDB()->prepare('INSERT INTO blog_posts (user_id, title, slug, excerpt, content, cover_path, album_id, tags, published_at) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$profile['id'], $title, $slug, $excerpt, $content, $coverPath, $albumId, $tags, $publishedAt]);
        $newId = (int) getDB()->lastInsertId();
        if ($categoryIds) {
            $insCat = getDB()->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (?,?)');
            foreach ($categoryIds as $cid) {
                $insCat->execute([$newId, $cid]);
            }
        }
        if (strtotime($publishedAt) <= time()) {
            $postUrl = siteUrl(blogPostUrl($profile['slug'], ['published_at' => $publishedAt, 'slug' => $slug]));
            notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'blog', $title, $postUrl);
        }
        header('Location: /dashboard_blog_posts.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM blog_categories WHERE user_id=? ORDER BY name ASC');
$stmt->execute([$profile['id']]);
$categories = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT id, title FROM photo_albums WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$albums = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <p><a href="/dashboard_blog.php"><i class="fa-solid fa-arrow-left"></i> Torna al blog</a></p>

  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Scrivi un articolo, eventualmente collegalo a un album della sezione Foto, aggiungi tag
      liberi e una o più categorie, e scegli quando farlo comparire: subito, o programmato per
      una data futura (proprio come per gli album fotografici). Un articolo programmato resta
      visibile solo a te, qui in dashboard, finché non arriva la data scelta.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="section-title">Nuovo articolo</div>
  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="tz_offset_minutes" value="">
    <label>Titolo post</label>
    <input type="text" name="title" required>
    <label>Contenuto</label>
    <textarea name="content" id="blog-ai-testo" rows="10" required></textarea>
    <div id="blog-ai-caption-box" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 14px;">
      <button type="button" class="btn small secondary" id="blog-ai-caption-toggle">✨ Genera con AI</button>
    </div>
    <div id="blog-ai-caption-panel" class="card" style="display:none;margin:-8px 0 14px;">
      <label>Qualche parola chiave o istruzione per l'AI</label>
      <textarea id="blog-ai-caption-keywords" rows="2" placeholder="es. articolo sul nuovo album, tono entusiasta"></textarea>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn small" id="blog-ai-caption-generate">Genera testo</button>
        <button type="button" class="btn small secondary" id="blog-ai-caption-cancel">Annulla</button>
      </div>
      <p id="blog-ai-caption-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
    </div>
    <label>Copertina quadrata (opzionale, jpg/png/webp — usata anche come immagine di anteprima quando condividi il link)</label>
    <input type="file" name="cover" accept="image/*">

    <label>Album collegato (opzionale)</label>
    <select name="album_id">
      <option value="">— Nessun album —</option>
      <?php foreach ($albums as $al): ?>
        <option value="<?= (int) $al['id'] ?>"><?= e($al['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$albums): ?>
      <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Non hai ancora nessun album nella sezione Foto.</p>
    <?php endif; ?>

    <label>Tag (opzionale, separati da virgola)</label>
    <input type="text" name="tags" placeholder="es. concerti, novità, napoli">

    <?php if ($categories): ?>
      <label>Categorie (opzionale, puoi sceglierne più di una)</label>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px;margin-bottom:14px;">
        <?php foreach ($categories as $cat): ?>
          <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
            <input type="checkbox" name="category_ids[]" value="<?= (int) $cat['id'] ?>" style="width:auto;">
            <?= e($cat['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:var(--text-muted);font-size:12.5px;">Non hai ancora nessuna categoria — <a href="/dashboard_blog_categories.php">creane una</a> per poterla assegnare qui.</p>
    <?php endif; ?>

    <label>Programma la pubblicazione (opzionale)</label>
    <input type="datetime-local" name="published_at">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicare subito.</p>

    <button type="submit" class="btn">Pubblica</button>
  </form>

  <script>
  (function () {
    const toggleBtn = document.getElementById('blog-ai-caption-toggle');
    const panel = document.getElementById('blog-ai-caption-panel');
    const cancelBtn = document.getElementById('blog-ai-caption-cancel');
    const generateBtn = document.getElementById('blog-ai-caption-generate');
    const keywordsInput = document.getElementById('blog-ai-caption-keywords');
    const statusEl = document.getElementById('blog-ai-caption-status');
    const textarea = document.getElementById('blog-ai-testo');
    const csrfInput = toggleBtn.closest('form').querySelector('input[name="csrf"]');

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
