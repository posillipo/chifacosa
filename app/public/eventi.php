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

$events = getDB()->prepare('SELECT * FROM events WHERE user_id=? AND event_date >= NOW() ORDER BY event_date ASC');
$events->execute([$artist['id']]);
$events = $events->fetchAll();

$pageUrl = siteUrl('/' . $slug . '/eventi');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eventi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Eventi di <?= e($artist['display_name']) ?>">
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
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'eventi') ?>

  <?php if (!$events): ?>
    <div class="card">Nessun evento in programma al momento.</div>
  <?php endif; ?>

  <?php foreach ($events as $i => $ev): ?>
    <a href="/<?= e($slug) ?>/eventi/<?= (int)$ev['id'] ?>" class="color-link-btn"
       style="background:<?= e(COLORFUL_PALETTE[$i % count(COLORFUL_PALETTE)]) ?>;">
      <?php if ($ev['cover_path']): ?>
        <img src="/<?= e($ev['cover_path']) ?>" class="btn-cover-icon">
      <?php endif; ?>
      <small style="display:block;opacity:.75;"><?= date('d/m/Y H:i', strtotime($ev['event_date'])) ?></small>
      <strong style="display:block;"><?= e($ev['title']) ?></strong>
      <?php if ($ev['venue'] || $ev['city']): ?>
        <small style="opacity:.75;"><?= e($ev['venue']) ?><?= $ev['venue'] && $ev['city'] ? ', ' : '' ?><?= e($ev['city']) ?></small>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($slug) ?>
</body>
</html>
