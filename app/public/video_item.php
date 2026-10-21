<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/videos.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$videoId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, p.privacy_tracking_settings, v.*
                          FROM videos v
                          JOIN users u ON u.id = v.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND v.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $videoId]);
$video = $stmt->fetch();

if (!$video) {
    http_response_code(404);
    exit('Video non trovato.');
}

$isOwner = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $video['user_id'];
$isScheduledFuture = $video['publish_at'] && strtotime($video['publish_at']) > time();
$isPreview = !$isOwner && previewTokenValid('video', (int) $video['id'], $_GET['preview'] ?? null);
if (!$isOwner && !$isPreview && (!(int) $video['is_public'] || $isScheduledFuture)) {
    http_response_code(404);
    exit('Video non trovato.');
}

$artist = [
    'id' => $video['user_id'],
    'slug' => $slug,
    'display_name' => $video['display_name'],
    'avatar_path' => $video['avatar_path'],
    'spotify_artist_id' => $video['spotify_artist_id'],
    'spotify_show_id' => $video['spotify_show_id'],
    'youtube_channel_id' => $video['youtube_channel_id'],
    'privacy_tracking_settings' => $video['privacy_tracking_settings'] ?? null,
    'genere' => $video['genere'],
    'account_type' => $video['account_type'],
    'page_theme' => $video['page_theme'] ?? 'colorful',
    'dashboard_theme' => $video['dashboard_theme'] ?? null,
];

$pageUrl = videoPublicUrl($slug, $videoId);
if (!(int) $video['is_public'] || $isScheduledFuture) {
    $pageUrl = withPreviewToken($pageUrl, 'video', $videoId);
}
$videoUrl = siteUrl('/' . $video['video_path']);
$ogImage = !empty($video['thumb_path']) ? siteUrl('/' . $video['thumb_path']) : ($video['avatar_path'] ? siteUrl($video['avatar_path']) : null);
$ogDescription = $video['description'] ? textExcerpt($video['description'], 160) : ($video['display_name'] . ' — guarda il video su ' . siteName());

if (($artist['page_theme'] ?? 'colorful') === 'adminlte-profile') {
    echo renderAdminLteVideoDetailPage($artist, $slug, $video, $isOwner, $isScheduledFuture, $isPreview);
    exit;
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($video['title']) ?> — <?= e($video['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">

<meta property="og:type" content="video.other">
<meta property="og:video" content="<?= e($videoUrl) ?>">
<meta property="og:video:type" content="<?= e($video['mime_type']) ?>">
<meta property="og:title" content="<?= e($video['title']) ?> — <?= e($video['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($video['title']) ?> — <?= e($video['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($video['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($video['theme_color'])) ?>; }</style>
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
  <?= publicProfileHeader($artist, 'video') ?>

  <?php if (($isOwner || $isPreview) && (!(int) $video['is_public'] || $isScheduledFuture)): ?>
    <div class="alert error">Questo video non è visibile al pubblico al momento (privato o programmato per il futuro) — <?= e(previewNoticeSuffix($isOwner)) ?></div>
  <?php endif; ?>

  <div class="card" style="padding:0;overflow:hidden;">
    <video controls playsinline preload="metadata" style="width:100%;display:block;background:#000;" <?= !empty($video['thumb_path']) ? 'poster="/' . e($video['thumb_path']) . '"' : '' ?>>
      <source src="<?= e($videoUrl) ?><?= empty($video['thumb_path']) ? '#t=1' : '' ?>" type="<?= e($video['mime_type']) ?>">
      Il tuo browser non supporta la riproduzione video.
    </video>
    <div style="padding:16px;">
      <h1 style="font-size:22px;margin:0 0 4px;"><?= e($video['title']) ?></h1>
      <p style="opacity:0.75;margin-top:0;">Video di <?= e($video['display_name']) ?></p>
      <?php if (!empty($video['description'])): ?>
        <p style="color:rgba(var(--text-rgb),0.9);margin:12px 0 0;"><?= nl2br(e($video['description'])) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <p><a href="/<?= e($slug) ?>/video">← Tutti i video di <?= e($video['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>

</body>
</html>
