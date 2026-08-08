<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$trackId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, t.*
                          FROM audio_tracks t
                          JOIN users u ON u.id = t.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND t.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $trackId]);
$track = $stmt->fetch();

if (!$track) {
    http_response_code(404);
    exit('Brano non trovato.');
}

// Per riusare l'header condiviso serve un array "artista" con le chiavi attese
$artist = [
    'id' => $track['user_id'],
    'slug' => $slug,
    'display_name' => $track['display_name'],
    'avatar_path' => $track['avatar_path'],
    'spotify_artist_id' => $track['spotify_artist_id'],
    'spotify_show_id' => $track['spotify_show_id'],
    'account_type' => $track['account_type'] ?? 'band',
    'page_theme' => $track['page_theme'] ?? 'colorful',
    'genere' => $track['genere'],
    'youtube_channel_id' => $track['youtube_channel_id'],
];

$pageUrl = siteUrl('/' . $slug . '/brani/' . $trackId);
$coverImage = $track['cover_path'] ? siteUrl($track['cover_path']) : ($track['avatar_path'] ? siteUrl($track['avatar_path']) : null);
$ogDescription = $track['display_name'] . ' — ascolta "' . $track['title'] . '" su ' . siteName();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($track['title']) ?> — <?= e($track['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">

<!-- Open Graph / condivisione social -->
<meta property="og:type" content="music.song">
<meta property="og:title" content="<?= e($track['title']) ?> — <?= e($track['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($coverImage): ?>
<meta property="og:image" content="<?= e($coverImage) ?>">
<meta property="og:image:width" content="500">
<meta property="og:image:height" content="500">
<?php endif; ?>
<meta property="og:audio" content="<?= e(siteUrl($track['file_path'])) ?>">

<meta name="twitter:card" content="<?= $coverImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($track['title']) ?> — <?= e($track['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($coverImage): ?><meta name="twitter:image" content="<?= e($coverImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($track['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($track['theme_color'])) ?>; }</style>
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body class="<?= e(getPageThemeClass($artist['page_theme'] ?? 'colorful')) ?>">
<?php if (str_starts_with($artist['page_theme'] ?? 'colorful', 'wave')): ?><?= renderWaveBackground($artist['theme_color'] ?? '#6C5CE7', $artist['page_theme']) ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'circuit'): ?><?= renderCircuitBackground($artist['theme_color'] ?? '#6C5CE7') ?><?php endif; ?>
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'brani') ?>

  <div class="card" style="text-align:center;">
    <?php if ($track['cover_path']): ?>
      <img src="/<?= e($track['cover_path']) ?>" alt="<?= e($track['title']) ?>"
           style="width:220px;height:220px;border-radius:16px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
    <?php endif; ?>
    <h1 style="font-size:22px;margin:0 0 4px;"><?= e($track['title']) ?></h1>
    <p style="color:rgba(var(--text-rgb),0.7);margin-top:0;">di <?= e($track['display_name']) ?></p>
    <audio controls src="/<?= e($track['file_path']) ?>" style="width:100%;margin-top:10px;"></audio>
  </div>

  <p><a href="/<?= e($slug) ?>/brani">← Tutti i brani di <?= e($track['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($slug) ?>
</body>
</html>
