<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Articoli del blog';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM blog_posts WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
        }
        getDB()->prepare('DELETE FROM blog_posts WHERE id=? AND user_id=?')->execute([$id, $profile['id']]);
    }
    // Riporta alla stessa ricerca/filtro che si stava usando, invece di azzerarla dopo ogni eliminazione.
    $backQs = (trim($_POST['q'] ?? '') !== '' || (int) ($_POST['category'] ?? 0) > 0)
        ? '?' . http_build_query(['q' => trim($_POST['q']), 'in_content' => !empty($_POST['in_content']) ? 1 : 0, 'category' => (int) ($_POST['category'] ?? 0)])
        : '';
    header('Location: /dashboard_blog_posts.php' . $backQs);
    exit;
}

$q = trim($_GET['q'] ?? '');
$inContent = !empty($_GET['in_content']);
$categoryId = (int) ($_GET['category'] ?? 0);

$stmt = getDB()->prepare('SELECT * FROM blog_categories WHERE user_id=? ORDER BY name ASC');
$stmt->execute([$profile['id']]);
$categories = $stmt->fetchAll();
// Filtro per categoria non valida (di un altro profilo, o cancellata nel frattempo): si ignora
// invece di restituire un elenco vuoto senza spiegazione.
if ($categoryId > 0 && !in_array($categoryId, array_column($categories, 'id'), true)) {
    $categoryId = 0;
}

$sql = 'SELECT * FROM blog_posts WHERE user_id=?';
$params = [$profile['id']];
if ($q !== '') {
    // Sfugge i caratteri jolly di LIKE (%, _) perché digitati nella ricerca vanno trattati come
    // testo letterale, non come wildcard — altrimenti una ricerca con "%" nel titolo si
    // comporterebbe in modo sorprendente.
    $likeTerm = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    if ($inContent) {
        $sql .= " AND (title LIKE ? ESCAPE '\\\\' OR content LIKE ? ESCAPE '\\\\')";
        $params[] = $likeTerm;
        $params[] = $likeTerm;
    } else {
        $sql .= " AND title LIKE ? ESCAPE '\\\\'";
        $params[] = $likeTerm;
    }
}
if ($categoryId > 0) {
    $sql .= ' AND EXISTS (SELECT 1 FROM blog_post_categories bpc WHERE bpc.post_id = blog_posts.id AND bpc.category_id = ?)';
    $params[] = $categoryId;
}
$sql .= ' ORDER BY published_at DESC';
$stmt = getDB()->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$postCategoryIds = [];
foreach ($posts as $p) {
    $postCategoryIds[(int) $p['id']] = array_column(getBlogPostCategories((int) $p['id']), 'id');
}

include __DIR__ . '/_dash_header.php';
?>
  <p><a href="/dashboard_blog.php"><i class="fa-solid fa-arrow-left"></i> Torna al blog</a></p>

  <form method="get" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cerca per titolo..." style="flex:1;min-width:180px;margin-bottom:0;">
    <?php if ($categories): ?>
      <select name="category" style="width:auto;margin-bottom:0;">
        <option value="0">Tutte le categorie</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;white-space:nowrap;">
      <input type="checkbox" name="in_content" value="1" style="width:auto;" <?= $inContent ? 'checked' : '' ?>>
      Ricerca avanzata: cerca anche nel testo
    </label>
    <button type="submit" class="btn" style="width:auto;">Cerca</button>
    <?php if ($q !== '' || $categoryId > 0): ?><a href="/dashboard_blog_posts.php" class="btn small secondary">Azzera</a><?php endif; ?>
  </form>

  <div class="section-title">
    <?= ($q !== '' || $categoryId > 0) ? 'Risultati (' . count($posts) . ')' : 'Tutti gli articoli (' . count($posts) . ')' ?>
  </div>
  <?php if (!$posts): ?>
    <div class="alert error"><?= ($q !== '' || $categoryId > 0) ? 'Nessun articolo trovato per questa ricerca/filtro.' : 'Non hai ancora scritto nessun articolo.' ?></div>
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
        <?php if (!(int) ($p['in_feed'] ?? 1)): ?>
          <span style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">Non nel Feed</span>
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
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <input type="hidden" name="in_content" value="<?= $inContent ? 1 : 0 ?>">
            <input type="hidden" name="category" value="<?= $categoryId ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
