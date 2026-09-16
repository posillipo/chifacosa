<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Blog';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Inserisci un nome per la categoria.';
        } else {
            $catSlug = generateUniqueBlogCategorySlug((int) $profile['id'], $name);
            $stmt = getDB()->prepare('INSERT INTO blog_categories (user_id, name, slug) VALUES (?,?,?)');
            $stmt->execute([$profile['id'], $name, $catSlug]);
        }
    } elseif ($action === 'delete_category') {
        $id = (int) ($_POST['id'] ?? 0);
        // blog_post_categories ha ON DELETE CASCADE: gli articoli restano, perdono solo
        // l'assegnazione a questa categoria.
        getDB()->prepare('DELETE FROM blog_categories WHERE id=? AND user_id=?')->execute([$id, $profile['id']]);
    } elseif ($action === 'add') {
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
            // Verifica che l'album e le categorie appartengano davvero a questo utente, per
            // evitare che un ID arbitrario nel form li associ a roba di qualcun altro.
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
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM blog_posts WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM blog_posts WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    }
    if (!$error) {
        header('Location: /dashboard_blog.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM blog_categories WHERE user_id=? ORDER BY name ASC');
$stmt->execute([$profile['id']]);
$categories = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT id, title FROM photo_albums WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$albums = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT * FROM blog_posts WHERE user_id=? ORDER BY published_at DESC');
$stmt->execute([$profile['id']]);
$posts = $stmt->fetchAll();
$postCategoryIds = [];
foreach ($posts as $p) {
    $postCategoryIds[(int) $p['id']] = array_column(getBlogPostCategories((int) $p['id']), 'id');
}

include __DIR__ . '/_dash_header.php';
?>
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

  <div class="section-title">Categorie</div>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_category">
    <label>Nuova categoria (es. "Concerti", "Novità", "Dietro le quinte")</label>
    <div style="display:flex;gap:8px;">
      <input type="text" name="name" required style="flex:1;margin-bottom:0;">
      <button type="submit" class="btn" style="width:auto;">Aggiungi categoria</button>
    </div>
  </form>
  <?php if ($categories): ?>
    <div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <?php foreach ($categories as $cat): ?>
        <span class="icon-btn" style="width:auto;padding:0 10px 0 14px;gap:8px;display:inline-flex;">
          <?= e($cat['name']) ?>
          <form method="post" onsubmit="return confirm('Eliminare la categoria &quot;<?= e($cat['name']) ?>&quot;? Gli articoli restano, perdono solo questa categoria.');" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_category">
            <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
            <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;font-size:15px;line-height:1;">×</button>
          </form>
        </span>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="alert error">Nessuna categoria ancora — creane una qui sopra per poterla assegnare agli articoli.</div>
  <?php endif; ?>

  <div class="section-title">Nuovo articolo</div>
  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="tz_offset_minutes" value="">
    <label>Titolo post</label>
    <input type="text" name="title" required>
    <label>Contenuto</label>
    <textarea name="content" id="blog-ai-testo" rows="6" required></textarea>
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
    <?php endif; ?>

    <label>Programma la pubblicazione (opzionale)</label>
    <input type="datetime-local" name="published_at">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicare subito.</p>

    <button type="submit" class="btn">Pubblica</button>
  </form>

  <div class="section-title">I tuoi post (<?= count($posts) ?>)</div>
  <?php if (!$posts): ?>
    <div class="alert error">Non hai ancora scritto nessun articolo.</div>
  <?php endif; ?>
  <?php foreach ($posts as $p): ?>
    <?php $isScheduled = strtotime($p['published_at']) > time(); ?>
    <div class="blog-item" style="display:flex;gap:14px;align-items:flex-start;">
      <?php if ($p['cover_path']): ?>
        <img src="/<?= e($p['cover_path']) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <?php if ($isScheduled): ?>
          <div style="color:#f0ad4e;font-size:12.5px;font-weight:700;"><i class="fa-solid fa-clock"></i> Programmato per il <?= e(formatLocalDateTime($p['published_at'], $profile)) ?></div>
        <?php else: ?>
          <div class="date"><?= e(formatLocalDateTime($p['published_at'], $profile)) ?></div>
        <?php endif; ?>
        <strong><?= e($p['title']) ?></strong>
        <p style="color:var(--text-muted);font-size:13px;margin:4px 0;"><?= e($p['excerpt'] ?: textExcerpt($p['content'])) ?></p>
        <?php $pCats = array_filter($categories, fn ($c) => in_array((int) $c['id'], $postCategoryIds[(int) $p['id']], true)); ?>
        <?php if ($pCats || $p['tags']): ?>
          <p style="display:flex;gap:6px;flex-wrap:wrap;margin:6px 0;">
            <?php foreach ($pCats as $c): ?><span class="icon-btn" style="width:auto;padding:0 10px;font-size:12px;"><?= e($c['name']) ?></span><?php endforeach; ?>
            <?php if ($p['tags']): ?><span style="color:var(--text-muted);font-size:12.5px;align-self:center;"><i class="fa-solid fa-tags"></i> <?= e($p['tags']) ?></span><?php endif; ?>
          </p>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="/dashboard_blog_edit.php?id=<?= (int) $p['id'] ?>" class="btn small secondary">✏️ Modifica</a>
          <a href="<?= e(blogPostUrl($profile['slug'], $p)) ?>" target="_blank" class="btn small secondary">↗ Vedi online</a>
          <form method="post" onsubmit="return confirm('Eliminare questo post?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

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
