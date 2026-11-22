<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
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

include __DIR__ . '/../_dash_header.php';
require_once __DIR__ . '/_studio_blog.php';
$bc = getDB()->prepare('SELECT (SELECT COUNT(*) FROM blog_posts WHERE user_id=?) p, (SELECT COUNT(*) FROM blog_categories WHERE user_id=?) c');
$bc->execute([$profile['id'], $profile['id']]);
$bcr = $bc->fetch();
$blogCounts = ['posts' => (int) $bcr['p'], 'cats' => (int) $bcr['c'], 'tags' => count(getBlogTagCounts((int) $profile['id']))];
$cnt = [];
$q2 = getDB()->prepare('SELECT bpc.category_id, COUNT(*) n FROM blog_post_categories bpc JOIN blog_categories bc ON bc.id = bpc.category_id WHERE bc.user_id=? GROUP BY bpc.category_id');
$q2->execute([$profile['id']]);
foreach ($q2->fetchAll() as $r) { $cnt[(int) $r['category_id']] = (int) $r['n']; }
studioBlogHead('cats', $blogCounts);
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="blx-add">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_category">
    <input type="text" name="name" required maxlength="80" placeholder="Nuova categoria (es. Concerti, Novità, Dietro le quinte)" aria-label="Nome della nuova categoria">
    <button type="submit" class="blx-new"><i class="fa-solid fa-plus"></i> <span>Aggiungi</span></button>
  </form>
  <?php if (!$categories): ?>
    <div class="tlx-empty"><i class="fa-regular fa-folder-open"></i><b>Nessuna categoria ancora</b><span>Creane una qui sopra per poterla assegnare agli articoli.</span></div>
  <?php endif; ?>
  <div class="blx-grid">
    <?php foreach ($categories as $cat): $n = $cnt[(int) $cat['id']] ?? 0; ?>
      <div class="blx-card">
        <a href="/dashboard_blog_posts.php?category=<?= (int) $cat['id'] ?>" class="blx-card-main"><span class="blx-ico"><i class="fa-solid fa-folder"></i></span><b><?= e($cat['name']) ?></b><small><?= $n ?> articol<?= $n === 1 ? 'o' : 'i' ?></small></a>
        <form method="post" data-confirm="Eliminare la categoria «<?= e($cat['name']) ?>»? Gli articoli restano, perdono solo questa categoria.">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
          <button type="submit" class="blx-x" aria-label="Elimina categoria"><i class="fa-solid fa-trash"></i></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
