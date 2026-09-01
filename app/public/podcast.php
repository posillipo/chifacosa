<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.spotify_show_name, p.youtube_channel_id, p.privacy_tracking_settings
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist || empty($artist['spotify_show_id'])) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$episodes = spotifyGetShowEpisodes($artist['spotify_show_id'], 10);
$showDetails = spotifyGetShow($artist['spotify_show_id']);

$pageUrl = siteUrl('/' . $slug . '/podcast');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['spotify_show_name'] ?: 'Podcast') ?> — <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['spotify_show_name'] ?: 'Podcast') ?> — <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if (!empty($showDetails['image'])): ?><meta property="og:image" content="<?= e($showDetails['image']) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($artist['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($artist['theme_color'])) ?>; }</style>
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
  <?= publicProfileHeader($artist, 'podcast') ?>

  <?php if ($artist['spotify_show_name']): ?>
    <div class="section-title">Podcast: <?= e($artist['spotify_show_name']) ?></div>
  <?php endif; ?>

  <?php if ($episodes): ?>
    <?php foreach ($episodes as $ep): ?>
      <a class="card" style="display:flex;align-items:center;gap:14px;text-decoration:none;color:inherit;"
         href="<?= e($ep['spotify_url']) ?>" target="_blank" rel="noopener">
        <?php if ($ep['image']): ?>
          <img src="<?= e($ep['image']) ?>" alt="" style="width:64px;height:64px;border-radius:8px;flex-shrink:0;">
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <strong><?= e($ep['name']) ?></strong><br>
          <small style="color:rgba(var(--text-rgb),0.7);">
            <?= $ep['release_date'] ? date('d/m/Y', strtotime($ep['release_date'])) : '' ?>
            <?= $ep['duration_ms'] ? ' · ' . gmdate('i:s', (int)($ep['duration_ms']/1000)) . ' min' : '' ?>
          </small>
          <?php if ($ep['description']): ?>
            <p style="color:rgba(var(--text-rgb),0.7);margin:6px 0 0;font-size:13px;"><?= e($ep['description']) ?></p>
          <?php endif; ?>
        </div>
        <i class="fa-brands fa-spotify" style="color:#1DB954;font-size:22px;flex-shrink:0;"></i>
      </a>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="card">Nessun episodio trovato al momento.</div>
  <?php endif; ?>

  <div class="card" style="margin-top:24px;text-align:center;">
    <a class="btn" style="background:#1DB954;" href="https://open.spotify.com/show/<?= e($artist['spotify_show_id']) ?>" target="_blank" rel="noopener">
      <i class="fa-brands fa-spotify"></i> Ascolta tutti gli episodi su Spotify
    </a>
  </div>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
