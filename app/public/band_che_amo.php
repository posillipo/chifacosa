<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.genere
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_bands WHERE user_id=? ORDER BY sort_order ASC');
$stmt->execute([$artist['id']]);
$favorites = $stmt->fetchAll();

$pageUrl = siteUrl('/' . $slug . '/band-che-amo');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Band che ama <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Band che ama <?= e($artist['display_name']) ?>">
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
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'home') ?>

  <div class="section-title" style="text-align:center;color:rgba(var(--text-rgb),0.6);margin:18px 0 10px;">
    Tutte le band che ama (<?= count($favorites) ?>)
  </div>

  <?php if ($favorites): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;">
      <?php foreach ($favorites as $f): ?>
        <a href="https://open.spotify.com/artist/<?= e($f['spotify_artist_id']) ?>" target="_blank" rel="noopener"
           class="card" style="text-align:center;text-decoration:none;color:inherit;padding:14px 8px;">
          <?php if ($f['artist_image']): ?>
            <img src="<?= e($f['artist_image']) ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;margin-bottom:8px;">
          <?php endif; ?>
          <div style="font-weight:700;font-size:13px;"><?= e($f['spotify_artist_name']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card">Nessuna band aggiunta ancora.</div>
  <?php endif; ?>

  <p style="margin-top:18px;"><a href="/<?= e($slug) ?>">← Torna alla pagina di <?= e($artist['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar() ?>
</body>
</html>
