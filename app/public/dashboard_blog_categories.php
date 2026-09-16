<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Categorie del blog';
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
    }
    if (!$error) {
        header('Location: /dashboard_blog_categories.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM blog_categories WHERE user_id=? ORDER BY name ASC');
$stmt->execute([$profile['id']]);
$categories = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <p><a href="/dashboard_blog.php"><i class="fa-solid fa-arrow-left"></i> Torna al blog</a></p>

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
<?php include __DIR__ . '/_dash_footer.php'; ?>
