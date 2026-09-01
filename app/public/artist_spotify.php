<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, p.privacy_tracking_settings, p.spotify_artist_name
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist || empty($artist['spotify_artist_id'])) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$albums = spotifyGetArtistAlbums($artist['spotify_artist_id']);
$topTracks = spotifyGetArtistTopTracks($artist['spotify_artist_id']);
$artistDetails = spotifyGetArtist($artist['spotify_artist_id']);

$pageUrl = siteUrl('/' . $slug . '/spotify');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> su Spotify — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['display_name']) ?> su Spotify">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if (!empty($artistDetails['image'])): ?><meta property="og:image" content="<?= e($artistDetails['image']) ?>"><?php endif; ?>
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
  <?= publicProfileHeader($artist, 'spotify') ?>

  <?php if ($topTracks): ?>
    <div class="section-title">Brani più ascoltati</div>
    <?php foreach ($topTracks as $t): ?>
      <a class="card" style="display:flex;align-items:center;gap:14px;text-decoration:none;color:inherit;"
         href="<?= e($t['spotify_url']) ?>" target="_blank" rel="noopener">
        <?php if ($t['image']): ?>
          <img src="<?= e($t['image']) ?>" alt="" style="width:56px;height:56px;border-radius:8px;flex-shrink:0;">
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <strong><?= e($t['name']) ?></strong><br>
          <small style="color:rgba(var(--text-rgb),0.7);"><?= e($t['album_name']) ?></small>
        </div>
        <i class="fa-brands fa-spotify" style="color:#1DB954;font-size:22px;flex-shrink:0;"></i>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($albums): ?>
    <div class="section-title">Album e singoli</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;">
      <?php foreach ($albums as $a): ?>
        <a href="<?= e($a['spotify_url']) ?>" target="_blank" rel="noopener"
           style="text-decoration:none;color:inherit;">
          <?php if ($a['image']): ?>
            <img src="<?= e($a['image']) ?>" alt="" style="width:100%;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,0.12);">
          <?php endif; ?>
          <div style="margin-top:6px;font-size:13px;font-weight:700;"><?= e($a['name']) ?></div>
          <div style="font-size:12px;color:rgba(var(--text-rgb),0.7);">
            <?= e($a['release_date'] ? substr($a['release_date'], 0, 4) : '') ?> ·
            <?= $a['type'] === 'single' ? 'Singolo' : 'Album' ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$topTracks && !$albums): ?>
    <div class="card">Nessun contenuto trovato su Spotify per questo artista al momento.</div>
  <?php endif; ?>

  <div class="card" style="margin-top:24px;text-align:center;">
    <a class="btn" style="background:#1DB954;" href="https://open.spotify.com/artist/<?= e($artist['spotify_artist_id']) ?>" target="_blank" rel="noopener">
      <i class="fa-brands fa-spotify"></i> Apri il profilo completo su Spotify
    </a>
  </div>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
