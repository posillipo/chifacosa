<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

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

$services = getDB()->prepare("SELECT sv.*, (SELECT COUNT(*) FROM service_photos WHERE service_id = sv.id) AS extra_photos
    FROM services sv WHERE sv.user_id=? AND sv.show_in_feed = 1
    AND (sv.publish_at IS NULL OR sv.publish_at <= NOW()) ORDER BY sv.sort_order DESC");
$services->execute([$artist['id']]);
$services = $services->fetchAll();

$pageUrl = siteUrl('/' . $slug . '/servizi');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Servizi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Servizi di <?= e($artist['display_name']) ?>">
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
  <?= publicProfileHeader($artist, 'servizi') ?>

  <?php if (!$services): ?>
    <div class="card">Nessun servizio pubblicato al momento.</div>
  <?php endif; ?>

  <?php foreach ($services as $sv): ?>
    <a href="/<?= e($slug) ?>/servizi/<?= (int) $sv['id'] ?>" class="card" style="display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit;">
      <?php if ($sv['cover_path']): ?>
        <img src="/<?= e($sv['cover_path']) ?>" style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <strong><?= e($sv['title']) ?></strong>
        <?php if ($sv['description']): ?>
          <div style="opacity:.75;margin-top:2px;font-size:13px;"><?= e(textExcerpt($sv['description'], 90)) ?></div>
        <?php endif; ?>
        <?php if ((int) $sv['accepts_inquiries'] === 1): ?>
          <small style="color:var(--accent);font-weight:700;display:block;margin-top:4px;"><i class="fa-solid fa-envelope"></i> Richiedi informazioni</small>
        <?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>

  <p style="margin-top:18px;"><a href="/<?= e($slug) ?>">← Torna alla pagina di <?= e($artist['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
