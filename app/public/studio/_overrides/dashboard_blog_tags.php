<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Tag del blog';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $tag = trim($_POST['tag'] ?? '');

    if ($tag === '') {
        $error = 'Tag non valido.';
    } else {
        $stmt = getDB()->prepare("SELECT id, tags FROM blog_posts WHERE user_id=? AND tags IS NOT NULL AND tags <> ''");
        $stmt->execute([$profile['id']]);
        $posts = $stmt->fetchAll();

        if ($action === 'delete_tag') {
            foreach ($posts as $p) {
                $list = array_filter(array_map('trim', explode(',', $p['tags'])));
                if (in_array($tag, $list, true)) {
                    $newList = array_values(array_diff($list, [$tag]));
                    $newTags = $newList ? implode(', ', $newList) : null;
                    getDB()->prepare('UPDATE blog_posts SET tags=? WHERE id=?')->execute([$newTags, $p['id']]);
                }
            }
        } elseif ($action === 'rename_tag') {
            $newName = trim($_POST['new_name'] ?? '');
            if ($newName === '') {
                $error = 'Inserisci il nuovo nome del tag.';
            } else {
                foreach ($posts as $p) {
                    $list = array_filter(array_map('trim', explode(',', $p['tags'])));
                    if (in_array($tag, $list, true)) {
                        $list = array_diff($list, [$tag]);
                        $list[] = $newName;
                        // Rimuove eventuali duplicati (es. il post aveva già anche il tag col nuovo nome).
                        $list = array_unique($list);
                        getDB()->prepare('UPDATE blog_posts SET tags=? WHERE id=?')->execute([implode(', ', $list), $p['id']]);
                    }
                }
            }
        }
    }
    if (!$error) {
        header('Location: /dashboard_blog_tags.php');
        exit;
    }
}

$tagCounts = getBlogTagCounts((int) $profile['id']);

include __DIR__ . '/../_dash_header.php';
require_once __DIR__ . '/_studio_blog.php';
$bc = getDB()->prepare('SELECT (SELECT COUNT(*) FROM blog_posts WHERE user_id=?) p, (SELECT COUNT(*) FROM blog_categories WHERE user_id=?) c');
$bc->execute([$profile['id'], $profile['id']]);
$bcr = $bc->fetch();
$blogCounts = ['posts' => (int) $bcr['p'], 'cats' => (int) $bcr['c'], 'tags' => count(getBlogTagCounts((int) $profile['id']))];
studioBlogHead('tags', $blogCounts);
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$tagCounts): ?>
    <div class="tlx-empty"><i class="fa-solid fa-tags"></i><b>Nessun tag ancora</b><span>Aggiungili scrivendo o modificando un articolo.</span></div>
  <?php endif; ?>
  <div class="blx-taglist">
    <?php foreach ($tagCounts as $tag => $count): ?>
      <div class="blx-tagrow">
        <div class="blx-tagname"><b>#<?= e($tag) ?></b><small><?= $count ?> articol<?= $count === 1 ? 'o' : 'i' ?></small></div>
        <form method="post" class="blx-rename">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="rename_tag"><input type="hidden" name="tag" value="<?= e($tag) ?>">
          <input type="text" name="new_name" placeholder="Nuovo nome" required aria-label="Nuovo nome per <?= e($tag) ?>">
          <button type="submit" class="stc-act">Rinomina</button>
        </form>
        <form method="post" data-confirm="Eliminare il tag «<?= e($tag) ?>» da tutti gli articoli?">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_tag"><input type="hidden" name="tag" value="<?= e($tag) ?>">
          <button type="submit" class="stc-act danger" title="Elimina"><i class="fa-solid fa-trash"></i></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
