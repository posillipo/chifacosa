<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Blog';

$stmt = getDB()->prepare('SELECT COUNT(*) c FROM blog_categories WHERE user_id=?');
$stmt->execute([$profile['id']]);
$categoriesCount = (int) $stmt->fetch()['c'];

$tagsCount = count(getBlogTagCounts((int) $profile['id']));

$stmt = getDB()->prepare('SELECT COUNT(*) c FROM blog_posts WHERE user_id=?');
$stmt->execute([$profile['id']]);
$postsCount = (int) $stmt->fetch()['c'];

include __DIR__ . '/../_dash_header.php';
require_once __DIR__ . '/_studio_blog.php';
$bc = getDB()->prepare('SELECT (SELECT COUNT(*) FROM blog_posts WHERE user_id=?) p, (SELECT COUNT(*) FROM blog_categories WHERE user_id=?) c');
$bc->execute([$profile['id'], $profile['id']]);
$bcr = $bc->fetch();
$blogCounts = ['posts' => (int) $bcr['p'], 'cats' => (int) $bcr['c'], 'tags' => count(getBlogTagCounts((int) $profile['id']))];
$stmt = getDB()->prepare('SELECT id, title, slug, published_at, cover_path, in_feed FROM blog_posts WHERE user_id=? ORDER BY published_at DESC, id DESC LIMIT 5');
$stmt->execute([$profile['id']]);
$recent = $stmt->fetchAll();
$schedN = (int) getDB()->query("SELECT COUNT(*) FROM blog_posts WHERE user_id=" . (int) $profile['id'] . " AND published_at > NOW()")->fetchColumn();
studioBlogHead('hub', $blogCounts);
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_blog_posts.php"><b><?= $blogCounts['posts'] ?></b><span>Articoli</span></a>
      <div class="tlx-kpi"><b><?= $schedN ?></b><span>Programmati</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_blog_categories.php"><b><?= $blogCounts['cats'] ?></b><span>Categorie</span></a>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_blog_tags.php"><b><?= $blogCounts['tags'] ?></b><span>Tag</span></a>
    </div>
    <div class="tlx-listhead"><h2>Ultimi articoli</h2><a href="/dashboard_blog_posts.php">Vedi tutti →</a></div>
    <?php if (!$recent): ?>
      <div class="tlx-empty"><i class="fa-regular fa-newspaper"></i><b>Il tuo blog è ancora vuoto</b><span>Scrivi il primo articolo: comparirà qui e nel Feed.</span><a class="blx-new" href="/dashboard_blog_new.php"><i class="fa-solid fa-plus"></i> Scrivi un articolo</a></div>
    <?php endif; ?>
    <?php foreach ($recent as $r): $sc = strtotime($r['published_at']) > time(); ?>
      <article class="stc stc-list">
        <a class="stc-thumb" href="/dashboard_blog_edit.php?id=<?= (int) $r['id'] ?>"><?php if ($r['cover_path']): ?><img src="/<?= e($r['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="stc-ph"><?= e(mb_strtoupper(mb_substr($r['title'], 0, 1))) ?></span><?php endif; ?></a>
        <div class="stc-body">
          <div class="stc-meta1"><span class="stc-type stc-violet">Articolo</span>
            <?php if ($sc): ?><span class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> Programmato · <?= e(formatLocalDateTime($r['published_at'], $profile)) ?></span><?php else: ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Pubblicato</span><?php endif; ?></div>
          <h3 class="stc-title"><a href="/dashboard_blog_edit.php?id=<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a></h3>
          <div class="stc-meta2"><?= e(formatLocalDateTime($r['published_at'], $profile)) ?></div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
