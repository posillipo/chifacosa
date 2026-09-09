<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$albumId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, p.privacy_tracking_settings, p.custom_feed_guid, p.custom_feed_guid_since, pa.*
                          FROM photo_albums pa
                          JOIN users u ON u.id = pa.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND pa.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $albumId]);
$album = $stmt->fetch();

if (!$album) {
    http_response_code(404);
    exit('Album non trovato.');
}

$isOwner = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $album['user_id'];
$isScheduledFuture = $album['publish_at'] && strtotime($album['publish_at']) > time();
if (!$isOwner && (!(int) $album['show_in_feed'] || $isScheduledFuture)) {
    http_response_code(404);
    exit('Album non trovato.');
}

$artist = [
    'id' => $album['user_id'],
    'slug' => $slug,
    'display_name' => $album['display_name'],
    'avatar_path' => $album['avatar_path'],
    'spotify_artist_id' => $album['spotify_artist_id'],
    'spotify_show_id' => $album['spotify_show_id'],
    'youtube_channel_id' => $album['youtube_channel_id'],
    'privacy_tracking_settings' => $album['privacy_tracking_settings'] ?? null,
    'genere' => $album['genere'],
    'account_type' => $album['account_type'],
    'page_theme' => $album['page_theme'] ?? 'colorful',
    'dashboard_theme' => $album['dashboard_theme'] ?? null,
];

// Copertina + eventuali foto extra, nello stesso ordine di caricamento — stesso carosello già
// usato per i post Timeline/Viaggi con più foto (renderPhotoCarousel(), niente di nuovo qui).
$photos = array_values(array_filter(array_merge([$album['cover_path']], getAlbumPhotos($albumId))));

$pageUrl = siteUrl('/' . $slug . '/album/' . $albumId);
$ogImage = $album['cover_path'] ? siteUrl($album['cover_path']) : ($album['avatar_path'] ? siteUrl($album['avatar_path']) : null);
$ogDescription = $album['description'] ? textExcerpt($album['description'], 160) : ($album['display_name'] . ' — scopri l\'album su ' . siteName());
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($album['custom_feed_guid'], $album['custom_feed_guid_since'], $album['created_at']); ?>
<title><?= e($album['title']) ?> — <?= e($album['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($album['title']) ?> — <?= e($album['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($album['title']) ?> — <?= e($album['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($album['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($album['theme_color'])) ?>; }</style>
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
  <?= publicProfileHeader($artist, 'foto') ?>

  <?php if ($isOwner && (!(int) $album['show_in_feed'] || $isScheduledFuture)): ?>
    <div class="alert error">Questo album non è visibile al pubblico al momento (privato o programmato per il futuro) — lo vedi solo tu, come proprietario del profilo.</div>
  <?php endif; ?>

  <div class="card" style="text-align:center;">
    <?= renderPhotoCarousel($photos, $albumId) ?>
    <h1 style="font-size:22px;margin:0 0 4px;"><?= e($album['title']) ?></h1>
    <p style="opacity:0.75;margin-top:0;">Album di <?= e($album['display_name']) ?> · <?= count($photos) ?> foto</p>
    <?php if (!empty($album['description'])): ?>
      <p style="text-align:left;color:rgba(var(--text-rgb),0.9);margin:16px 0 0;"><?= nl2br(e($album['description'])) ?></p>
    <?php endif; ?>
  </div>

  <p><a href="/<?= e($slug) ?>/foto">← Tutte le foto di <?= e($album['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>

<?php if (count($photos) > 1): ?>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>">
<script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script>
<?php endif; ?>
</body>
</html>
