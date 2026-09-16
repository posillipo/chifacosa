<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Modifica articolo';
$error = null;

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = getDB()->prepare('SELECT * FROM blog_posts WHERE id=? AND user_id=?');
$stmt->execute([$id, $profile['id']]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    exit('Articolo non trovato.');
}

$stmt = getDB()->prepare('SELECT * FROM blog_categories WHERE user_id=? ORDER BY name ASC');
$stmt->execute([$profile['id']]);
$categories = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT id, title FROM photo_albums WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$albums = $stmt->fetchAll();

// Categorie assegnate all'articolo: dalla richiesta appena inviata se stiamo ridisegnando il
// form dopo un errore di validazione (altrimenti si perderebbero le checkbox appena spuntate),
// altrimenti quelle già salvate nel database.
$selectedCategoryIds = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_filter(array_map('intval', $_POST['category_ids'] ?? []))
    : array_column(getBlogPostCategories($id), 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $albumId = (int) ($_POST['album_id'] ?? 0) ?: null;
    $tagsRaw = trim($_POST['tags'] ?? '');
    $tags = $tagsRaw !== '' ? implode(', ', array_filter(array_map('trim', explode(',', $tagsRaw)), fn ($t) => $t !== '')) : null;
    $tags = $tags !== '' ? $tags : null;
    $categoryIds = $selectedCategoryIds;
    $publishedAt = parseLocalDateTime($_POST['published_at'] ?? '', $profile, browserTzOffsetFromRequest()) ?: $post['published_at'];

    if ($title === '' || $content === '') {
        $error = 'Titolo e contenuto sono obbligatori.';
        // Ridisegna il form con quanto appena scritto invece dei vecchi valori del database.
        $post = array_merge($post, ['title' => $title, 'content' => $content, 'album_id' => $albumId, 'tags' => $tags, 'published_at' => $publishedAt]);
    } else {
        // Verifica che album e categorie appartengano davvero a questo utente, per evitare che un
        // ID arbitrario nel form li associ a roba di qualcun altro (stesso controllo di dashboard_blog.php).
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
        if (!$coverPath) {
            $coverPath = $post['cover_path'];
        } else {
            deleteCoverFile($post['cover_path']);
        }

        // Lo slug (e quindi la parte finale del permalink) non cambia mai in modifica, anche se
        // il titolo cambia: un link già condiviso deve continuare a funzionare.
        $stmt = getDB()->prepare('UPDATE blog_posts SET title=?, excerpt=?, content=?, cover_path=?, album_id=?, tags=?, published_at=? WHERE id=? AND user_id=?');
        $stmt->execute([$title, $excerpt, $content, $coverPath, $albumId, $tags, $publishedAt, $id, $profile['id']]);
        getDB()->prepare('DELETE FROM blog_post_categories WHERE post_id=?')->execute([$id]);
        if ($categoryIds) {
            $insCat = getDB()->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (?,?)');
            foreach ($categoryIds as $cid) {
                $insCat->execute([$id, $cid]);
            }
        }
        header('Location: /dashboard_blog.php');
        exit;
    }
}

include __DIR__ . '/_dash_header.php';
?>
  <p><a href="/dashboard_blog.php"><i class="fa-solid fa-arrow-left"></i> Torna al blog</a></p>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="section-title">Modifica articolo</div>
  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
    <input type="hidden" name="tz_offset_minutes" value="">
    <label>Titolo post</label>
    <input type="text" name="title" value="<?= e($post['title']) ?>" required>
    <label>Contenuto</label>
    <textarea name="content" rows="16" required><?= e($post['content']) ?></textarea>

    <?php if ($post['cover_path']): ?>
      <label>Copertina attuale</label>
      <img src="/<?= e($post['cover_path']) ?>" style="width:120px;height:120px;border-radius:8px;object-fit:cover;display:block;margin-bottom:10px;">
    <?php endif; ?>
    <label>Nuova copertina (opzionale — lascia vuoto per non cambiarla)</label>
    <input type="file" name="cover" accept="image/*">

    <label>Album collegato (opzionale)</label>
    <select name="album_id">
      <option value="">— Nessun album —</option>
      <?php foreach ($albums as $al): ?>
        <option value="<?= (int) $al['id'] ?>" <?= (int) ($post['album_id'] ?? 0) === (int) $al['id'] ? 'selected' : '' ?>><?= e($al['title']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Tag (opzionale, separati da virgola)</label>
    <input type="text" name="tags" value="<?= e($post['tags'] ?? '') ?>" placeholder="es. concerti, novità, napoli">

    <?php if ($categories): ?>
      <label>Categorie (opzionale, puoi sceglierne più di una)</label>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px;margin-bottom:14px;">
        <?php foreach ($categories as $cat): ?>
          <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
            <input type="checkbox" name="category_ids[]" value="<?= (int) $cat['id'] ?>" style="width:auto;" <?= in_array((int) $cat['id'], $selectedCategoryIds, true) ? 'checked' : '' ?>>
            <?= e($cat['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:var(--text-muted);font-size:12.5px;">Non hai ancora nessuna categoria — creane una dalla pagina Blog per poterla assegnare qui.</p>
    <?php endif; ?>

    <label>Data di pubblicazione</label>
    <input type="datetime-local" name="published_at" value="<?= e(date('Y-m-d\TH:i', strtotime($post['published_at']))) ?>">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Impostala nel futuro per (ri)programmare l'articolo.</p>

    <button type="submit" class="btn">Salva modifiche</button>
    <a href="/dashboard_blog.php" class="btn secondary" style="margin-left:8px;">Annulla</a>
  </form>
<?php include __DIR__ . '/_dash_footer.php'; ?>
