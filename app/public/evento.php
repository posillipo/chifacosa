<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$eventId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, ev.*
                          FROM events ev
                          JOIN users u ON u.id = ev.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND ev.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $eventId]);
$event = $stmt->fetch();

if (!$event) {
    http_response_code(404);
    exit('Evento non trovato.');
}

$artist = [
    'id' => $event['user_id'],
    'slug' => $slug,
    'display_name' => $event['display_name'],
    'avatar_path' => $event['avatar_path'],
    'spotify_artist_id' => $event['spotify_artist_id'],
    'spotify_show_id' => $event['spotify_show_id'],
    'account_type' => $event['account_type'] ?? 'band',
    'page_theme' => $event['page_theme'] ?? 'colorful',
    'genere' => $event['genere'],
    'youtube_channel_id' => $event['youtube_channel_id'],
];

$resMsg = $_GET['res_msg'] ?? '';
$resErr = ($_GET['res_err'] ?? '0') === '1';

$pageUrl = siteUrl('/' . $slug . '/eventi/' . $eventId);
$ogImage = $event['cover_path'] ? siteUrl($event['cover_path']) : ($event['avatar_path'] ? siteUrl($event['avatar_path']) : null);
$locationLine = trim(($event['venue'] ?: '') . ($event['venue'] && $event['city'] ? ', ' : '') . ($event['city'] ?: ''));
$ogDescription = trim($event['display_name'] . ' — ' . date('d/m/Y H:i', strtotime($event['event_date'])) . ($locationLine ? ' · ' . $locationLine : ''));
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($event['title']) ?> — <?= e($event['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($event['title']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="500">
<meta property="og:image:height" content="500">
<?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($event['title']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($event['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($event['theme_color'])) ?>; }</style>
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

  <div class="card" style="text-align:center;">
    <?php if ($event['cover_path']): ?>
      <img src="/<?= e($event['cover_path']) ?>" alt="<?= e($event['title']) ?>"
           style="width:220px;height:220px;border-radius:16px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
    <?php endif; ?>
    <h1 style="font-size:22px;margin:0 0 6px;"><?= e($event['title']) ?></h1>
    <p style="color:rgba(var(--text-rgb),0.7);margin:0 0 4px;"><?= date('d/m/Y H:i', strtotime($event['event_date'])) ?></p>
    <?php if ($locationLine): ?>
      <p style="color:rgba(var(--text-rgb),0.7);margin:0 0 12px;"><?= e($locationLine) ?></p>
    <?php endif; ?>
    <?php if ($event['ticket_url']): ?>
      <a class="btn" href="<?= e($event['ticket_url']) ?>" target="_blank" rel="noopener">Biglietti →</a>
    <?php endif; ?>
  </div>

  <?php if ($resMsg): ?>
    <div class="alert <?= $resErr ? 'error' : 'success' ?>"><?= e($resMsg) ?></div>
  <?php endif; ?>

  <?php if ((int) $event['accepts_reservations'] === 1): ?>
    <div class="card">
      <div class="section-title" style="margin-top:0;">Prenota</div>
      <form method="post" action="/reserve_table.php">
        <?= csrfField() ?>
        <input type="hidden" name="slug" value="<?= e($slug) ?>">
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
        <label>Nome e cognome</label>
        <input type="text" name="guest_name" required>
        <label>Email</label>
        <input type="email" name="guest_email" required>
        <label>Telefono (facoltativo)</label>
        <input type="tel" name="guest_phone">
        <label>Numero di persone</label>
        <input type="number" name="party_size" min="1" max="50" value="2" required>
        <label>Note (facoltative)</label>
        <input type="text" name="notes" placeholder="es. seggiolone, allergie, ...">
        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:4px 0 16px;">
          <input type="checkbox" name="marketing_opt_in" value="1" style="width:auto;margin-bottom:0;">
          Voglio ricevere aggiornamenti su nuovi eventi e offerte da <?= e($event['display_name']) ?>
        </label>
        <button type="submit" class="btn">Prenota</button>
      </form>
    </div>
  <?php endif; ?>

  <p><a href="/<?= e($slug) ?>/eventi">← Tutti gli eventi di <?= e($event['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($slug) ?>
</body>
</html>
