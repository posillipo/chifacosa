<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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

include __DIR__ . '/_dash_header.php';
?>
  <p><a href="/dashboard_blog.php"><i class="fa-solid fa-arrow-left"></i> Torna al blog</a></p>

  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      I tag sono etichette libere assegnate ai singoli articoli — a differenza delle categorie
      non vanno create prima: basta scriverle quando scrivi o modifichi un articolo. Qui trovi
      tutti quelli attualmente in uso: puoi rinominarli (cambia ovunque compaiano) o eliminarli.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="section-title">Tag (<?= count($tagCounts) ?>)</div>
  <?php if (!$tagCounts): ?>
    <div class="alert error">Nessun tag ancora — aggiungine scrivendo o modificando un articolo.</div>
  <?php else: ?>
    <?php foreach ($tagCounts as $tag => $count): ?>
      <div class="link-item">
        <div>
          <strong><?= e($tag) ?></strong>
          <span style="color:var(--text-muted);font-size:12.5px;"> · <?= $count ?> articol<?= $count === 1 ? 'o' : 'i' ?></span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <form method="post" style="display:flex;gap:6px;align-items:center;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="rename_tag">
            <input type="hidden" name="tag" value="<?= e($tag) ?>">
            <input type="text" name="new_name" placeholder="Nuovo nome" style="margin-bottom:0;width:140px;" required>
            <button type="submit" class="btn small secondary">Rinomina</button>
          </form>
          <form method="post" onsubmit="return confirm('Eliminare il tag &quot;<?= e($tag) ?>&quot; da tutti gli articoli?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_tag">
            <input type="hidden" name="tag" value="<?= e($tag) ?>">
            <button type="submit" class="btn small danger">Elimina</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
