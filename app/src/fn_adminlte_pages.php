<?php
// Tema AdminLTE: pagine pubbliche complete (blog, Che Amo, eventi, video, menu...).
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Pagina pubblica "Timeline" a tema AdminLTE — stesso principio "a scena" della Home (vedi
// renderAdminLteProfileTheme()), ma qui con lo scroll infinito reale della pagina Timeline del
// tema Colorful (timeline.php/timeline_more.php) invece della sola anteprima di 5 elementi
// mostrata nel tab Timeline della Home.
function renderAdminLteTimelinePage(array $artist, string $slug): string {
    $pageUrl = siteUrl('/' . $slug . '/timeline');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Timeline di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Timeline di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'timeline') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Timeline') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <?= renderAdminLteTimelineFeedBlock($artist, $slug) ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Singolo aggiornamento della Timeline" a tema AdminLTE — stesso principio "a
// scena" della Home. $post/$photos/$sameDayPosts arrivano già pronti da timeline_post.php (stessa
// forma usata dal tema Colorful): qui cambia solo il vestito, non le query. Il carosello foto
// (renderPhotoCarousel(), classi .ig-carousel/.ig-lightbox) è un componente a sé, con il proprio
// CSS/JS dedicato — funziona identico dentro la card AdminLTE.
function renderAdminLteTimelinePostPage(array $post, array $artist, string $slug, array $photos, array $sameDayPosts, bool $isOwner = false, bool $isScheduledFuture = false, bool $isPreview = false): string {
    $pageUrl = siteUrl('/' . $slug . '/timeline/' . (int) $post['id']);
    if ($post['visibility'] === 'private' || $isScheduledFuture) {
        $pageUrl = withPreviewToken($pageUrl, 'pensiero', (int) $post['id']);
    }
    $avatarUrl = adminLteAvatarUrl($artist);
    $ogImagePath = (count($photos) > 1 && $post['image_path']) ? getFeedShareImage($post['image_path']) : $post['image_path'];
    $ogImage = $ogImagePath ? siteUrl($ogImagePath) : ($post['avatar_path'] ? siteUrl($post['avatar_path']) : null);
    $anteprima = $post['testo'] ? textExcerpt($post['testo'], 150) : ('Nuovo aggiornamento su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!empty($post['redirect_link'])) {
    emitPostRedirectLink($post['redirect_link']);
} else {
    emitCustomFeedLinkRedirect($post['custom_feed_guid'], $post['custom_feed_guid_since'], $post['created_at']);
} ?>
<title><?= e($post['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($anteprima) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($post['display_name']) ?> su <?= e(siteName()) ?>">
<meta property="og:description" content="<?= e($anteprima) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($post['display_name']) ?> su <?= e(siteName()) ?>">
<meta name="twitter:description" content="<?= e($anteprima) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if ($photos || $sameDayPosts): ?>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>">
<?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'timeline') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $post['display_name'], 'Aggiornamento', ['Timeline' => '/' . $slug . '/timeline']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Aggiornamento') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <?php if (($isOwner || $isPreview) && ($post['visibility'] === 'private' || $isScheduledFuture)): ?>
                  <div class="alert alert-warning">Questo aggiornamento non è visibile al pubblico al momento (Solo io, o programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
                <?php endif; ?>

                <div class="card mb-3">
                  <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <img src="<?= e($avatarUrl) ?>" class="rounded-circle" style="width:28px;height:28px;object-fit:cover;" alt="">
                      <span class="text-secondary small"><?= e($post['display_name']) ?> · <?= e(formatLocalDateTime($post['created_at'], $artist)) ?></span>
                    </div>
                    <?= renderPhotoCarousel($photos, (int) $post['id']) ?>
                    <?php if ($post['testo']): ?><p class="mb-0"><?= nl2br(e($post['testo'])) ?></p><?php endif; ?>
                  </div>
                </div>

                <?php if ($sameDayPosts): ?>
                  <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title h6 mb-0">Altri di questa giornata (<?= count($sameDayPosts) ?>)</h3></div>
                  </div>
                  <?php foreach ($sameDayPosts as $sp):
                    $spPhotos = array_values(array_filter(array_merge([$sp['image_path']], getTimelinePostPhotos((int) $sp['id']))));
                  ?>
                  <div class="card mb-3">
                    <div class="card-body">
                      <?= renderPhotoCarousel($spPhotos, (int) $sp['id']) ?>
                      <small class="text-secondary"><?= e(formatLocalDateTime($sp['created_at'], $artist)) ?></small>
                      <?php if ($sp['testo']): ?><p class="mb-0 mt-1"><?= nl2br(e($sp['testo'])) ?></p><?php endif; ?>
                    </div>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>

                <a href="/<?= e($slug) ?>/timeline" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Torna alla Timeline</a>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if ($photos || $sameDayPosts): ?>
<script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script>
<?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Blog" (elenco) a tema AdminLTE — stesso principio "a scena" della Home, elenco
// completo (non un'anteprima) come la pagina Blog del tema Colorful (blog_index.php).
function renderAdminLteBlogIndexPage(array $artist, string $slug, array $posts, array $filter = ['q' => '', 'cats' => []], ?int $total = null): string {
    $pageUrl = siteUrl('/' . $slug . '/blog');
    $avatarUrl = adminLteAvatarUrl($artist);
    $pageSize = 20;
    $finished = count($posts) < $pageSize;
    // Descrizione SEO: cita gli articoli più recenti quando ce ne sono, altrimenti resta generica
    // — sempre meglio del "Blog di X" ripetuto identico su ogni profilo, che Google tratterebbe
    // come contenuto duplicato tra loro.
    $blogDescription = $posts
        ? 'Blog di ' . $artist['display_name'] . ': ' . textExcerpt(implode(', ', array_column(array_slice($posts, 0, 5), 'title')), 155)
        : 'Il blog di ' . $artist['display_name'] . ' su ' . siteName() . '.';
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Blog di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($blogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Blog di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($blogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= blogListJsonLd($pageUrl, 'Blog di ' . $artist['display_name'], $blogDescription, $posts, $slug) ?>
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'blog') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Blog') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug, false, renderAdminLteBlogFilterCard((int) $artist['id'], $slug, $filter, $total)) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, renderAdminLteBlogCategoriesNavCard((int) $artist['id'], $slug)) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Blog') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

                <?php if (!$posts): ?>
                  <div class="card"><div class="card-body text-secondary"><?= ($filter['q'] !== '' || $filter['cats']) ? 'Nessun articolo trovato con questi filtri.' : 'Nessun articolo pubblicato ancora.' ?></div></div>
                <?php else: ?>
                  <div id="adminlte-list-feed"><?= renderAdminLteBlogRows($posts, $slug, $artist) ?></div>
                  <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
                  <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
                  <div id="adminlte-list-sentinel" style="height:1px;"></div>
                  <?= adminLteInfiniteScrollScript('blog', $slug, count($posts), $pageSize, $finished, blogFilterQueryString($filter)) ?>
                <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Categoria del blog" a tema AdminLTE — stesso elenco della pagina Blog
// (renderAdminLteBlogRows()), filtrato per una categoria. Niente scroll infinito qui (a
// differenza del Blog intero): un elenco filtrato è normalmente già abbastanza corto da non
// servire, evitando di dover insegnare anche a adminlte_list_more.php a filtrare per categoria.
function renderAdminLteBlogCategoryPage(array $artist, string $slug, array $category, array $posts): string {
    $pageUrl = siteUrl(blogCategoryUrl($slug, $category));
    $categoryDescription = 'Articoli della categoria "' . $category['name'] . '" nel blog di ' . $artist['display_name'] . '.';
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($category['name']) ?> — Blog di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($categoryDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($category['name']) ?> — Blog di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($categoryDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= blogListJsonLd($pageUrl, $category['name'] . ' — Blog di ' . $artist['display_name'], $categoryDescription, $posts, $slug) ?>
<?= breadcrumbJsonLd([
    ['name' => $artist['display_name'], 'url' => siteUrl('/' . $slug)],
    ['name' => 'Blog', 'url' => siteUrl('/' . $slug . '/blog')],
    ['name' => $category['name'], 'url' => $pageUrl],
]) ?>
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'blog') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $category['name'], ['Blog' => '/' . $slug . '/blog']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, renderAdminLteBlogCategoriesNavCard((int) $artist['id'], $slug, (int) $category['id'])) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e($category['name']) ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

                <p class="mb-3"><a href="/<?= e($slug) ?>/blog"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Tutti gli articoli</a></p>

                <?php if (!$posts): ?>
                  <div class="card"><div class="card-body text-secondary">Nessun articolo in questa categoria ancora.</div></div>
                <?php else: ?>
                  <?= renderAdminLteBlogRows($posts, $slug, $artist) ?>
                <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Articolo del blog" a tema AdminLTE — stesso principio "a scena" della Home.
// $post contiene sia le colonne di blog_posts sia quelle di profiles/users già unite dalla query
// di blog_post.php (stessa forma usata dal tema Colorful); $artist è l'array "adattatore" già
// costruito lì per riusare le funzioni condivise (embedTrackingHead ecc.).
function renderAdminLteBlogPostPage(array $post, array $artist, string $slug, bool $isOwner = false, bool $isScheduledFuture = false, bool $isPreview = false): string {
    $permalink = siteUrl(blogPostUrl($slug, $post));
    if ($isScheduledFuture) {
        $permalink = withPreviewToken($permalink, 'blog', (int) $post['id']);
    }
    $ogImage = $post['cover_path'] ? siteUrl($post['cover_path']) : ($post['avatar_path'] ? siteUrl($post['avatar_path']) : null);
    $avatarUrl = adminLteAvatarUrl($artist);
    $postCategories = getBlogPostCategories((int) $post['id']);
    $linkedAlbum = null;
    if (!empty($post['album_id'])) {
        $albStmt = getDB()->prepare('SELECT id, title, cover_path FROM photo_albums WHERE id=? AND user_id=?');
        $albStmt->execute([$post['album_id'], $post['user_id']]);
        $linkedAlbum = $albStmt->fetch() ?: null;
    }

    // Da desktop la copertina si sposta nella colonna laterale destra, a piena altezza (niente
    // max-height/object-fit:cover che la ritagliava) — pensata apposta per le foto verticali
    // (4:5), che nella card centrale larga perdevano testa o piedi. Su mobile resta invece dov'era
    // sempre stata, dentro la card dell'articolo: le due colonne laterali lì sono impilate sotto
    // il contenuto, quindi spostarla lì avrebbe solo allungato la pagina senza risolvere nulla.
    $blogCoverSidebarHtml = '';
    // Con Academic la copertina sta già sopra il testo: niente seconda copia sotto la pagina.
    if (!academicSkinActive() && ($post['cover_path'] || !empty($post['cover_video_path']))) {
        ob_start();
        ?>
        <div class="card mb-3 d-none d-md-block">
          <?php if (!empty($post['cover_video_path'])): ?>
            <video src="/<?= e($post['cover_video_path']) ?>" <?= $post['cover_path'] ? 'poster="/' . e($post['cover_path']) . '"' : '' ?> controls playsinline preload="metadata" class="card-img-top" style="width:100%;height:auto;display:block;background:#000;"></video>
          <?php else: ?>
          <img src="/<?= e($post['cover_path']) ?>" alt="<?= e($post['title']) ?>" class="card-img-top" style="width:100%;height:auto;display:block;">
          <?php endif; ?>
        </div>
        <?php
        $blogCoverSidebarHtml = ob_get_clean();
    }

    // Ultimi articoli sopra la copertina, riferimento alla categoria (evidenziata nell'elenco
    // esistente) subito sotto — ordine richiesto esplicitamente da un utente per la colonna
    // laterale destra di questa pagina.
    $acadPost = academicSkinActive();
    $blogLatestPostsHtml = $acadPost ? '' : renderAdminLteBlogLatestPostsCard((int) $post['user_id'], $slug, (int) $post['id']);
    $activeCategoryId = $postCategories ? (int) $postCategories[0]['id'] : null;

    // Articoli correlati: gli ultimi articoli già pubblicati che condividono almeno una categoria
    // con questo (escluso lui stesso), con la stessa griglia di miniature di "Ultimi articoli".
    // Senza categorie, o senza altri articoli nella stessa categoria, la card non compare.
    $relatedPostsHtml = '';
    if ($postCategories && !$acadPost) {
        $catIds = array_map(fn($c) => (int) $c['id'], $postCategories);
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $stmt = getDB()->prepare("SELECT b.id, b.title, b.slug, b.cover_path, b.published_at FROM blog_posts b
            WHERE b.user_id = ? AND b.id <> ? AND b.published_at <= NOW()
              AND EXISTS (SELECT 1 FROM blog_post_categories pc WHERE pc.post_id = b.id AND pc.category_id IN ($ph))
            ORDER BY b.published_at DESC LIMIT 8");
        $stmt->execute(array_merge([(int) $post['user_id'], (int) $post['id']], $catIds));
        $relatedTiles = [];
        foreach ($stmt->fetchAll() as $rp) {
            $relatedTiles[] = [
                'url' => blogPostUrl($slug, $rp),
                'image' => $rp['cover_path'] ? '/' . $rp['cover_path'] : '',
                'placeholder_icon' => 'bi-newspaper',
                'title' => $rp['title'],
                'date' => formatItalianDate($rp['published_at'], $artist),
            ];
        }
        if ($relatedTiles && academicSkinActive()) {
            $single = count($postCategories) === 1;
            academicSidebarSlot(academicSideListCard(
                $single ? 'Altri articoli in ' . $postCategories[0]['name'] : 'Articoli correlati',
                $relatedTiles,
                $single ? blogCategoryUrl($slug, $postCategories[0]) : '/' . $slug . '/blog',
                $single ? 'Tutti gli articoli in ' . $postCategories[0]['name'] : 'Leggi il Blog'
            ));
        } elseif ($relatedTiles) {
            $single = count($postCategories) === 1;
            $relatedPostsHtml = renderAdminLteGridCarousel(
                $single ? 'Altri articoli in ' . $postCategories[0]['name'] : 'Articoli correlati',
                'bi-newspaper',
                $single ? blogCategoryUrl($slug, $postCategories[0]) : '/' . $slug . '/blog',
                $single ? 'Tutti gli articoli in ' . $postCategories[0]['name'] : 'Leggi il Blog',
                $relatedTiles,
                'square'
            );
        }
    }

    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($post['custom_feed_guid'], $post['custom_feed_guid_since'], $post['published_at']); ?>
<title><?= e($post['title']) ?> — <?= e($post['display_name']) ?></title>
<meta name="description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">

<meta property="og:type" content="article">
<meta property="og:title" content="<?= e($post['title']) ?>">
<meta property="og:description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">
<meta property="og:url" content="<?= e($permalink) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<?php if (!empty($post['cover_video_path'])): ?><meta property="og:video" content="<?= e(siteUrl('/' . $post['cover_video_path'])) ?>"><meta property="og:video:type" content="video/mp4"><?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($post['title']) ?>">
<meta name="twitter:description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($permalink) ?>">
<?= blogPostingJsonLd($post, $post['display_name'], $permalink, $ogImage) ?>
<?= breadcrumbJsonLd([
    ['name' => $post['display_name'], 'url' => siteUrl('/' . $slug)],
    ['name' => 'Blog', 'url' => siteUrl('/' . $slug . '/blog')],
    ['name' => $post['title'], 'url' => $permalink],
]) ?>
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'blog') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Articolo', ['Blog' => '/' . $slug . '/blog']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, $blogLatestPostsHtml . $blogCoverSidebarHtml . renderAdminLteBlogCategoriesNavCard((int) $post['user_id'], $slug, $activeCategoryId), true) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
                <?php if (($isOwner || $isPreview) && $isScheduledFuture): ?>
                  <div class="alert alert-warning mb-3">Questo articolo non è ancora pubblico (programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
                <?php endif; ?>

                <?php if ($acadPost): ?>
                <?= academicBlogPostHtml($post, $artist, $slug, $permalink, $postCategories) ?>
                <?php else: ?>
                <article class="card mb-3">
                  <div class="card-header d-flex align-items-center">
                    <div class="user-block mb-0">
                      <img src="<?= e($avatarUrl) ?>" alt="<?= e($post['display_name']) ?>" class="rounded-circle">
                      <span class="username"><a href="/<?= e($slug) ?>"><?= e($post['display_name']) ?></a> <small class="fw-normal text-secondary">ha pubblicato un articolo</small></span>
                      <span class="description"><?php
                        echo e(formatItalianDate($post['published_at'], $artist));
                      ?></span>
                    </div>
                  </div>
                  <?php if (!empty($post['cover_video_path'])): ?>
                    <video src="/<?= e($post['cover_video_path']) ?>" <?= $post['cover_path'] ? 'poster="/' . e($post['cover_path']) . '"' : '' ?> controls playsinline preload="metadata" class="d-md-none" style="width:100%;display:block;background:#000;"></video>
                  <?php elseif ($post['cover_path']): ?>
                    <img src="/<?= e($post['cover_path']) ?>" alt="<?= e($post['title']) ?>" class="d-md-none" style="width:100%;display:block;">
                  <?php endif; ?>
                  <div class="card-body">
                    <h2 class="h3 mb-3"><?= e($post['title']) ?></h2>
                    <?php if ($postCategories): ?>
                      <p class="mb-3">
                        <?php foreach ($postCategories as $cat): ?>
                          <a href="<?= e(blogCategoryUrl($slug, $cat)) ?>" class="badge text-bg-secondary text-decoration-none me-1"><?= e($cat['name']) ?></a>
                        <?php endforeach; ?>
                      </p>
                    <?php endif; ?>
                    <div><?= nl2br(e($post['content'])) ?></div>
                    <?php if ($post['tags']): ?>
                      <p class="text-secondary small mt-3 mb-0"><i class="bi bi-tags me-1" aria-hidden="true"></i><?= e($post['tags']) ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="card-footer small text-secondary">
                    <i class="bi bi-share me-1"></i>
                    <strong>Condividi</strong> · <span class="user-select-all"><?= e($permalink) ?></span>
                  </div>
                </article>
                <?php endif; ?>

                <?php if ($linkedAlbum): ?>
                <a href="/<?= e($slug) ?>/album/<?= (int) $linkedAlbum['id'] ?>" class="card mb-3 text-decoration-none text-body">
                  <div class="d-flex align-items-center gap-3 p-3">
                    <?php if ($linkedAlbum['cover_path']): ?>
                      <img src="/<?= e($linkedAlbum['cover_path']) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;" alt="">
                    <?php endif; ?>
                    <div>
                      <small class="text-secondary d-block"><i class="bi bi-images me-1" aria-hidden="true"></i>Album collegato</small>
                      <strong><?= e($linkedAlbum['title']) ?></strong>
                    </div>
                  </div>
                </a>
                <?php endif; ?>

                <?= $relatedPostsHtml ?>

          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Vetrina "Che Amo" a tema AdminLTE — griglia di card verso i moduli attivi (uguale a che_amo.php
// del tema Colorful, con le icone Bootstrap già usate nel tab Che Amo della Home).
function renderAdminLteCheAmoIndexPage(array $artist, string $slug, array $visibleModules): string {
    $pageUrl = siteUrl('/' . $slug . '/che-amo');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Che Amo — <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Che Amo — <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Che Amo') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Che Amo') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <?php if ($visibleModules): ?>
                <div class="row g-3 text-center">
                  <?php foreach ($visibleModules as $mKey => $m): ?>
                    <div class="col-6 col-sm-4 col-lg-3">
                      <a href="/<?= e($slug) ?>/<?= e($m['segment']) ?>" class="card text-decoration-none text-body p-3 h-100">
                        <div class="fs-2 text-primary mb-2"><i class="bi <?= e(ADMINLTE_CHE_AMO_ICONS[$mKey] ?? 'bi-heart') ?>" aria-hidden="true"></i></div>
                        <div class="fw-semibold small"><?= e($m['label']) ?></div>
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                  <div class="card"><div class="card-body text-secondary">Nessun contenuto ancora.</div></div>
                <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Elenco pubblico di un modulo "che amo" (Band/Attori/Film/Libri/Playlist/Album) a tema AdminLTE —
// una sola funzione generica al posto di 6 file quasi identici, guidata da ADMINLTE_FAN_FAVORITE_KINDS
// (stesso principio di configurazione già usato dal tema Colorful in fan_favorite_item.php).
function renderAdminLteFanFavoriteListPage(array $artist, string $slug, array $favorites, string $kind): string {
    $cfg = ADMINLTE_FAN_FAVORITE_KINDS[$kind];
    $pageUrl = siteUrl('/' . $slug . '/' . $cfg['list_url_segment']);
    $pageSize = 20;
    $finished = count($favorites) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $cfg['label'], ['Che Amo' => '/' . $slug . '/che-amo']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e($cfg['label']) ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <?php if ($favorites): ?>
                <div class="row g-3 text-center" id="adminlte-list-feed"><?= renderAdminLteFanFavoriteRows($favorites, $slug, $kind, $artist) ?></div>
                <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
                <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
                <div id="adminlte-list-sentinel" style="height:1px;"></div>
                <?= adminLteInfiniteScrollScript($kind, $slug, count($favorites), $pageSize, $finished) ?>
                <?php else: ?>
                  <div class="card"><div class="card-body text-secondary">Nessun elemento aggiunto ancora.</div></div>
                <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Dettaglio pubblico di un singolo elemento "che amo" a tema AdminLTE — stessa funzione generica
// riusata dai 6 moduli con API esterna, guidata da ADMINLTE_FAN_FAVORITE_KINDS. $apiDetails arriva
// già pronto dal chiamante (fan_favorite_item.php), che sa quale funzione dell'API chiamare per
// ciascun $kind.
function renderAdminLteFanFavoriteDetailPage(array $artist, string $slug, string $kind, array $item, array $sameDayItems, ?array $apiDetails, bool $isOwner = false, bool $isScheduledFuture = false, bool $isPreview = false): string {
    $cfg = ADMINLTE_FAN_FAVORITE_KINDS[$kind];
    $name = $item[$cfg['name_col']];
    $image = $item['image_path'] ?: ($item[$cfg['image_col']] ?? null);
    $imageUrl = $image ? (str_starts_with($image, 'http') ? $image : siteUrl($image)) : null;
    $note = trim($item['note'] ?? '');
    // Le ricette non hanno una pagina Spoonacular canonica raggiungibile solo dall'id: il link
    // migliore è quello del sito originale della ricetta (sourceUrl, dai dettagli live
    // dell'API) — l'URL fisso di Spoonacular resta solo un ripiego per quando l'API non risponde.
    $externalUrl = ($kind === 'recipe' && !empty($apiDetails['source_url']))
        ? $apiDetails['source_url']
        : $cfg['external_url'] . $item[$cfg['external_id_col']];
    $shapeStyle = ['circle' => 'width:160px;height:160px;border-radius:50%;', 'book' => 'width:140px;height:190px;border-radius:8px;', 'square' => 'width:170px;height:170px;border-radius:14px;'][$cfg['image_shape']];
    $pageUrl = siteUrl('/' . $slug . '/' . $cfg['list_url_segment'] . '/' . (int) $item['id']);
    if (!(int) $item['is_public'] || $isScheduledFuture) {
        $pageUrl = withPreviewToken($pageUrl, fanFavoritePreviewType($kind), (int) $item['id']);
    }
    $ogDescription = $note !== '' ? $note : ($apiDetails['biography'] ?? $apiDetails['overview'] ?? ($artist['display_name'] . ' ama ' . $name . ' — scoprilo su ' . siteName()));
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'] ?? null, $artist['custom_feed_guid_since'] ?? null, $item['created_at']); ?>
<title><?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?><meta property="og:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<?php if ($imageUrl): ?><meta name="twitter:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Scheda', ['Che Amo' => '/' . $slug . '/che-amo', $cfg['label'] => '/' . $slug . '/' . $cfg['list_url_segment']]) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <h3 class="mb-3"><?= e($cfg['label']) ?></h3>
            <?php if (($isOwner || $isPreview) && (!(int) $item['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo elemento non è visibile al pubblico al momento (Solo io, o programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($imageUrl): ?><img src="<?= e($imageUrl) ?>" alt="<?= e($name) ?>" class="mb-3" style="<?= $shapeStyle ?>object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);"><?php endif; ?>
                <h1 class="h4 mb-1"><?= e($name) ?></h1>
                <p class="text-secondary">
                  <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>
                  <?php if (!empty($apiDetails['authors'])): ?> · <?= e($apiDetails['authors']) ?><?php endif; ?>
                  <?php if ($kind === 'album' && !empty($item['album_artist_name'])): ?> · <?= e($item['album_artist_name']) ?><?php endif; ?>
                  <?php if ($kind === 'playlist' && !empty($apiDetails['owner'])): ?> · di <?= e($apiDetails['owner']) ?> su Spotify<?php endif; ?>
                  <?php if (!empty($apiDetails['tracks_total'])): ?> · <?= (int) $apiDetails['tracks_total'] ?> brani<?php endif; ?>
                  <?php if (!empty($apiDetails['release_date'])): ?> · <?= e(substr($apiDetails['release_date'], 0, 4)) ?><?php endif; ?>
                  <?php if (!empty($apiDetails['known_for_department'])): ?> · <?= e($apiDetails['known_for_department']) ?><?php endif; ?>
                  <?php if ($kind === 'recipe' && !empty($apiDetails['ready_in_minutes'])): ?> · <?= (int) $apiDetails['ready_in_minutes'] ?> min<?php endif; ?>
                  <?php if ($kind === 'recipe' && !empty($apiDetails['servings'])): ?> · <?= (int) $apiDetails['servings'] ?> porzioni<?php endif; ?>
                  <?php if ($kind === 'team' && !empty($apiDetails['league'])): ?> · <?= e($apiDetails['league']) ?><?php endif; ?>
                  <?php if ($kind === 'team' && !empty($apiDetails['country'])): ?> · <?= e($apiDetails['country']) ?><?php endif; ?>
                  <?php if ($kind === 'team' && !empty($apiDetails['founded_year'])): ?> · dal <?= e($apiDetails['founded_year']) ?><?php endif; ?>
                  <?php if ($kind === 'footballer' && !empty($apiDetails['team'])): ?> · <?= e($apiDetails['team']) ?><?php endif; ?>
                  <?php if ($kind === 'footballer' && !empty($apiDetails['position'])): ?> · <?= e($apiDetails['position']) ?><?php endif; ?>
                  <?php if ($kind === 'footballer' && !empty($apiDetails['nationality'])): ?> · <?= e($apiDetails['nationality']) ?><?php endif; ?>
                  <?php if ($kind === 'match' && !empty($apiDetails['league'])): ?> · <?= e($apiDetails['league']) ?><?php endif; ?>
                  <?php if ($kind === 'match' && !empty($apiDetails['venue'])): ?> · <?= e($apiDetails['venue']) ?><?php endif; ?>
                  <?php if ($kind === 'publication' && !empty($apiDetails['journal'])): ?> · <?= e($apiDetails['journal']) ?><?php endif; ?>
                </p>
                <small class="text-secondary"><?= e(publishedAtLabel($item['publish_at'], $item['created_at'], $artist)) ?></small>
                <?php if ($kind === 'album' && !empty($apiDetails['genres'])): ?><p class="mt-1 fst-italic"><?= e(implode(', ', $apiDetails['genres'])) ?></p><?php endif; ?>
                <?php if ($kind === 'playlist' && !empty($apiDetails['description'])): ?><p class="text-start mt-2"><?= nl2br(e(strip_tags($apiDetails['description']))) ?></p><?php endif; ?>
                <?php if ($note !== ''): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Perché <?= e($artist['display_name']) ?> lo ama</strong><p class="mb-0 mt-1"><?= nl2br(e($note)) ?></p></div></div>
                <?php endif; ?>
                <?php if ($kind !== 'album' && !empty($apiDetails['genres'])): ?><p class="mt-3 fst-italic"><?= e(implode(', ', $apiDetails['genres'])) ?></p><?php endif; ?>
                <?php if (!empty($apiDetails['overview'])): ?>
                  <p class="text-start mt-2"><?= nl2br(e($apiDetails['overview'])) ?></p>
                <?php elseif (!empty($apiDetails['biography'])): ?>
                  <p class="text-start mt-2"><?= nl2br(e(textExcerpt($apiDetails['biography'], 500))) ?></p>
                <?php endif; ?>
                <?php if ($kind === 'recipe' && !empty($apiDetails['ingredients'])): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Ingredienti</strong>
                    <ul class="mb-0 mt-1">
                      <?php foreach ($apiDetails['ingredients'] as $ing): ?><li><?= e($ing) ?></li><?php endforeach; ?>
                    </ul>
                  </div></div>
                <?php endif; ?>
                <?php if ($kind === 'team' && !empty($apiDetails['stadium'])): ?>
                  <p class="text-secondary small mt-2 mb-0"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= e($apiDetails['stadium']) ?></p>
                <?php endif; ?>
                <?php if ($kind === 'team' && !empty($apiDetails['next_event'])): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Prossima partita</strong><p class="mb-0 mt-1"><?= e($apiDetails['next_event']) ?></p></div></div>
                <?php endif; ?>
                <?php if ($kind === 'footballer' && !empty($apiDetails['born'])): ?>
                  <?php // Data di nascita pura (senza orario): niente conversione di fuso orario (formatLocalDateTime()) qui, sposterebbe il giorno per i profili con fuso molto indietro rispetto al server. ?>
                  <p class="text-secondary small mt-2 mb-0"><i class="bi bi-balloon-heart me-1" aria-hidden="true"></i>Nato il <?= e(date('d/m/Y', strtotime($apiDetails['born']))) ?></p>
                <?php endif; ?>
                <?php if ($kind === 'match'): ?>
                  <div class="card text-center mt-3"><div class="card-body">
                    <?php if (!empty($apiDetails['result'])): ?>
                      <div class="h4 mb-1"><?= e($apiDetails['home_team']) ?> <?= e($apiDetails['result']) ?> <?= e($apiDetails['away_team']) ?></div>
                    <?php else: ?>
                      <div class="text-secondary">Partita in programma</div>
                    <?php endif; ?>
                    <?php if (!empty($apiDetails['match_date'])): ?><small class="text-secondary"><?= e(trim($apiDetails['match_date'])) ?></small><?php endif; ?>
                  </div></div>
                <?php endif; ?>
                <p class="mt-3"><a href="<?= e($externalUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><?= e($cfg['external_label']) ?></a></p>
              </div>
            </div>

            <?php if ($sameDayItems): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title h6 mb-0">Altri di questa giornata (<?= count($sameDayItems) ?>)</h3></div>
              </div>
              <?php foreach ($sameDayItems as $s):
                $sName = $s[$cfg['name_col']];
                $sImage = $s['image_path'] ?: ($s[$cfg['image_col']] ?? null);
                $sImageUrl = $sImage ? (str_starts_with($sImage, 'http') ? $sImage : siteUrl($sImage)) : null;
                $sNote = trim($s['note'] ?? '');
                $sExternalUrl = $cfg['external_url'] . $s[$cfg['external_id_col']];
              ?>
              <div class="card mb-3">
                <div class="card-body text-center">
                  <a href="/<?= e($slug) ?>/<?= e($cfg['list_url_segment']) ?>/<?= (int) $s['id'] ?>" class="text-decoration-none text-body">
                    <?php if ($sImageUrl): ?><img src="<?= e($sImageUrl) ?>" alt="<?= e($sName) ?>" class="mb-2" style="<?= $shapeStyle ?>object-fit:cover;"><?php endif; ?>
                    <h4 class="h6 mb-1"><?= e($sName) ?></h4>
                    <small class="text-secondary"><?= e(publishedAtLabel($s['publish_at'], $s['created_at'], $artist)) ?></small>
                  </a>
                  <?php if ($sNote !== ''): ?><p class="text-start mt-2 mb-0 text-secondary"><?= nl2br(e($sNote)) ?></p><?php endif; ?>
                  <p class="mt-2 mb-0"><a href="<?= e($sExternalUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><?= e($cfg['external_label']) ?></a></p>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Elenco pubblico "Viaggi" a tema AdminLTE, raggruppato per mese come viaggi.php del tema Colorful.
function renderAdminLteViaggiListPage(array $artist, string $slug, array $monthGroups, int $totalCount): string {
    $pageUrl = siteUrl('/' . $slug . '/viaggi');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Viaggi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Viaggi di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Viaggi (' . $totalCount . ')', ['Che Amo' => '/' . $slug . '/che-amo']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Viaggi (' . $totalCount . ')') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($monthGroups): ?>
              <?php foreach ($monthGroups as $group): ?>
                <div class="card mt-3 mb-2">
                  <div class="card-header"><h3 class="card-title h6 mb-0"><?= e($group['label']) ?></h3></div>
                </div>
                <div class="row g-3 text-center mb-2">
                  <?php foreach ($group['items'] as $f): $thumb = $f['image_path'] ?: $f['map_image_path']; ?>
                    <div class="col-6 col-sm-4 col-lg-3">
                      <a href="/<?= e($slug) ?>/viaggi/<?= (int) $f['id'] ?>" class="card text-decoration-none text-body p-2 h-100">
                        <?php if ($thumb): ?><img src="/<?= e($thumb) ?>" alt="" loading="lazy" class="mb-2 rounded" style="width:100%;height:88px;object-fit:cover;"><?php endif; ?>
                        <div class="fw-semibold small"><?= e($f['place_name']) ?></div>
                        <small class="text-secondary" style="font-size:11px;"><?= e(publishedAtLabel($f['publish_at'], $f['created_at'], $artist)) ?></small>
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Nessun viaggio aggiunto ancora.</div></div>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Dettaglio pubblico di un singolo viaggio a tema AdminLTE — carosello foto + mappa OpenStreetMap
// (renderPhotoCarousel()/renderOsmEmbed() sono componenti a sé, invariati rispetto al Colorful).
function renderAdminLteViaggioDetailPage(array $artist, string $slug, array $trip, array $photos, array $sameDayItems, bool $isOwner = false, bool $isScheduledFuture = false, bool $isPreview = false): string {
    $note = trim($trip['note'] ?? '');
    $image = (count($photos) > 1) ? getFeedShareImage($trip['image_path']) : ($trip['image_path'] ?: $trip['map_image_path']);
    $imageUrl = $image ? siteUrl($image) : null;
    $pageUrl = siteUrl('/' . $slug . '/viaggi/' . (int) $trip['id']);
    if (!(int) $trip['is_public'] || $isScheduledFuture) {
        $pageUrl = withPreviewToken($pageUrl, 'viaggio_favorito', (int) $trip['id']);
    }
    $ogDescription = $note !== '' ? $note : ($artist['display_name'] . ' è stato a ' . $trip['place_name'] . ' — scoprilo su ' . siteName());
    $anyMultiPhoto = (bool) $photos;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'] ?? null, $artist['custom_feed_guid_since'] ?? null, $trip['created_at']); ?>
<title><?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?><meta property="og:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<?php if ($imageUrl): ?><meta name="twitter:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if ($anyMultiPhoto): ?><link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>"><?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Viaggio', ['Che Amo' => '/' . $slug . '/che-amo', 'Viaggi' => '/' . $slug . '/viaggi']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <h3 class="mb-3"><?= e('Viaggi') ?></h3>
            <?php if (($isOwner || $isPreview) && (!(int) $trip['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo viaggio non è visibile al pubblico al momento (Solo io, o programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?= renderPhotoCarousel($photos, (int) $trip['id']) ?>
                <h1 class="h4 mb-1"><?= e($trip['place_name']) ?></h1>
                <p class="text-secondary">
                  Viaggio di <?= e($artist['display_name']) ?>
                  <?php if (!empty($trip['address']) && $trip['address'] !== $trip['place_name']): ?> · <?= e($trip['address']) ?><?php endif; ?>
                </p>
                <small class="text-secondary"><?= e(publishedAtLabel($trip['publish_at'], $trip['created_at'], $artist)) ?></small>
                <?php if ($note !== ''): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Il racconto di <?= e($artist['display_name']) ?></strong><p class="mb-0 mt-1"><?= nl2br(e($note)) ?></p></div></div>
                <?php endif; ?>
                <div class="mt-3"><?= renderOsmEmbed((float) $trip['lat'], (float) $trip['lng']) ?></div>
              </div>
            </div>

            <?php if ($sameDayItems): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title h6 mb-0">Altri di questa giornata (<?= count($sameDayItems) ?>)</h3></div>
              </div>
              <?php foreach ($sameDayItems as $s):
                $sNote = trim($s['note'] ?? '');
                $sPhotos = $s['image_path'] ? array_values(array_filter(array_merge([$s['image_path']], getTripPhotos((int) $s['id'])))) : [];
                if ($sPhotos) { $anyMultiPhoto = true; }
              ?>
              <div class="card mb-3">
                <div class="card-body text-center">
                  <?= renderPhotoCarousel($sPhotos, (int) $s['id']) ?>
                  <a href="/<?= e($slug) ?>/viaggi/<?= (int) $s['id'] ?>" class="text-decoration-none text-body">
                    <h4 class="h6 mb-1"><?= e($s['place_name']) ?></h4>
                    <p class="text-secondary mb-1">
                      Viaggio di <?= e($artist['display_name']) ?>
                      <?php if (!empty($s['address']) && $s['address'] !== $s['place_name']): ?> · <?= e($s['address']) ?><?php endif; ?>
                    </p>
                    <small class="text-secondary"><?= e(publishedAtLabel($s['publish_at'], $s['created_at'], $artist)) ?></small>
                  </a>
                  <?php if ($sNote !== ''): ?><p class="text-start mt-2 mb-0 text-secondary"><?= nl2br(e($sNote)) ?></p><?php endif; ?>
                  <div class="mt-2"><?= renderOsmEmbed((float) $s['lat'], (float) $s['lng']) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if ($anyMultiPhoto): ?><script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Elenco pubblico "Brani che amo" a tema AdminLTE — lista, non griglia, come brani.php del tema
// Colorful (ogni riga ha anche i link a testo/voto).
function renderAdminLteBraniListPage(array $artist, string $slug, array $tracks): string {
    $pageUrl = siteUrl('/' . $slug . '/brani');
    $pageSize = 20;
    $finished = count($tracks) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Brani che amo di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Brani che amo di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Brani che amo', ['Che Amo' => '/' . $slug . '/che-amo']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Brani che amo') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (!$tracks): ?>
              <div class="card"><div class="card-body text-secondary">Nessun brano aggiunto ancora.</div></div>
            <?php else: ?>
              <div id="adminlte-list-feed"><?= renderAdminLteBraniRows($tracks, $slug, $artist) ?></div>
              <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
              <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
              <div id="adminlte-list-sentinel" style="height:1px;"></div>
              <?= adminLteInfiniteScrollScript('brani', $slug, count($tracks), $pageSize, $finished) ?>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Dettaglio pubblico ("scheda") di un singolo brano che amo a tema AdminLTE — favorite_track_item.php.
function renderAdminLteFavoriteTrackDetailPage(array $artist, string $slug, array $track, array $sameDayItems, bool $isOwner = false, bool $isScheduledFuture = false, bool $isPreview = false): string {
    $note = trim($track['note'] ?? '');
    $image = $track['image_path'] ?: $track['track_image'];
    $imageUrl = $image ? (str_starts_with($image, 'http') ? $image : siteUrl($image)) : null;
    $pageUrl = siteUrl('/' . $slug . '/brani/' . (int) $track['id'] . '/scheda');
    if (!(int) $track['is_public'] || $isScheduledFuture) {
        $pageUrl = withPreviewToken($pageUrl, 'brano', (int) $track['id']);
    }
    $ogDescription = $note !== '' ? $note : ($artist['display_name'] . ' ama "' . $track['track_name'] . '" — scoprilo su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'] ?? null, $artist['custom_feed_guid_since'] ?? null, $track['created_at']); ?>
<title><?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:type" content="music.song">
<meta property="og:title" content="<?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?><meta property="og:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<?php if ($imageUrl): ?><meta name="twitter:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Scheda', ['Che Amo' => '/' . $slug . '/che-amo', 'Brani che amo' => '/' . $slug . '/brani']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <h3 class="mb-3"><?= e('Brani che amo') ?></h3>
            <?php if (($isOwner || $isPreview) && (!(int) $track['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo brano non è visibile al pubblico al momento (Solo io, o programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($imageUrl): ?><img src="<?= e($imageUrl) ?>" alt="<?= e($track['track_name']) ?>" class="mb-3 rounded-4" style="width:220px;height:220px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);"><?php endif; ?>
                <h1 class="h4 mb-1"><?= e($track['track_name']) ?></h1>
                <p class="text-secondary">Brano che amo di <?= e($artist['display_name']) ?><?php if ($track['artist_name']): ?> · <?= e($track['artist_name']) ?><?php endif; ?></p>
                <small class="text-secondary"><?= e(publishedAtLabel($track['publish_at'], $track['created_at'], $artist)) ?></small>
                <?php if ($note !== ''): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Perché <?= e($artist['display_name']) ?> lo ama</strong><p class="mb-0 mt-1"><?= nl2br(e($note)) ?></p></div></div>
                <?php endif; ?>
                <div class="d-flex gap-2 justify-content-center flex-wrap mt-3">
                  <?php if ($track['spotify_url']): ?><a href="<?= e($track['spotify_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary">Ascolta su Spotify</a><?php endif; ?>
                  <?php if (!empty($track['lyrics'])): ?><a href="/<?= e($slug) ?>/brani/<?= (int) $track['id'] ?>/testo" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-text me-1"></i>Testo</a><?php endif; ?>
                  <a href="/<?= e($slug) ?>/brani/<?= (int) $track['id'] ?>/votazioni" class="btn btn-sm btn-outline-primary"><i class="bi bi-star-fill me-1"></i>Vota</a>
                </div>
              </div>
            </div>

            <?php if ($sameDayItems): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title h6 mb-0">Altri di questa giornata (<?= count($sameDayItems) ?>)</h3></div>
              </div>
              <?php foreach ($sameDayItems as $s):
                $sImage = $s['image_path'] ?: $s['track_image'];
                $sImageUrl = $sImage ? (str_starts_with($sImage, 'http') ? $sImage : siteUrl($sImage)) : null;
                $sNote = trim($s['note'] ?? '');
              ?>
              <div class="card mb-3">
                <div class="card-body text-center">
                  <a href="/<?= e($slug) ?>/brani/<?= (int) $s['id'] ?>/scheda" class="text-decoration-none text-body">
                    <?php if ($sImageUrl): ?><img src="<?= e($sImageUrl) ?>" alt="" class="mb-2 rounded-4" style="width:220px;height:220px;object-fit:cover;"><?php endif; ?>
                    <h4 class="h6 mb-1"><?= e($s['track_name']) ?></h4>
                    <p class="text-secondary mb-1">Brano che amo di <?= e($artist['display_name']) ?><?php if ($s['artist_name']): ?> · <?= e($s['artist_name']) ?><?php endif; ?></p>
                    <small class="text-secondary"><?= e(publishedAtLabel($s['publish_at'], $s['created_at'], $artist)) ?></small>
                  </a>
                  <?php if ($sNote !== ''): ?><p class="text-start mt-2 mb-0 text-secondary"><?= nl2br(e($sNote)) ?></p><?php endif; ?>
                  <?php if ($s['spotify_url']): ?><p class="mt-2 mb-0"><a href="<?= e($s['spotify_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Ascolta su Spotify</a></p><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Testo e ascolto" di un brano che amo a tema AdminLTE — track_lyrics.php.
function renderAdminLteTrackLyricsPage(array $artist, string $slug, array $track, array $stats): string {
    $pageUrl = siteUrl('/' . $slug . '/brani/' . (int) $track['id'] . '/testo');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($track['track_name']) ?> — Testo e ascolto — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($track['track_name']) ?> — Testo e ascolto">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if ($track['track_image']): ?><meta property="og:image" content="<?= e($track['track_image']) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Testo e ascolto', ['Che Amo' => '/' . $slug . '/che-amo', 'Brani che amo' => '/' . $slug . '/brani']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Testo e ascolto') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($track['track_image']): ?><img src="<?= e($track['track_image']) ?>" class="mb-2 rounded-3" style="width:96px;height:96px;object-fit:cover;"><?php endif; ?>
                <h1 class="h5 mb-0"><?= e($track['track_name']) ?></h1>
                <p class="text-secondary mb-1"><?= e($track['artist_name']) ?></p>
                <?php if ($stats['count'] > 0): ?><p class="mb-0"><?= renderCromeRating($stats['avg']) ?> <span class="text-secondary small">(<?= $stats['count'] ?>)</span></p><?php endif; ?>
              </div>
            </div>

            <?php if ($track['spotify_track_id']): ?>
            <div class="card mb-3 p-0" style="overflow:hidden;">
              <iframe style="border-radius:12px;" src="https://open.spotify.com/embed/track/<?= e($track['spotify_track_id']) ?>?utm_source=generator"
                width="100%" height="152" frameborder="0" allowfullscreen loading="lazy"
                allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"></iframe>
            </div>
            <?php endif; ?>

            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title"><i class="bi bi-file-text me-1"></i>Testo</h3></div>
              <div class="card-body" style="white-space:pre-line;line-height:1.7;"><?= e($track['lyrics']) ?></div>
            </div>

            <p class="text-center"><a href="/<?= e($slug) ?>/brani/<?= (int) $track['id'] ?>/votazioni"><i class="bi bi-star-fill me-1"></i>Vota questo brano →</a></p>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Vota questo brano" a tema AdminLTE — track_review.php. Riusa renderRatingForm()
// (form di voto reale, invariato rispetto al Colorful) dentro la card.
function renderAdminLteTrackReviewPage(array $artist, string $slug, array $track, array $stats, ?int $viewerId, ?int $myRating, array $reviewers): string {
    $pageUrl = siteUrl('/' . $slug . '/brani/' . (int) $track['id'] . '/votazioni');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vota: <?= e($track['track_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Vota: <?= e($track['track_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Vota il brano', ['Che Amo' => '/' . $slug . '/che-amo', 'Brani che amo' => '/' . $slug . '/brani']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Vota il brano') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($track['track_image']): ?><img src="<?= e($track['track_image']) ?>" class="mb-2 rounded-3" style="width:96px;height:96px;object-fit:cover;"><?php endif; ?>
                <h1 class="h5 mb-0"><?= e($track['track_name']) ?></h1>
                <p class="text-secondary mb-2"><?= e($track['artist_name']) ?></p>
                <?php if ($track['spotify_url']): ?><a href="<?= e($track['spotify_url']) ?>" target="_blank" rel="noopener" class="small"><i class="bi bi-spotify me-1"></i>Ascolta su Spotify</a><?php endif; ?>
              </div>
            </div>

            <div class="card mb-3 border-primary">
              <div class="card-header"><h3 class="card-title"><i class="bi bi-star-fill me-1"></i>Vota questo brano</h3></div>
              <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <?= renderCromeRating($stats['avg']) ?>
                  <?php if ($stats['count'] > 0): ?><span class="text-secondary small"><?= $stats['avg'] ?> · <?= $stats['count'] ?> <?= $stats['count'] === 1 ? 'voto' : 'voti' ?></span><?php endif; ?>
                </div>
                <?= renderRatingForm('rate_track', (int) $track['id'], $viewerId, (int) $track['user_id'], $myRating) ?>
                <?php if ($reviewers): ?>
                <div class="mt-3 d-flex flex-wrap gap-2">
                  <?php foreach ($reviewers as $r): ?>
                    <span class="badge text-bg-light">@<?= e($r['slug']) ?> <?= renderCromeRating((float) $r['rating']) ?></span>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!empty($track['lyrics'])): ?>
              <p class="text-center"><a href="/<?= e($slug) ?>/brani/<?= (int) $track['id'] ?>/testo"><i class="bi bi-file-text me-1"></i>Testo e ascolto →</a></p>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Spotify" (artista) a tema AdminLTE — artist_spotify.php.
function renderAdminLteSpotifyPage(array $artist, string $slug, array $albums, array $topTracks, ?array $artistDetails): string {
    $pageUrl = siteUrl('/' . $slug . '/spotify');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> su Spotify — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['display_name']) ?> su Spotify">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if (!empty($artistDetails['image'])): ?><meta property="og:image" content="<?= e($artistDetails['image']) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'spotify') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Spotify') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Spotify') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

            <?php if (!empty($artistDetails['genres'])): ?>
            <div class="card mb-3">
              <div class="card-body">
                <?php foreach ($artistDetails['genres'] as $genre): ?>
                  <span class="badge text-bg-light text-capitalize me-1"><?= e($genre) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($topTracks): ?>
              <div class="card mb-2">
                <div class="card-header"><h3 class="card-title h6 mb-0">Brani più ascoltati</h3></div>
              </div>
              <?php foreach ($topTracks as $t): ?>
                <a class="card mb-2 text-decoration-none text-body" href="<?= e($t['spotify_url']) ?>" target="_blank" rel="noopener">
                  <div class="card-body d-flex align-items-center gap-3">
                    <?php if ($t['image']): ?><img src="<?= e($t['image']) ?>" alt="" style="width:56px;height:56px;border-radius:8px;flex-shrink:0;"><?php endif; ?>
                    <div class="flex-grow-1" style="min-width:0;">
                      <strong class="d-block text-truncate"><?= e($t['name']) ?></strong>
                      <small class="text-secondary"><?= e($t['album_name']) ?></small>
                    </div>
                    <i class="bi bi-spotify text-success fs-4 flex-shrink-0"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($albums): ?>
              <div class="card mt-4 mb-2">
                <div class="card-header"><h3 class="card-title h6 mb-0">Album e singoli</h3></div>
              </div>
              <div class="row g-3 text-center">
                <?php foreach ($albums as $a): ?>
                  <div class="col-6 col-sm-4 col-lg-3">
                    <a href="<?= e($a['spotify_url']) ?>" target="_blank" rel="noopener" class="card text-decoration-none text-body p-2 h-100">
                      <?php if ($a['image']): ?><img src="<?= e($a['image']) ?>" alt="" class="rounded-3 mb-1" style="width:100%;"><?php endif; ?>
                      <div class="small fw-semibold"><?= e($a['name']) ?></div>
                      <div class="text-secondary" style="font-size:11.5px;">
                        <?= e($a['release_date'] ? substr($a['release_date'], 0, 4) : '') ?> · <?= $a['type'] === 'single' ? 'Singolo' : 'Album' ?>
                      </div>
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!$topTracks && !$albums): ?>
              <div class="card"><div class="card-body text-secondary">Nessun contenuto trovato su Spotify per questo artista al momento.</div></div>
            <?php endif; ?>

            <div class="card mt-4">
              <div class="card-body text-center">
                <a class="btn btn-success" href="https://open.spotify.com/artist/<?= e($artist['spotify_artist_id']) ?>" target="_blank" rel="noopener">
                  <i class="bi bi-spotify me-1"></i>Apri il profilo completo su Spotify
                </a>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Podcast" a tema AdminLTE — podcast.php.
function renderAdminLtePodcastPage(array $artist, string $slug, array $episodes, ?array $showDetails): string {
    $pageUrl = siteUrl('/' . $slug . '/podcast');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['spotify_show_name'] ?: 'Podcast') ?> — <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['spotify_show_name'] ?: 'Podcast') ?> — <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if (!empty($showDetails['image'])): ?><meta property="og:image" content="<?= e($showDetails['image']) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'podcast') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $artist['spotify_show_name'] ?: 'Podcast') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e($artist['spotify_show_name'] ?: 'Podcast') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($episodes): ?>
              <?php foreach ($episodes as $ep): ?>
                <a class="card mb-2 text-decoration-none text-body" href="<?= e($ep['spotify_url']) ?>" target="_blank" rel="noopener">
                  <div class="card-body d-flex align-items-center gap-3">
                    <?php if ($ep['image']): ?><img src="<?= e($ep['image']) ?>" alt="" style="width:64px;height:64px;border-radius:8px;flex-shrink:0;"><?php endif; ?>
                    <div class="flex-grow-1" style="min-width:0;">
                      <strong class="d-block"><?= e($ep['name']) ?></strong>
                      <small class="text-secondary">
                        <?= $ep['release_date'] ? date('d/m/Y', strtotime($ep['release_date'])) : '' ?>
                        <?= $ep['duration_ms'] ? ' · ' . gmdate('i:s', (int) ($ep['duration_ms'] / 1000)) . ' min' : '' ?>
                      </small>
                      <?php if ($ep['description']): ?><p class="text-secondary small mb-0 mt-1"><?= e($ep['description']) ?></p><?php endif; ?>
                    </div>
                    <i class="bi bi-spotify text-success fs-4 flex-shrink-0"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Nessun episodio trovato al momento.</div></div>
            <?php endif; ?>

            <div class="card mt-4">
              <div class="card-body text-center">
                <a class="btn btn-success" href="https://open.spotify.com/show/<?= e($artist['spotify_show_id']) ?>" target="_blank" rel="noopener">
                  <i class="bi bi-spotify me-1"></i>Ascolta tutti gli episodi su Spotify
                </a>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "YouTube" a tema AdminLTE — youtube.php.
function renderAdminLteYoutubePage(array $artist, string $slug, array $videos): string {
    $pageUrl = siteUrl('/' . $slug . '/youtube');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> su YouTube — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['display_name']) ?> su YouTube">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'youtube') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'YouTube') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('YouTube') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($videos): ?>
              <?php foreach ($videos as $v): ?>
                <div class="card mb-3 p-0" style="overflow:hidden;">
                  <div class="ratio ratio-16x9">
                    <iframe src="https://www.youtube.com/embed/<?= e($v['video_id']) ?>" title="<?= e($v['title']) ?>"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                  </div>
                  <div class="p-3"><strong><?= e($v['title']) ?></strong></div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Nessun video trovato su YouTube per questo canale al momento.</div></div>
            <?php endif; ?>

            <div class="card mt-3">
              <div class="card-body text-center">
                <a class="btn btn-danger" href="https://www.youtube.com/channel/<?= e($artist['youtube_channel_id']) ?>" target="_blank" rel="noopener">
                  <i class="bi bi-youtube me-1"></i>Vai al canale completo su YouTube
                </a>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Elenco pubblico dei video caricati a tema AdminLTE — video.php.
function renderAdminLteVideosPage(array $artist, string $slug, array $videos): string {
    $pageUrl = siteUrl('/' . $slug . '/video');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Video di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Video di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'video') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Video') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <?php if ($videos && academicSkinActive()): ?>
              <h1 class="page__title">Video</h1>
              <?php foreach ($videos as $v): ?>
                <div class="archive__item acad-video">
                  <a class="acad-video__thumb" href="/<?= e($slug) ?>/video/<?= (int) $v['id'] ?>">
                    <?php if (!empty($v['thumb_path'])): ?><img src="/<?= e($v['thumb_path']) ?>" alt="" loading="lazy"><?php endif; ?>
                    <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                  </a>
                  <div class="archive__item-body">
                    <h3 class="archive__item-title"><a href="/<?= e($slug) ?>/video/<?= (int) $v['id'] ?>"><?= e($v['title']) ?></a></h3>
                    <?php if ($dl = videoDurationLabel($v['duration_seconds'] !== null ? (int) $v['duration_seconds'] : null)): ?><p class="page__meta"><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= e($dl) ?></p><?php endif; ?>
                    <?php if (!empty($v['description'])): ?><p class="archive__item-excerpt"><?= e(textExcerpt($v['description'], 160)) ?></p><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php elseif ($videos): ?>
              <?php foreach ($videos as $v): ?>
                <a href="/<?= e($slug) ?>/video/<?= (int) $v['id'] ?>" class="card mb-3 text-decoration-none text-reset" style="overflow:hidden;">
                  <div class="ratio ratio-16x9 bg-dark position-relative">
                    <?php if (!empty($v['thumb_path'])): ?><img src="/<?= e($v['thumb_path']) ?>" alt="" loading="lazy" style="object-fit:cover;width:100%;height:100%;"><?php endif; ?>
                    <span class="position-absolute top-50 start-50 translate-middle text-white" style="font-size:48px;text-shadow:0 2px 8px rgba(0,0,0,.6);"><i class="bi bi-play-circle-fill"></i></span>
                  </div>
                  <div class="p-3">
                    <strong><?= e($v['title']) ?></strong>
                    <?php if ($dl = videoDurationLabel($v['duration_seconds'] !== null ? (int) $v['duration_seconds'] : null)): ?><span class="text-secondary small"> · <?= e($dl) ?></span><?php endif; ?>
                    <?php if (!empty($v['description'])): ?><div class="text-secondary small mt-1"><?= e(textExcerpt($v['description'], 140)) ?></div><?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Nessun video ancora.</div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Dettaglio pubblico di un video caricato a tema AdminLTE — video_item.php.
function renderAdminLteVideoDetailPage(array $artist, string $slug, array $video, bool $isOwner, bool $isScheduledFuture, bool $isPreview = false): string {
    $pageUrl = videoPublicUrl($slug, (int) $video['id']);
    if (!(int) $video['is_public'] || $isScheduledFuture) {
        $pageUrl = withPreviewToken($pageUrl, 'video', (int) $video['id']);
    }
    $videoUrl = siteUrl('/' . $video['video_path']);
    $ogImage = !empty($video['thumb_path']) ? siteUrl('/' . $video['thumb_path']) : null;
    $ogDescription = $video['description'] ? textExcerpt($video['description'], 160) : ($video['display_name'] . ' — guarda il video su ' . siteName());

    // Come per le foto di eventi e articoli: su schermi larghi il video sta nella colonna destra
    // (card d-none d-md-block, passata come prima card di renderAdminLteProfileExtras()), nella
    // colonna centrale restano titolo e descrizione; su mobile, dove le colonne si impilano, il
    // video resta dentro la card centrale (d-md-none). Con l'anteprima il browser non scarica il
    // file finché non si preme play (le due copie non pesano doppio).
    $avatarUrl = adminLteAvatarUrl($artist);

    // Altri video del profilo (pubblici e già pubblicati, escluso questo), con la stessa griglia
    // di miniature del widget "Ultimi articoli" — mostrata solo se ce ne sono.
    $moreVideosHtml = '';
    $stmt = getDB()->prepare("SELECT id, title, thumb_path, publish_at, created_at FROM videos
        WHERE user_id = ? AND id <> ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())
        ORDER BY sort_order DESC, id DESC LIMIT 8");
    $stmt->execute([(int) $video['user_id'], (int) $video['id']]);
    $moreTiles = [];
    foreach ($stmt->fetchAll() as $mv) {
        $moreTiles[] = [
            'url' => '/' . $slug . '/video/' . (int) $mv['id'],
            'image' => !empty($mv['thumb_path']) ? '/' . $mv['thumb_path'] : '',
            'placeholder_icon' => 'bi-play-btn',
            'title' => $mv['title'],
            'date' => formatItalianDate($mv['publish_at'] ?: $mv['created_at'], $artist),
        ];
    }
    if ($moreTiles) {
        $moreVideosHtml = renderAdminLteGridCarousel('Altri video', 'bi-play-btn', '/' . $slug . '/video', 'Tutti i video', $moreTiles, 'square');
    }
    $hasPoster = !empty($video['thumb_path']);
    $playerHtml = '<video controls playsinline preload="' . ($hasPoster ? 'none' : 'metadata') . '" style="width:100%;height:auto;display:block;background:#000;"'
        . ($hasPoster ? ' poster="/' . e($video['thumb_path']) . '"' : '') . '>'
        . '<source src="' . e($videoUrl) . ($hasPoster ? '' : '#t=1') . '" type="' . e($video['mime_type']) . '">'
        . '</video>';
    $videoSidebarHtml = '<div class="card mb-3 d-none d-md-block"><div class="card-body p-0" style="overflow:hidden;border-radius:inherit;">' . $playerHtml . '</div></div>';
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($video['title']) ?> — <?= e($video['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="video.other">
<meta property="og:title" content="<?= e($video['title']) ?> — <?= e($video['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<meta property="og:video" content="<?= e($videoUrl) ?>">
<meta property="og:video:type" content="<?= e($video['mime_type']) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($video['title']) ?> — <?= e($video['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'video') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $video['title'], ['Video' => '/' . $slug . '/video']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, $videoSidebarHtml, true) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <?php if (($isOwner || $isPreview) && (!(int) $video['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo video non è visibile al pubblico al momento (privato o programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
            <?php endif; ?>
            <article class="card mb-3">
              <div class="card-header d-flex align-items-center">
                <div class="user-block mb-0">
                  <img src="<?= e($avatarUrl) ?>" alt="<?= e($video['display_name']) ?>" class="rounded-circle">
                  <span class="username"><a href="/<?= e($slug) ?>"><?= e($video['display_name']) ?></a> <small class="fw-normal text-secondary">ha pubblicato un video</small></span>
                  <span class="description"><?= e(formatItalianDate($video['publish_at'] ?: $video['created_at'], $artist)) ?></span>
                </div>
              </div>
              <div class="d-md-none">
                <?= $playerHtml ?>
              </div>
              <div class="card-body">
                <h2 class="h3 mb-3"><?= e($video['title']) ?></h2>
                <p class="mb-3">
                  <a href="/<?= e($slug) ?>/video" class="badge text-bg-secondary text-decoration-none me-1">Video</a>
                </p>
                <?php if (!empty($video['description'])): ?><div><?= nl2br(e($video['description'])) ?></div><?php endif; ?>
              </div>
              <div class="card-footer small text-secondary">
                <i class="bi bi-share me-1"></i>
                <strong>Condividi</strong> · <span class="user-select-all"><?= e($pageUrl) ?></span>
              </div>
            </article>

            <?= $moreVideosHtml ?>

            <p><a href="/<?= e($slug) ?>/video">← Tutti i video di <?= e($video['display_name']) ?></a></p>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Menù" a tema AdminLTE — menu.php. Tab delle categorie con piccolo JS vanilla
// (stesso principio del filtro Album/Timeline già usato nel tab Foto della Home): niente Bootstrap
// JS da caricare in più solo per questa pagina.
function renderAdminLteMenuPage(array $artist, string $slug, array $categories, array $itemsByCategory): string {
    $pageUrl = siteUrl('/' . $slug . '/menu');
    $hasAllergens = false;
    foreach ($itemsByCategory as $items) {
        foreach ($items as $it) {
            if (parseMenuAllergens($it['allergens'] ?? null)) { $hasAllergens = true; break 2; }
        }
    }
    $precontoActive = false;
    if (!empty($artist['menu_preconto_enabled'])) {
        foreach ($itemsByCategory as $items) {
            foreach ($items as $it) {
                if ($it['price'] !== null) { $precontoActive = true; break 2; }
            }
        }
    }
    $precontoMsg = $_GET['preconto_msg'] ?? '';
    $precontoErr = !empty($_GET['preconto_err']);
    $precontoFollowTerms = trim(getSiteSetting('follow_terms_content') ?: '');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Menù di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Menù di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'menu') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Menù') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Menù') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($categories): ?>
            <div class="card">
              <div class="card-header p-0 border-bottom-0">
                <ul class="nav nav-pills p-2 gap-1" id="menu-tabs">
                  <?php foreach ($categories as $index => $cat): ?>
                    <li class="nav-item"><button type="button" class="nav-link<?= $index === 0 ? ' active' : '' ?>" data-menu-cat="<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></button></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <div class="card-body">
                <?php foreach ($categories as $index => $cat): ?>
                <div class="menu-cat-panel<?= $index === 0 ? '' : ' d-none' ?>" data-menu-panel="<?= (int) $cat['id'] ?>">
                  <h3 class="h6 mb-3"><?= e($cat['name']) ?></h3>
                  <?php foreach ($itemsByCategory[(int) $cat['id']] as $it): $allergens = parseMenuAllergens($it['allergens'] ?? null); ?>
                    <div class="d-flex justify-content-between align-items-start border-bottom py-2">
                      <div>
                        <?= e($it['name']) ?><?php foreach ($allergens as $aId): ?><sup title="<?= e(MENU_ALLERGENS[$aId]) ?>">&nbsp;<?= $aId ?></sup><?php endforeach; ?>
                        <?php if ($it['description']): ?><div class="text-secondary small"><?= e($it['description']) ?></div><?php endif; ?>
                      </div>
                      <?php if ($it['price'] !== null): ?>
                        <div class="d-flex align-items-center gap-2 ms-3 flex-shrink-0">
                          <div class="fw-semibold text-nowrap">€ <?= e(number_format((float) $it['price'], 2, ',', '.')) ?></div>
                          <?php if ($precontoActive): ?>
                            <span class="preconto-stepper" data-id="<?= (int) $it['id'] ?>" data-price="<?= e((string) (float) $it['price']) ?>">
                              <button type="button" data-delta="-1">−</button>
                              <span class="preconto-qty">0</span>
                              <button type="button" data-delta="1">+</button>
                            </span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Il menù non è ancora disponibile.</div></div>
            <?php endif; ?>

            <?php if ($hasAllergens): ?>
              <div class="card mt-3">
                <div class="card-body text-secondary small">
                  Allergeni:
                  <?php foreach (MENU_ALLERGENS as $aId => $aLabel): ?><?= $aId ?>. <?= e($aLabel) ?><?= $aId < count(MENU_ALLERGENS) ? ' · ' : '' ?><?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($precontoActive): ?>
              <?php if ($precontoMsg): ?>
                <div class="alert <?= $precontoErr ? 'alert-danger' : 'alert-success' ?> mt-3"><?= e($precontoMsg) ?></div>
              <?php endif; ?>

              <div id="preconto-bar" style="display:none;position:fixed;left:0;right:0;bottom:0;z-index:1040;background:#fff;box-shadow:0 -2px 12px rgba(0,0,0,.2);padding:12px 16px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <button type="button" id="preconto-bar-locked" class="btn btn-link text-decoration-none p-0 fw-bold">🧮 Calcola il tuo preconto</button>
                <div id="preconto-bar-unlocked" style="display:none;align-items:center;gap:14px;flex-wrap:wrap;" class="d-flex">
                  <span>Totale: <strong id="preconto-total">€ 0,00</strong></span>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="preconto-reset-btn">Svuota</button>
                </div>
              </div>

              <div id="preconto-modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1050;align-items:center;justify-content:center;padding:16px;">
                <div id="preconto-modal" class="card" style="max-width:380px;width:100%;max-height:90vh;overflow-y:auto;">
                  <div class="card-body">
                    <h3 class="h6">Lascia i tuoi dati per attivare il preconto</h3>
                    <form method="post" action="/menu_preconto.php">
                      <?= csrfField() ?>
                      <input type="hidden" name="slug" value="<?= e($slug) ?>">
                      <div class="mb-2"><label class="form-label small mb-0">Nome</label><input type="text" name="first_name" class="form-control form-control-sm" required></div>
                      <div class="mb-2"><label class="form-label small mb-0">Cognome</label><input type="text" name="last_name" class="form-control form-control-sm" required></div>
                      <div class="mb-2"><label class="form-label small mb-0">Email</label><input type="email" name="email" class="form-control form-control-sm" required></div>
                      <div class="mb-2"><label class="form-label small mb-0">Telefono</label><input type="tel" name="phone" class="form-control form-control-sm" required></div>
                      <div class="mb-2"><label class="form-label small mb-0">CAP</label><input type="text" name="postal_code" class="form-control form-control-sm" required maxlength="10"></div>
                      <?php if ($precontoFollowTerms !== ''): ?>
                        <div class="form-check mt-2">
                          <input type="checkbox" name="accept_terms" value="1" class="form-check-input" id="preconto-accept-terms" required>
                          <label class="form-check-label small" for="preconto-accept-terms">Accetto i <a href="/termini_segui.php" target="_blank" rel="noopener">Termini di Utilizzo</a></label>
                        </div>
                      <?php endif; ?>
                      <?= renderTurnstileWidget() ?>
                      <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary btn-sm">Conferma e attiva</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="preconto-modal-cancel">Annulla</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
              <style>
              .preconto-stepper { display: inline-flex; align-items: center; gap: 6px; }
              .preconto-stepper button { width: 24px; height: 24px; border-radius: 50%; border: none; background: #f0f0f0; cursor: pointer; font-size: 14px; line-height: 1; }
              .preconto-qty { min-width: 16px; text-align: center; font-weight: 600; }
              body.menu-preconto-active { padding-bottom: 64px; }
              </style>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<script>
(function () {
  var tabs = document.getElementById('menu-tabs');
  if (!tabs) return;
  tabs.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-menu-cat]');
    if (!btn) return;
    var catId = btn.getAttribute('data-menu-cat');
    tabs.querySelectorAll('.nav-link').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('[data-menu-panel]').forEach(function (p) {
      p.classList.toggle('d-none', p.getAttribute('data-menu-panel') !== catId);
    });
  });
})();
<?php if ($precontoActive): ?>
(function () {
  var userId = <?= (int) $artist['id'] ?>;
  var cookieName = 'preconto_ok_' + userId;
  var storageKey = 'preconto_qty_' + userId;
  var steppers = document.querySelectorAll('.preconto-stepper');
  if (!steppers.length) return;
  document.body.classList.add('menu-preconto-active');

  var bar = document.getElementById('preconto-bar');
  var barLocked = document.getElementById('preconto-bar-locked');
  var barUnlocked = document.getElementById('preconto-bar-unlocked');
  var totalEl = document.getElementById('preconto-total');
  var resetBtn = document.getElementById('preconto-reset-btn');
  var modalBackdrop = document.getElementById('preconto-modal-backdrop');
  var modalCancel = document.getElementById('preconto-modal-cancel');

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }
  function isUnlocked() {
    return !!getCookie(cookieName);
  }
  function loadQty() {
    try { return JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) { return {}; }
  }
  function saveQty(qty) {
    try { localStorage.setItem(storageKey, JSON.stringify(qty)); } catch (e) {}
  }
  function formatEuro(v) {
    return '€ ' + v.toFixed(2).replace('.', ',');
  }
  function render() {
    var unlocked = isUnlocked();
    bar.style.display = 'flex';
    barLocked.style.display = unlocked ? 'none' : 'inline-block';
    barUnlocked.style.display = unlocked ? 'flex' : 'none';
    var qty = loadQty();
    var total = 0;
    steppers.forEach(function (stepper) {
      var id = stepper.getAttribute('data-id');
      var price = parseFloat(stepper.getAttribute('data-price'));
      var q = unlocked ? (qty[id] || 0) : 0;
      total += q * price;
      stepper.querySelector('.preconto-qty').textContent = q;
    });
    totalEl.textContent = formatEuro(total);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.preconto-stepper button');
    if (btn) {
      if (!isUnlocked()) {
        modalBackdrop.style.display = 'flex';
        return;
      }
      var stepper = btn.closest('.preconto-stepper');
      var id = stepper.getAttribute('data-id');
      var delta = parseInt(btn.getAttribute('data-delta'), 10);
      var qty = loadQty();
      qty[id] = Math.max(0, (qty[id] || 0) + delta);
      saveQty(qty);
      render();
      return;
    }
    if (e.target.closest('#preconto-bar-locked')) {
      modalBackdrop.style.display = 'flex';
    }
  });
  resetBtn.addEventListener('click', function () {
    saveQty({});
    render();
  });
  modalCancel.addEventListener('click', function () {
    modalBackdrop.style.display = 'none';
  });

  render();
})();
<?php endif; ?>
</script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Elenco pubblico "Offerte speciali" a tema AdminLTE — offerte.php.
function renderAdminLteOfferteListPage(array $artist, string $slug, array $offers): string {
    $pageUrl = siteUrl('/' . $slug . '/offerte');
    $pageSize = 20;
    $finished = count($offers) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offerte speciali di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Offerte speciali di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'offerte') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Offerte') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Offerte') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (!$offers): ?>
              <div class="card"><div class="card-body text-secondary">Nessuna offerta attiva al momento.</div></div>
            <?php else: ?>
              <div id="adminlte-list-feed"><?= renderAdminLteOfferteRows($offers, $slug, $artist) ?></div>
              <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
              <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
              <div id="adminlte-list-sentinel" style="height:1px;"></div>
              <?= adminLteInfiniteScrollScript('offerte', $slug, count($offers), $pageSize, $finished) ?>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Dettaglio pubblico di una singola offerta speciale a tema AdminLTE — offerta.php.
function renderAdminLteOffertaDetailPage(array $artist, string $slug, array $offer, bool $isOwner, bool $isCurrentlyValid, bool $isPreview = false): string {
    $pageUrl = siteUrl('/' . $slug . '/offerte/' . (int) $offer['id']);
    if (!(int) $offer['is_active'] || !$isCurrentlyValid) {
        $pageUrl = withPreviewToken($pageUrl, 'offerta', (int) $offer['id']);
    }
    $ogImage = $offer['cover_path'] ? siteUrl($offer['cover_path']) : ($offer['avatar_path'] ? siteUrl($offer['avatar_path']) : null);
    $ogDescriptionParts = array_filter([$offer['price_label'], $offer['description'] ? textExcerpt($offer['description'], 160) : null]);
    $ogDescription = $ogDescriptionParts ? implode(' — ', $ogDescriptionParts) : ($offer['display_name'] . ' — scopri l\'offerta su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($offer['custom_feed_guid'] ?? null, $offer['custom_feed_guid_since'] ?? null, $offer['created_at']); ?>
<title><?= e($offer['title']) ?> — <?= e($offer['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($offer['title']) ?> — <?= e($offer['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($offer['title']) ?> — <?= e($offer['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'offerte') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Offerta', ['Offerte' => '/' . $slug . '/offerte']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Offerta') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (($isOwner || $isPreview) && (!(int) $offer['is_active'] || !$isCurrentlyValid)): ?>
              <div class="alert alert-warning">Questa offerta non è visibile al pubblico al momento (disattivata o fuori dal periodo di validità) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
            <?php endif; ?>
            <div class="card mb-3">
              <?php if ($offer['cover_path']): ?><img src="/<?= e($offer['cover_path']) ?>" alt="<?= e($offer['title']) ?>" class="card-img-top" style="max-height:400px;object-fit:cover;"><?php endif; ?>
              <div class="card-body text-center">
                <h1 class="h4 mb-1"><?= e($offer['title']) ?></h1>
                <?php if ($offer['price_label']): ?><p class="text-primary fw-bold fs-5"><?= e($offer['price_label']) ?></p><?php endif; ?>
                <?php if ($offer['valid_from'] || $offer['valid_until']): ?>
                  <p class="text-secondary">
                    <?= $offer['valid_from'] ? 'Dal ' . e(formatLocalDateTime($offer['valid_from'], $artist)) : '' ?>
                    <?= $offer['valid_from'] && $offer['valid_until'] ? ' — ' : '' ?>
                    <?= $offer['valid_until'] ? 'Fino al ' . e(formatLocalDateTime($offer['valid_until'], $artist)) : '' ?>
                  </p>
                <?php endif; ?>
                <?php if (!empty($offer['description'])): ?><p class="text-start mt-3"><?= nl2br(e($offer['description'])) ?></p><?php endif; ?>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Foto" (album + galleria Timeline) a tema AdminLTE — foto.php. Riusa la stessa
// griglia .ig-grid-item/.ig-lightbox già presente nel tab Foto della Home.
function renderAdminLteFotoPage(array $artist, string $slug, array $albums, array $timelinePhotos): string {
    $pageUrl = siteUrl('/' . $slug . '/foto');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Foto di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Foto di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if ($timelinePhotos): ?><link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>"><?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'foto') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Foto') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">

            <?php if ($albums): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title h6 mb-0">Album (<?= count($albums) ?>)</h3></div>
                <div class="card-body">
                  <div class="row g-3 text-center">
                    <?php foreach ($albums as $al): ?>
                      <div class="col-6 col-sm-4 col-lg-4">
                        <a href="/<?= e($slug) ?>/album/<?= (int) $al['id'] ?>" class="card text-decoration-none text-body p-2 h-100">
                          <?php if ($al['cover_path']): ?><img src="/<?= e($al['cover_path']) ?>" alt="" loading="lazy" class="rounded mb-2" style="width:100%;aspect-ratio:1;object-fit:cover;"><?php endif; ?>
                          <div class="small fw-semibold"><i class="bi bi-images me-1"></i><?= e($al['title']) ?></div>
                        </a>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($timelinePhotos): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title h6 mb-0">Foto (<?= count($timelinePhotos) ?>)</h3></div>
                <div class="card-body p-2">
                  <div class="row g-1">
                    <?php foreach ($timelinePhotos as $i => $ph): ?>
                      <div class="col-4 col-lg-3">
                        <a href="/<?= e($slug) ?>/timeline/<?= (int) $ph['post_id'] ?>" class="ig-grid-item d-block" data-lightbox="foto-grid" data-index="<?= $i ?>">
                          <img src="/<?= e($ph['photo']) ?>" alt="" loading="lazy" class="rounded-1" style="width:100%;aspect-ratio:1;object-fit:cover;">
                        </a>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
              <div class="ig-lightbox" data-post="foto-grid">
                <button type="button" class="ig-lightbox-close" aria-label="Chiudi">✕</button>
                <div class="ig-lightbox-track">
                  <?php foreach ($timelinePhotos as $ph): ?><img src="/<?= e($ph['photo']) ?>" alt="" loading="lazy"><?php endforeach; ?>
                </div>
                <button type="button" class="ig-arrow ig-arrow-prev" aria-label="Foto precedente">‹</button>
                <button type="button" class="ig-arrow ig-arrow-next" aria-label="Foto successiva">›</button>
                <div class="ig-lightbox-counter"></div>
              </div>
            <?php endif; ?>

            <?php if (!$albums && !$timelinePhotos): ?>
              <div class="card"><div class="card-body text-secondary">Nessuna foto ancora.</div></div>
            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if ($timelinePhotos): ?><script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Dettaglio pubblico di un album fotografico a tema AdminLTE — album_item.php.
function renderAdminLteAlbumDetailPage(array $artist, string $slug, array $album, array $photos, bool $isOwner, bool $isScheduledFuture, bool $isPreview = false): string {
    $pageUrl = siteUrl('/' . $slug . '/album/' . (int) $album['id']);
    if (!(int) $album['is_public'] || $isScheduledFuture) {
        $pageUrl = withPreviewToken($pageUrl, 'album_foto', (int) $album['id']);
    }
    $ogImage = $album['cover_path']
        ? siteUrl(count($photos) > 1 ? getFeedShareImage($album['cover_path']) : $album['cover_path'])
        : ($album['avatar_path'] ? siteUrl($album['avatar_path']) : null);
    $ogDescription = $album['description'] ? textExcerpt($album['description'], 160) : ($album['display_name'] . ' — scopri l\'album su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($album['custom_feed_guid'] ?? null, $album['custom_feed_guid_since'] ?? null, $album['created_at']); ?>
<title><?= e($album['title']) ?> — <?= e($album['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($album['title']) ?> — <?= e($album['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($album['title']) ?> — <?= e($album['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if ($photos): ?><link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>"><?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'foto') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Album', ['Foto' => '/' . $slug . '/foto']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <?php if (($isOwner || $isPreview) && (!(int) $album['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo album non è visibile al pubblico al momento (privato o programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($album['cover_path']): ?><img src="/<?= e($album['cover_path']) ?>" alt="" class="rounded mb-3" style="max-width:280px;width:100%;aspect-ratio:1;object-fit:cover;"><?php endif; ?>
                <h1 class="h4 mb-1"><?= e($album['title']) ?></h1>
                <p class="text-secondary">Album di <?= e($album['display_name']) ?> · <?= count($photos) ?> foto</p>
                <?php if (!empty($album['description'])): ?><p class="text-start mt-2"><?= nl2br(e($album['description'])) ?></p><?php endif; ?>
              </div>
            </div>
            <?php if ($photos): ?>
              <div class="card mb-3">
                <div class="card-body p-2">
                  <div class="row g-1">
                    <?php foreach ($photos as $i => $ph): ?>
                      <div class="col-4 col-lg-3">
                        <a href="/<?= e($ph) ?>" class="ig-grid-item d-block" data-lightbox="album-<?= (int) $album['id'] ?>" data-index="<?= $i ?>">
                          <img src="/<?= e($ph) ?>" alt="" loading="lazy" class="rounded-1" style="width:100%;aspect-ratio:1;object-fit:cover;">
                        </a>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
              <div class="ig-lightbox" data-post="album-<?= (int) $album['id'] ?>">
                <button type="button" class="ig-lightbox-close" aria-label="Chiudi">✕</button>
                <div class="ig-lightbox-track">
                  <?php foreach ($photos as $ph): ?><img src="/<?= e($ph) ?>" alt="" loading="lazy"><?php endforeach; ?>
                </div>
                <button type="button" class="ig-arrow ig-arrow-prev" aria-label="Foto precedente">‹</button>
                <button type="button" class="ig-arrow ig-arrow-next" aria-label="Foto successiva">›</button>
                <div class="ig-lightbox-counter"></div>
              </div>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Nessuna foto in questo album.</div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if ($photos): ?><script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Elenco pubblico "Servizi" a tema AdminLTE — servizi.php.
function renderAdminLteServiziListPage(array $artist, string $slug, array $services): string {
    $pageUrl = siteUrl('/' . $slug . '/servizi');
    $pageSize = 20;
    $finished = count($services) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Servizi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Servizi di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'servizi') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Servizi') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Servizi') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (!$services): ?>
              <div class="card"><div class="card-body text-secondary">Nessun servizio pubblicato al momento.</div></div>
            <?php else: ?>
              <div id="adminlte-list-feed"><?= renderAdminLteServiziRows($services, $slug) ?></div>
              <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
              <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
              <div id="adminlte-list-sentinel" style="height:1px;"></div>
              <?= adminLteInfiniteScrollScript('servizi', $slug, count($services), $pageSize, $finished) ?>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Dettaglio pubblico di un singolo servizio a tema AdminLTE — servizio_item.php (con eventuale
// modulo "Richiedi informazioni", stessa logica di invio/notifica del Colorful, solo grafica diversa).
function renderAdminLteServizioDetailPage(array $artist, string $slug, array $service, array $photos, bool $isOwner, bool $isScheduledFuture, bool $formSent, ?string $formError, ?string $conversionEventId, bool $isPreview = false): string {
    $pageUrl = siteUrl('/' . $slug . '/servizi/' . (int) $service['id']);
    if (!(int) $service['is_public'] || $isScheduledFuture) {
        $pageUrl = withPreviewToken($pageUrl, 'servizio', (int) $service['id']);
    }
    $ogImage = $service['cover_path'] ? siteUrl($service['cover_path']) : ($service['avatar_path'] ? siteUrl($service['avatar_path']) : null);
    $ogDescription = $service['description'] ? textExcerpt($service['description'], 160) : ($service['display_name'] . ' — scopri il servizio su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($service['custom_feed_guid'] ?? null, $service['custom_feed_guid_since'] ?? null, $service['created_at']); ?>
<title><?= e($service['title']) ?> — <?= e($service['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($service['title']) ?> — <?= e($service['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($service['title']) ?> — <?= e($service['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if ($photos): ?><link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>"><?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'servizi') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Servizio', ['Servizi' => '/' . $slug . '/servizi']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Servizio') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (($isOwner || $isPreview) && (!(int) $service['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo servizio non è visibile al pubblico al momento (privato o programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?= renderPhotoCarousel($photos, (int) $service['id']) ?>
                <h1 class="h4 mb-1"><?= e($service['title']) ?></h1>
                <?php if (!empty($service['description'])): ?><p class="text-start mt-2"><?= nl2br(e($service['description'])) ?></p><?php endif; ?>
              </div>
            </div>

            <?php if ((int) $service['accepts_inquiries'] === 1): ?>
            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title">Richiedi informazioni</h3></div>
              <div class="card-body">
                <?php if ($formSent): ?>
                  <div class="alert alert-success mb-0">Richiesta inviata! Verrai ricontattato al più presto.</div>
                  <?= embedClientSideConversionEvent('Lead', $conversionEventId, $artist) ?>
                <?php else: ?>
                  <?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>
                  <form method="post">
                    <?= csrfField() ?>
                    <div class="mb-2"><label class="form-label">Nome</label><input type="text" name="guest_name" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Email</label><input type="email" name="guest_email" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Telefono (opzionale)</label><input type="tel" name="guest_phone" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Messaggio (opzionale)</label><textarea name="message" rows="4" class="form-control"></textarea></div>
                    <?= renderTurnstileWidget() ?>
                    <button type="submit" class="btn btn-primary">Invia richiesta</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if ($photos): ?><script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}


function renderAdminLteEventiListPage(array $artist, string $slug, array $events, ?string $provinciaFilter = null, array $expiredEvents = [], array $mapPoints = [], string $cittaFilter = '', array $filterOptions = []): string {
    $pageUrl = siteUrl('/' . $slug . '/eventi');
    $pageSize = 20;
    $finished = count($events) < $pageSize;
    $extraQuery = ($provinciaFilter !== null && $provinciaFilter !== '' ? '&provincia=' . urlencode($provinciaFilter) : '')
                . ($cittaFilter !== '' ? '&citta=' . urlencode($cittaFilter) : '');
    $filterLabel = trim(($cittaFilter !== '' ? $cittaFilter : '') . ($cittaFilter !== '' && (string) $provinciaFilter !== '' ? ' · ' : '') . ((string) $provinciaFilter !== '' ? 'provincia di ' . $provinciaFilter : ''));
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eventi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Eventi di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'eventi') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Eventi') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?php /* Pagina Eventi: elenco a sinistra (scorre) e mappa a destra (fissa) al posto della terza colonna. */ ?>
          <div class="col-lg-9 order-1 order-lg-2 adminlte-main-col adminlte-main-wide">
          <div class="row g-3">
          <div class="col-md-6">
            <?= renderAdminLteEventiFilterCard($slug, (string) $provinciaFilter, $cittaFilter, $filterOptions) ?>
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Eventi') ?><?php if ($extraQuery !== ''): ?> <span class="fw-normal text-secondary">— <?= e($filterLabel) ?></span><?php endif; ?></h3>
                <div class="card-tools">
                  <?php if ($extraQuery !== ''): ?>
                  <a href="/<?= e($slug) ?>/eventi" class="btn btn-tool" title="Togli il filtro"><i class="bi bi-x-lg"></i></a>
                  <?php endif; ?>
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (!$events): ?>
              <div class="card"><div class="card-body text-secondary"><?= $extraQuery !== '' ? 'Nessun evento con questi filtri.' : 'Nessun evento in programma al momento.' ?></div></div>
            <?php else: ?>
              <div id="adminlte-list-feed"><?= renderAdminLteEventiRows($events, $slug, $artist) ?></div>
              <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
              <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
              <div id="adminlte-list-sentinel" style="height:1px;"></div>
              <?= adminLteInfiniteScrollScript('eventi', $slug, count($events), $pageSize, $finished, $extraQuery) ?>
            <?php endif; ?>
            <?php if ($extraQuery !== '' && $expiredEvents): ?>
              <hr class="my-4">
              <h3 class="fs-6 text-secondary mb-3"><i class="bi bi-clock-history me-1"></i>Eventi scaduti</h3>
              <div style="opacity:.65;"><?= renderAdminLteEventiRows($expiredEvents, $slug, $artist) ?></div>
            <?php endif; ?>
            </div>
            </div>
          </div>
          <div class="col-md-6">
            <?= renderAdminLteEventiMapCard($mapPoints, $slug, count($events)) ?>
          </div>
          </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Dettaglio pubblico di un singolo evento a tema AdminLTE — evento.php (con eventuale modulo
// "Prenota", stesso form/azione del Colorful — invariato, solo grafica diversa).
function renderAdminLteEventoDetailPage(array $artist, string $slug, array $event, ?string $scheduleLabel, string $resMsg, bool $resErr): string {
    $pageUrl = siteUrl('/' . $slug . '/eventi/' . (int) $event['id']);
    $ogImage = $event['cover_path'] ? siteUrl($event['cover_path']) : ($event['avatar_path'] ? siteUrl($event['avatar_path']) : null);
    $locationLine = trim(($event['venue'] ?: '') . ($event['venue'] && $event['city'] ? ', ' : '') . ($event['city'] ?: ''));
    $ogDescription = trim($event['display_name'] . ' — ' . formatLocalDateTime($event['event_date'], $artist) . ($locationLine ? ' · ' . $locationLine : ''));

    // Da desktop la copertina si sposta nella colonna laterale destra, a piena altezza — stessa
    // logica già applicata alla pagina di un articolo del blog (renderAdminLteBlogPostPage()). Su
    // mobile resta invece dov'era sempre stata, dentro la card dell'evento.
    $eventCoverSidebarHtml = '';
    if ($event['cover_path']) {
        ob_start();
        ?>
        <div class="card mb-3 d-none d-md-block">
          <div class="position-relative">
            <img src="/<?= e($event['cover_path']) ?>" alt="<?= e($event['title']) ?>" class="card-img-top" style="width:100%;height:auto;display:block;">
            <?php if ($scheduleLabel): ?><span class="badge text-bg-primary position-absolute top-0 start-0 m-2"><i class="bi bi-arrow-repeat me-1"></i><?= e($scheduleLabel) ?></span><?php endif; ?>
          </div>
        </div>
        <?php
        $eventCoverSidebarHtml = ob_get_clean();
    }

    // Altri eventi della stessa provincia (stessa query della pagina Eventi filtrata per
    // provincia, esclude solo l'evento corrente), sotto al modulo di prenotazione nella colonna
    // centrale — così chi guarda un evento scopre subito cos'altro c'è nella stessa zona.
    $otherProvinciaEventsHtml = '';
    if (!empty($event['provincia'])) {
        $stmt = getDB()->prepare('SELECT * FROM events WHERE user_id=? AND provincia = ? AND id <> ? AND (event_date >= NOW() OR is_perpetual = 1) AND (publish_at IS NULL OR publish_at <= NOW()) ORDER BY is_perpetual DESC, event_date ASC LIMIT 20');
        $stmt->execute([$event['user_id'], $event['provincia'], $event['id']]);
        $otherProvinciaEvents = $stmt->fetchAll();
        if ($otherProvinciaEvents) {
            ob_start();
            ?>
            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title">Altri eventi a <?= e($event['provincia']) ?></h3></div>
              <div class="card-body">
                <?= renderAdminLteEventiRows($otherProvinciaEvents, $slug, $artist) ?>
              </div>
            </div>
            <?php
            $otherProvinciaEventsHtml = ob_get_clean();
        }
    }

    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($event['custom_feed_guid'] ?? null, $event['custom_feed_guid_since'] ?? null, $event['created_at']); ?>
<title><?= e($event['title']) ?> — <?= e($event['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($event['title']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($event['title']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'eventi') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Evento', ['Eventi' => '/' . $slug . '/eventi']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, $eventCoverSidebarHtml, true, $event['provincia'] ?? null) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Evento') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <div class="card mb-3">
              <?php if ($event['cover_path']): ?>
                <div class="position-relative d-md-none">
                  <img src="/<?= e($event['cover_path']) ?>" alt="<?= e($event['title']) ?>" class="card-img-top" style="max-height:400px;object-fit:cover;">
                  <?php if ($scheduleLabel): ?><span class="badge text-bg-primary position-absolute top-0 start-0 m-2"><i class="bi bi-arrow-repeat me-1"></i><?= e($scheduleLabel) ?></span><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="card-body text-center">
                <h1 class="h4 mb-1"><?= e($event['title']) ?></h1>
                <p class="text-secondary mb-1"><?= e(formatLocalDateTime($event['event_date'], $artist)) ?></p>
                <?php if ($locationLine): ?><p class="text-secondary"><?= e($locationLine) ?></p><?php endif; ?>
                <?php if ($scheduleLabel && !$event['cover_path']): ?><p><span class="badge text-bg-primary"><i class="bi bi-arrow-repeat me-1"></i><?= e($scheduleLabel) ?></span></p><?php endif; ?>
                <?php if (!empty($event['description'])): ?><p class="text-start mt-2"><?= nl2br(e($event['description'])) ?></p><?php endif; ?>
                <?php if ($event['ticket_url']): ?><a class="btn btn-primary mt-2" href="<?= e($event['ticket_url']) ?>" target="_blank" rel="noopener">Biglietti →</a><?php endif; ?>
                <?= renderAddToCalendarLinks($event, $slug, $artist, 'btn btn-outline-secondary mt-2 ms-1') ?>
              </div>
            </div>

            <?php if ($resMsg): ?><div class="alert <?= $resErr ? 'alert-danger' : 'alert-success' ?>"><?= e($resMsg) ?></div><?php endif; ?>

            <?php if ((int) $event['accepts_reservations'] === 1): ?>
            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title">Prenota</h3></div>
              <div class="card-body">
                <form method="post" action="/reserve_table.php">
                  <?= csrfField() ?>
                  <input type="hidden" name="slug" value="<?= e($slug) ?>">
                  <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                  <div class="mb-2"><label class="form-label">Nome e cognome</label><input type="text" name="guest_name" class="form-control" required></div>
                  <div class="mb-2"><label class="form-label">Email</label><input type="email" name="guest_email" class="form-control" required></div>
                  <div class="mb-2"><label class="form-label">Telefono (facoltativo)</label><input type="tel" name="guest_phone" class="form-control"></div>
                  <div class="mb-2"><label class="form-label">Numero di persone</label><input type="number" name="party_size" min="1" max="50" value="2" class="form-control" required></div>
                  <div class="mb-2"><label class="form-label">Note (facoltative)</label><input type="text" name="notes" class="form-control" placeholder="es. seggiolone, allergie, ..."></div>
                  <div class="form-check mb-3">
                    <input type="checkbox" name="marketing_opt_in" value="1" class="form-check-input" id="mktOptIn">
                    <label class="form-check-label" for="mktOptIn">Voglio ricevere aggiornamenti su nuovi eventi e offerte da <?= e($event['display_name']) ?></label>
                  </div>
                  <?= renderTurnstileWidget() ?>
                  <button type="submit" class="btn btn-primary">Prenota</button>
                </form>
              </div>
            </div>
            <?php endif; ?>

            <?= $otherProvinciaEventsHtml ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Pagina pubblica "Contatti" a tema AdminLTE — contatti.php (stesso form/azione del Colorful,
// invariato, solo grafica diversa).
function renderAdminLteContattiPage(array $artist, string $slug, bool $formSent, ?string $formError, ?string $conversionEventId): string {
    $pageUrl = siteUrl('/' . $slug . '/contatti');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contatti — <?= e($artist['display_name']) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Contatta <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'contatti') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Contatti') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Contatti') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($formSent): ?>
              <div class="alert alert-success">Messaggio inviato! Grazie, verrai ricontattato al più presto.</div>
              <?= embedClientSideConversionEvent('Contact', $conversionEventId, $artist) ?>
            <?php else: ?>
              <?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>
              <div class="card">
                <div class="card-body">
                  <form method="post">
                    <?= csrfField() ?>
                    <div class="mb-2"><label class="form-label">Nome</label><input type="text" name="sender_name" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Email</label><input type="email" name="sender_email" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Messaggio</label><textarea name="message" rows="4" class="form-control" required></textarea></div>
                    <?= renderTurnstileWidget() ?>
                    <button type="submit" class="btn btn-primary">Invia messaggio</button>
                  </form>
                </div>
              </div>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}
