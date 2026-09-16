<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Gestisci qui il blog: organizza categorie e tag, scrivi un nuovo articolo, oppure vai
      all'elenco completo per cercare, modificare o eliminare quelli già pubblicati.
    </p>
  </details>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;">
    <a href="/dashboard_blog_categories.php" class="card" style="display:block;text-decoration:none;color:inherit;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <span style="width:40px;height:40px;border-radius:10px;background:rgba(108,92,231,0.12);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">
          <i class="fa-solid fa-folder"></i>
        </span>
        <strong>Categorie</strong>
      </div>
      <p style="margin:0;color:var(--text-muted);font-size:13.5px;"><?= $categoriesCount ?> categori<?= $categoriesCount === 1 ? 'a' : 'e' ?></p>
    </a>
    <a href="/dashboard_blog_tags.php" class="card" style="display:block;text-decoration:none;color:inherit;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <span style="width:40px;height:40px;border-radius:10px;background:rgba(108,92,231,0.12);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">
          <i class="fa-solid fa-tags"></i>
        </span>
        <strong>Tag</strong>
      </div>
      <p style="margin:0;color:var(--text-muted);font-size:13.5px;"><?= $tagsCount ?> tag</p>
    </a>
    <a href="/dashboard_blog_posts.php" class="card" style="display:block;text-decoration:none;color:inherit;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <span style="width:40px;height:40px;border-radius:10px;background:rgba(108,92,231,0.12);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">
          <i class="fa-solid fa-newspaper"></i>
        </span>
        <strong>Tutti gli articoli</strong>
      </div>
      <p style="margin:0;color:var(--text-muted);font-size:13.5px;"><?= $postsCount ?> articol<?= $postsCount === 1 ? 'o' : 'i' ?></p>
    </a>
    <a href="/dashboard_blog_new.php" class="card" style="display:block;text-decoration:none;color:inherit;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <span style="width:40px;height:40px;border-radius:10px;background:rgba(108,92,231,0.12);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">
          <i class="fa-solid fa-pen"></i>
        </span>
        <strong>Nuovo articolo</strong>
      </div>
      <p style="margin:0;color:var(--text-muted);font-size:13.5px;">Scrivi un nuovo post</p>
    </a>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
