<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$tracks = getDB()->prepare('SELECT * FROM favorite_tracks WHERE user_id=? ORDER BY sort_order ASC, id DESC');
$tracks->execute([$artist['id']]);
$tracks = $tracks->fetchAll();

$pageUrl = siteUrl('/' . $slug . '/brani');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Brani di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Brani di <?= e($artist['display_name']) ?>">
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
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'brani') ?>

  <?php if (!$tracks): ?>
    <div class="card">Nessun brano aggiunto ancora.</div>
  <?php endif; ?>

  <?php foreach ($tracks as $t): ?>
    <div class="card" style="display:flex;gap:14px;align-items:center;">
      <a href="<?= e($t['spotify_url'] ?: '#') ?>" target="_blank" rel="noopener"
         style="display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit;flex:1;min-width:0;">
        <?php if ($t['track_image']): ?>
          <img src="<?= e($t['track_image']) ?>" alt="<?= e($t['track_name']) ?>"
               style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;">
        <?php else: ?>
          <div style="width:72px;height:72px;border-radius:10px;background:rgba(var(--text-rgb),0.15);flex-shrink:0;"></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <strong><?= e($t['track_name']) ?></strong><br>
          <small style="color:rgba(var(--text-rgb),0.7);"><?= e($t['artist_name']) ?></small><br>
          <?php $trackStats = getTrackRatingStats((int) $t['id']); ?>
          <small><?= renderCromeRating($trackStats['avg']) ?><?php if ($trackStats['count'] > 0): ?> <span style="color:rgba(var(--text-rgb),0.55);">(<?= $trackStats['count'] ?>)</span><?php endif; ?></small>
        </div>
        <i class="fa-brands fa-spotify" style="color:#1DB954;font-size:22px;flex-shrink:0;"></i>
      </a>
      <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
        <?php if (!empty($t['lyrics'])): ?>
          <a href="/<?= e($slug) ?>/brani/<?= (int) $t['id'] ?>/testo" title="Testo e ascolto" style="font-size:19px;color:inherit;">📝</a>
        <?php endif; ?>
        <a href="/<?= e($slug) ?>/brani/<?= (int) $t['id'] ?>/votazioni" title="Vota questo brano" style="font-size:24px;color:var(--accent);line-height:1;">★</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar() ?>
</body>
</html>
