<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userSlug = $_GET['slug'] ?? '';
$postToken = $_GET['post'] ?? '';

if (!preg_match('/^\d{4}\.\d{2}\.\d{2}\.(.+)$/', $postToken, $m)) {
    http_response_code(404);
    exit('Articolo non trovato.');
}
$postSlug = $m[1];

$stmt = getDB()->prepare('SELECT u.slug AS user_slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.privacy_tracking_settings, p.genere, p.custom_feed_guid, p.custom_feed_guid_since, b.*
                          FROM blog_posts b
                          JOIN users u ON u.id = b.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND b.slug = ? AND u.is_active = 1');
$stmt->execute([$userSlug, $postSlug]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    exit('Articolo non trovato.');
}

// Un articolo ancora programmato per il futuro non è raggiungibile da nessun altro, nemmeno con
// il link diretto — stessa regola già in uso per Timeline/Che Amo/Album/Servizi/Offerte. Il
// proprietario e chi ha un link di anteprima valido (vedi previewToken()) continuano a vederlo.
$isOwner = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $post['user_id'];
$isScheduledFuture = $post['published_at'] && strtotime($post['published_at']) > time();
$isPreview = !$isOwner && previewTokenValid('blog', (int) $post['id'], $_GET['preview'] ?? null);
if (!$isOwner && !$isPreview && $isScheduledFuture) {
    http_response_code(404);
    exit('Articolo non trovato.');
}

// Per riusare l'header condiviso serve un array "artista" con le chiavi attese
$artist = [
    'id' => $post['user_id'],
    'slug' => $userSlug,
    'display_name' => $post['display_name'],
    'avatar_path' => $post['avatar_path'],
    'spotify_artist_id' => $post['spotify_artist_id'] ?? null,
    'spotify_show_id' => $post['spotify_show_id'] ?? null,
    'account_type' => $post['account_type'] ?? 'band',
    'page_theme' => $post['page_theme'] ?? 'colorful',
    'genere' => $post['genere'] ?? null,
    'youtube_channel_id' => $post['youtube_channel_id'] ?? null,
    'privacy_tracking_settings' => $post['privacy_tracking_settings'] ?? null,
    'dashboard_theme' => $post['dashboard_theme'] ?? null,
];

// Tema "AdminLTE": stesso principio "a scena" della Home (vedi u.php).
if (($artist['page_theme'] ?? 'colorful') === 'adminlte-profile') {
    echo renderAdminLteBlogPostPage($post, $artist, $userSlug, $isOwner, $isScheduledFuture, $isPreview);
    exit;
}

$permalink = siteUrl(blogPostUrl($userSlug, $post));
if ($isScheduledFuture) {
    $permalink = withPreviewToken($permalink, 'blog', (int) $post['id']);
}
$ogImage = $post['cover_path'] ? siteUrl($post['cover_path']) : ($post['avatar_path'] ? siteUrl($post['avatar_path']) : null);
$postCategories = getBlogPostCategories((int) $post['id']);
$linkedAlbum = null;
if (!empty($post['album_id'])) {
    $albStmt = getDB()->prepare('SELECT id, title, cover_path FROM photo_albums WHERE id=? AND user_id=?');
    $albStmt->execute([$post['album_id'], $post['user_id']]);
    $linkedAlbum = $albStmt->fetch() ?: null;
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($post['title']) ?> — <?= e($post['display_name']) ?></title>
<meta name="description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">

<meta property="og:type" content="article">
<meta property="og:title" content="<?= e($post['title']) ?>">
<meta property="og:description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">
<meta property="og:url" content="<?= e($permalink) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($post['title']) ?>">
<meta name="twitter:description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($permalink) ?>">
<?= blogPostingJsonLd($post, $post['display_name'], $permalink, $ogImage) ?>
<?= breadcrumbJsonLd([
    ['name' => $post['display_name'], 'url' => siteUrl('/' . $userSlug)],
    ['name' => 'Blog', 'url' => siteUrl('/' . $userSlug . '/blog')],
    ['name' => $post['title'], 'url' => $permalink],
]) ?>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($post['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($post['theme_color'])) ?>; }</style>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="<?= e(getPageThemeClass($artist['page_theme'] ?? 'colorful')) ?>">
<?php if (str_starts_with($artist['page_theme'] ?? 'colorful', 'wave')): ?><?= renderWaveBackground($artist['theme_color'] ?? '#6C5CE7', $artist['page_theme']) ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'circuit'): ?><?= renderCircuitBackground($artist['theme_color'] ?? '#6C5CE7') ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'napoli'): ?><?= renderNapoliBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'cinemapop'): ?><?= renderCinemaPopBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'startrek'): ?><?= renderStarTrekBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'galactic'): ?><?= renderGalacticBackground() ?><?php endif; ?>
<?= embedTrackingBodyStart($artist) ?>
<div class="container">
  <?= publicProfileHeader($artist, 'blog') ?>

  <?php if (($isOwner || $isPreview) && $isScheduledFuture): ?>
    <div class="alert error">Questo articolo non è ancora pubblico (programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
  <?php endif; ?>

  <?php // .blog-item (pensata per le righe della lista, senza margini laterali) qui vince
        // su .card nel CSS e azzera il padding orizzontale: va ripristinato esplicitamente,
        // altrimenti il testo dell'articolo tocca i bordi del riquadro. ?>
  <article class="blog-item card" style="border-bottom:none;padding:26px 24px;">
    <?php if ($post['cover_path']): ?>
      <img src="/<?= e($post['cover_path']) ?>" alt="<?= e($post['title']) ?>"
           style="width:100%;max-width:400px;display:block;margin:0 auto 16px;border-radius:14px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.15);">
    <?php endif; ?>
    <div class="date"><?= e(formatLocalDateTime($post['published_at'], $artist)) ?></div>
    <h2><?= e($post['title']) ?></h2>
    <?php if ($postCategories): ?>
      <p style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0;">
        <?php foreach ($postCategories as $cat): ?>
          <a href="<?= e(blogCategoryUrl($userSlug, $cat)) ?>" class="color-link-btn" style="padding:4px 12px;font-size:12.5px;font-weight:700;background:rgba(var(--text-rgb),0.08);"><?= e($cat['name']) ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <div><?= nl2br(e($post['content'])) ?></div>
    <?php if ($post['tags']): ?>
      <p style="margin-top:16px;color:rgba(var(--text-rgb),0.6);font-size:13px;">🏷️ <?= e($post['tags']) ?></p>
    <?php endif; ?>
  </article>

  <?php if ($linkedAlbum): ?>
    <a href="/<?= e($userSlug) ?>/album/<?= (int) $linkedAlbum['id'] ?>" class="card" style="margin-top:16px;display:flex;align-items:center;gap:14px;text-decoration:none;color:inherit;">
      <?php if ($linkedAlbum['cover_path']): ?>
        <img src="/<?= e($linkedAlbum['cover_path']) ?>" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <span>
        <small style="display:block;color:rgba(var(--text-rgb),0.6);">📷 Album collegato</small>
        <strong><?= e($linkedAlbum['title']) ?></strong>
      </span>
    </a>
  <?php endif; ?>

  <div class="card" style="margin-top:24px;">
    <strong>Condividi questo articolo</strong><br>
    <small style="color:rgba(var(--text-rgb),0.75);"><?= e($permalink) ?></small>
  </div>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
