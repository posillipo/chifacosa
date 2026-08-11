<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/youtube.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, p.youtube_channel_name
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist || empty($artist['youtube_channel_id'])) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$uploadsPlaylistId = 'UU' . substr($artist['youtube_channel_id'], 2);
$videos = youtubeGetChannelVideos($uploadsPlaylistId, 12);

$pageUrl = siteUrl('/' . $slug . '/video');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> su YouTube — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['display_name']) ?> su YouTube">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($artist['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($artist['theme_color'])) ?>; }</style>
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
  <?= publicProfileHeader($artist, 'video') ?>

  <?php if ($videos): ?>
    <div style="display:grid;grid-template-columns:1fr;gap:20px;">
      <?php foreach ($videos as $v): ?>
        <div class="card" style="padding:0;overflow:hidden;">
          <div style="position:relative;padding-bottom:56.25%;height:0;">
            <iframe src="https://www.youtube.com/embed/<?= e($v['video_id']) ?>"
                    style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"
                    title="<?= e($v['title']) ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen></iframe>
          </div>
          <div style="padding:12px 16px;">
            <strong><?= e($v['title']) ?></strong>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card">Nessun video trovato su YouTube per questo canale al momento.</div>
  <?php endif; ?>

  <div class="card" style="margin-top:24px;text-align:center;">
    <a class="btn" style="background:#FF0000;" href="https://www.youtube.com/channel/<?= e($artist['youtube_channel_id']) ?>" target="_blank" rel="noopener">
      <i class="fa-brands fa-youtube"></i> Vai al canale completo su YouTube
    </a>
  </div>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($slug) ?>
</body>
</html>
