<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Articoli del blog';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path, cover_video_path FROM blog_posts WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            require_once __DIR__ . '/../../src/videos.php';
            blogCoverDeleteAll($row);
            if (empty($row['cover_video_path'])) {
                deleteCoverFile($row['cover_path']);
            }
        }
        getDB()->prepare('DELETE FROM blog_posts WHERE id=? AND user_id=?')->execute([$id, $profile['id']]);
        try {
            getDB()->prepare("DELETE FROM social_queue WHERE user_id=? AND content_type='blog' AND content_id=? AND status IN ('pending','processing')")->execute([$profile['id'], $id]);
        } catch (Throwable $e) {
        }
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

// Stato nella coda social per ogni articolo (ultima riga per articolo): così si vede subito se è
// già in coda o già programmato su Metricool, anche quando l'ha inserito un'IA.
$queueByPost = [];
if ($posts) {
    $ids = array_map(fn ($p) => (int) $p['id'], $posts);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $qs = getDB()->prepare("SELECT content_id, status, scheduled_for FROM social_queue WHERE user_id=? AND content_type='blog' AND content_id IN ($in) ORDER BY id ASC");
    $qs->execute(array_merge([$profile['id']], $ids));
    foreach ($qs->fetchAll() as $r) {
        $queueByPost[(int) $r['content_id']] = $r; // l'ultima riga prevale
    }
}
$queueLabels = [
    'pending'    => ['In coda social', '#6c5ce7', 'fa-hourglass-half'],
    'processing' => ['In elaborazione', '#6c5ce7', 'fa-gear'],
    'scheduled'  => ['Programmato su Metricool', '#00a65a', 'fa-circle-check'],
    'done'       => ['Programmato su Metricool', '#00a65a', 'fa-circle-check'],
    'failed'     => ['Coda social: errore', '#dd4b39', 'fa-triangle-exclamation'],
    'skipped'    => ['Coda social: saltato', '#6c757d', 'fa-forward'],
];

