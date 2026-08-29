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

$stmt = getDB()->prepare('SELECT u.slug AS user_slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.genere, p.custom_feed_guid, p.custom_feed_guid_since, b.*
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
];

$permalink = siteUrl(blogPostUrl($userSlug, $post));
$ogImage = $post['cover_path'] ? siteUrl($post['cover_path']) : ($post['avatar_path'] ? siteUrl($post['avatar_path']) : null);
?>
<!doctype html>
<html lang="it">
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

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($post['title']) ?>">
<meta name="twitter:description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($permalink) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($post['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($post['theme_color'])) ?>; }</style>
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body class="<?= e(getPageThemeClass($artist['page_theme'] ?? 'colorful')) ?>">
<?php if (str_starts_with($artist['page_theme'] ?? 'colorful', 'wave')): ?><?= renderWaveBackground($artist['theme_color'] ?? '#6C5CE7', $artist['page_theme']) ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'circuit'): ?><?= renderCircuitBackground($artist['theme_color'] ?? '#6C5CE7') ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'napoli'): ?><?= renderNapoliBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'startrek'): ?><?= renderStarTrekBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'galactic'): ?><?= renderGalacticBackground() ?><?php endif; ?>
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'blog') ?>

  <article class="blog-item card" style="border-bottom:none;">
    <?php if ($post['cover_path']): ?>
      <img src="/<?= e($post['cover_path']) ?>" alt="<?= e($post['title']) ?>"
           style="width:100%;max-width:400px;display:block;margin:0 auto 16px;border-radius:14px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.15);">
    <?php endif; ?>
    <div class="date"><?= date('d/m/Y', strtotime($post['published_at'])) ?></div>
    <h2><?= e($post['title']) ?></h2>
    <div><?= nl2br(e($post['content'])) ?></div>
  </article>

  <div class="card" style="margin-top:24px;">
    <strong>Condividi questo articolo</strong><br>
    <small style="color:rgba(var(--text-rgb),0.75);"><?= e($permalink) ?></small>
  </div>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($userSlug) ?>
</body>
</html>
