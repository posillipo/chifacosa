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

    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if ($title === '' || $content === '') {
            $error = 'Titolo e contenuto sono obbligatori.';
        } else {
            $slug = generateUniquePostSlug((int)$profile['id'], $title);
            $excerpt = textExcerpt($content, 200);
            $coverPath = handleCoverUpload($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO blog_posts (user_id, title, slug, excerpt, content, cover_path) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$profile['id'], $title, $slug, $excerpt, $content, $coverPath]);

            $postUrl = siteUrl(blogPostUrl($profile['slug'], ['published_at' => date('Y-m-d H:i:s'), 'slug' => $slug]));
            notifyFollowersNewContent((int)$profile['id'], $profile['display_name'], $profile['slug'], 'blog', $title, $postUrl);
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

$stmt = getDB()->prepare('SELECT * FROM blog_posts WHERE user_id=? ORDER BY published_at DESC');
$stmt->execute([$profile['id']]);
$posts = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <label>Titolo post</label>
    <input type="text" name="title" required>
    <label>Contenuto</label>
    <textarea name="content" rows="6" required></textarea>
    <label>Copertina quadrata (opzionale, jpg/png/webp — usata anche come immagine di anteprima quando condividi il link)</label>
    <input type="file" name="cover" accept="image/*">
    <button type="submit" class="btn">Pubblica</button>
  </form>

  <div class="section-title">I tuoi post (<?= count($posts) ?>)</div>
  <?php foreach ($posts as $p): ?>
    <div class="blog-item" style="display:flex;gap:14px;align-items:flex-start;">
      <?php if ($p['cover_path']): ?>
        <img src="/<?= e($p['cover_path']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div class="date"><?= date('d/m/Y', strtotime($p['published_at'])) ?></div>
        <strong><?= e($p['title']) ?></strong>
        <p style="color:var(--text-muted)"><?= nl2br(e($p['content'])) ?></p>
        <p><a href="<?= e(blogPostUrl($profile['slug'], $p)) ?>" target="_blank"><?= e(siteName()) ?><?= e(blogPostUrl($profile['slug'], $p)) ?> ↗</a></p>
        <form method="post" onsubmit="return confirm('Eliminare questo post?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn small danger" type="submit">Elimina</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