include __DIR__ . '/../_dash_header.php';
require_once __DIR__ . '/_studio_blog.php';
$bc = getDB()->prepare('SELECT (SELECT COUNT(*) FROM blog_posts WHERE user_id=?) p, (SELECT COUNT(*) FROM blog_categories WHERE user_id=?) c');
$bc->execute([$profile['id'], $profile['id']]);
$bcr = $bc->fetch();
$blogCounts = ['posts' => (int) $bcr['p'], 'cats' => (int) $bcr['c'], 'tags' => count(getBlogTagCounts((int) $profile['id']))];
studioBlogHead('posts', $blogCounts);
$filtered = ($q !== '' || $categoryId > 0);
?>
  <form method="get" class="blx-toolbar">
    <div class="blx-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cerca per titolo..."></div>
    <?php if ($categories): ?>
      <select name="category" aria-label="Categoria">
        <option value="0">Tutte le categorie</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <label class="tlx-switch"><input type="checkbox" name="in_content" value="1" <?= $inContent ? 'checked' : '' ?>><i></i><span>Cerca anche nel testo</span></label>
    <button type="submit" class="tlx-chip">Cerca</button>
    <?php if ($filtered): ?><a href="/dashboard_blog_posts.php" class="tlx-chip">Azzera</a><?php endif; ?>
  </form>

  <div class="tlx-listhead"><h2><?= $filtered ? 'Risultati' : 'Tutti gli articoli' ?> <small>(<?= count($posts) ?>)</small></h2></div>
  <?php if (!$posts): ?>
    <div class="tlx-empty"><i class="fa-regular fa-newspaper"></i><b><?= $filtered ? 'Nessun articolo trovato' : 'Non hai ancora scritto nessun articolo' ?></b><span><?= $filtered ? 'Prova con altre parole o azzera i filtri.' : 'Scrivi il primo: comparirà qui e nel Feed.' ?></span></div>
  <?php endif; ?>
  <?php foreach ($posts as $p):
    $isScheduled = strtotime($p['published_at']) > time();
    $pCats = array_filter($categories, fn ($c) => in_array((int) $c['id'], $postCategoryIds[(int) $p['id']], true));
    $pubUrl = blogPostUrl($profile['slug'], $p);
    $share = json_encode(['t' => $p['title'], 'u' => siteUrl($pubUrl)], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
  ?>
    <article class="stc stc-list blx-post">
      <a class="stc-thumb" href="/dashboard_blog_edit.php?id=<?= (int) $p['id'] ?>"><?php if ($p['cover_path']): ?><img src="/<?= e($p['cover_path']) ?>" alt="" loading="lazy"><?php if (!empty($p['cover_video_path'])): ?><i class="fa-solid fa-circle-play blx-play"></i><?php endif; ?><?php else: ?><span class="stc-ph"><?= e(mb_strtoupper(mb_substr($p['title'], 0, 1))) ?></span><?php endif; ?></a>
      <div class="stc-body">
        <div class="stc-meta1">
          <span class="stc-type stc-violet">Articolo</span>
          <?php if ($isScheduled): ?><span class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> Programmato · <?= e(formatLocalDateTime($p['published_at'], $profile)) ?></span>
          <?php else: ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Pubblicato</span><?php endif; ?>
          <?php if (!(int) ($p['in_feed'] ?? 1)): ?><span class="tlx-tag">Fuori dal Feed</span><?php endif; ?>
          <?php if (isset($queueByPost[(int) $p['id']])): $qr = $queueByPost[(int) $p['id']]; $ql = $queueLabels[$qr['status']] ?? [$qr['status'], '', 'fa-share-nodes']; ?>
            <span class="tlx-tag" title="Coda social"><i class="fa-solid <?= e($ql[2]) ?>"></i> <?= e($ql[0]) ?><?= !empty($qr['scheduled_for']) ? ' · ' . e(formatLocalDateTime($qr['scheduled_for'], $profile)) : '' ?></span>
          <?php endif; ?>
          <?php if (!empty($p['created_by'])): ?><span class="tlx-tag" title="Autore (visibile solo qui)"><i class="fa-solid fa-pen-nib"></i> <?= e($p['created_by']) ?></span><?php endif; ?>
        </div>
        <h3 class="stc-title"><a href="/dashboard_blog_edit.php?id=<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a></h3>
        <p class="blx-exc"><?= e($p['excerpt'] ?: textExcerpt($p['content'])) ?></p>
        <?php if ($pCats || $p['tags']): ?>
          <div class="blx-chips"><?php foreach ($pCats as $c): ?><a href="/dashboard_blog_posts.php?category=<?= (int) $c['id'] ?>" class="blx-cat"><?= e($c['name']) ?></a><?php endforeach; ?>
            <?php if ($p['tags']): ?><span class="blx-tg"><i class="fa-solid fa-tags"></i> <?= e($p['tags']) ?></span><?php endif; ?></div>
        <?php endif; ?>
        <div class="stc-actions">
          <a class="stc-act primary" href="/dashboard_blog_edit.php?id=<?= (int) $p['id'] ?>"><i class="fa-solid fa-pen"></i> <span>Modifica</span></a>
          <a class="stc-act" href="<?= e($pubUrl) ?>" target="_blank" title="Vedi online"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Vedi online</span></a>
          <button type="button" class="stc-act" data-share='<?= e($share) ?>' title="Condividi"><i class="fa-solid fa-share-nodes"></i> <span>Condividi</span></button>
          <?php if (!empty($dashProfile['metricool_auto_social']) && !isset($queueByPost[(int) $p['id']])): ?>
            <?php $sqJson = json_encode(['suggest' => true, 'type' => 'blog', 'id' => (int) $p['id'], 'title' => $p['title'], 'img' => $p['cover_path'] ? siteUrl('/' . ltrim($p['cover_path'], '/')) : null, 'url' => siteUrl($pubUrl)]); ?>
            <button type="button" class="stc-act" data-sq='<?= e($sqJson) ?>' title="Metti in coda social"><i class="fa-solid fa-paper-plane"></i> <span>In coda social</span></button>
          <?php endif; ?>
          <form method="post" data-confirm="Eliminare questo articolo?" class="blx-del">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="in_content" value="<?= $inContent ? 1 : 0 ?>"><input type="hidden" name="category" value="<?= $categoryId ?>">
            <button type="submit" class="stc-act danger" title="Elimina"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button>
          </form>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
