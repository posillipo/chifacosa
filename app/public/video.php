<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/videos.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, p.privacy_tracking_settings
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$stmt = getDB()->prepare("SELECT * FROM videos WHERE user_id=? AND is_public = 1
    AND (publish_at IS NULL OR publish_at <= NOW()) ORDER BY sort_order DESC, id DESC");
$stmt->execute([$artist['id']]);
$videos = $stmt->fetchAll();

if (($artist['page_theme'] ?? 'colorful') === 'adminlte-profile') {
    echo renderAdminLteVideosPage($artist, $slug, $videos);
    exit;
}

$pageUrl = siteUrl('/' . $slug . '/video');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Video di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Video di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
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
  <?= publicProfileHeader($artist, 'video') ?>

  <?php if ($videos): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
      <?php foreach ($videos as $v): ?>
        <a href="/<?= e($slug) ?>/video/<?= (int) $v['id'] ?>" class="card" style="padding:0;overflow:hidden;text-decoration:none;color:inherit;">
          <div style="position:relative;aspect-ratio:16/9;background:#000;">
            <?php if (!empty($v['thumb_path'])): ?>
              <img src="/<?= e($v['thumb_path']) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
            <?php endif; ?>
            <span style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#fff;font-size:44px;text-shadow:0 2px 8px rgba(0,0,0,.6);"><i class="fa-solid fa-circle-play"></i></span>
            <?php if ($dl = videoDurationLabel($v['duration_seconds'] !== null ? (int) $v['duration_seconds'] : null)): ?>
              <span style="position:absolute;right:8px;bottom:8px;background:rgba(0,0,0,.7);color:#fff;font-size:12px;padding:2px 6px;border-radius:4px;"><?= e($dl) ?></span>
            <?php endif; ?>
          </div>
          <div style="padding:12px 16px;">
            <strong><?= e($v['title']) ?></strong>
            <?php if (!empty($v['description'])): ?>
              <div style="opacity:0.75;font-size:13px;margin-top:4px;"><?= e(textExcerpt($v['description'], 140)) ?></div>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card">Nessun video ancora.</div>
  <?php endif; ?>

  <p style="margin-top:18px;"><a href="/<?= e($slug) ?>">← Torna alla pagina di <?= e($artist['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>

</body>
</html>
