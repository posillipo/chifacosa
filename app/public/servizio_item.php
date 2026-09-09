<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$serviceId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.id AS owner_id, u.slug, u.account_type, u.email AS owner_email, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, p.privacy_tracking_settings, p.custom_feed_guid, p.custom_feed_guid_since, sv.*
                          FROM services sv
                          JOIN users u ON u.id = sv.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND sv.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $serviceId]);
$service = $stmt->fetch();

if (!$service) {
    http_response_code(404);
    exit('Servizio non trovato.');
}

$isOwner = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $service['user_id'];
$isScheduledFuture = $service['publish_at'] && strtotime($service['publish_at']) > time();
if (!$isOwner && (!(int) $service['show_in_feed'] || $isScheduledFuture)) {
    http_response_code(404);
    exit('Servizio non trovato.');
}

$artist = [
    'id' => $service['user_id'],
    'slug' => $slug,
    'display_name' => $service['display_name'],
    'avatar_path' => $service['avatar_path'],
    'spotify_artist_id' => $service['spotify_artist_id'],
    'spotify_show_id' => $service['spotify_show_id'],
    'youtube_channel_id' => $service['youtube_channel_id'],
    'privacy_tracking_settings' => $service['privacy_tracking_settings'] ?? null,
    'genere' => $service['genere'],
    'account_type' => $service['account_type'],
    'page_theme' => $service['page_theme'] ?? 'colorful',
    'dashboard_theme' => $service['dashboard_theme'] ?? null,
];

// Copertina + eventuali foto extra della galleria, stesso carosello già usato per gli album fotografici.
$photos = array_values(array_filter(array_merge([$service['cover_path']], getServicePhotos($serviceId))));

$formSent = false;
$formError = null;
$conversionEventId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) $service['accepts_inquiries'] === 1) {
    checkCsrf();
    $guestName = trim($_POST['guest_name'] ?? '');
    $guestEmail = trim($_POST['guest_email'] ?? '');
    $guestPhone = trim($_POST['guest_phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($guestName === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $formError = 'Compila nome ed email con un\'email valida.';
    } else {
        $stmt = getDB()->prepare('INSERT INTO service_inquiries (user_id, service_id, guest_name, guest_email, guest_phone, message) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$service['user_id'], $serviceId, $guestName, $guestEmail, $guestPhone !== '' ? $guestPhone : null, $message !== '' ? $message : null]);
        $formSent = true;

        notifyServiceInquiry(
            $service['owner_email'],
            $service['display_name'],
            $service['title'],
            $guestName,
            $guestEmail,
            $guestPhone !== '' ? $guestPhone : null,
            $message !== '' ? $message : null,
            siteUrl('/' . $slug . '/servizi/' . $serviceId)
        );

        $conversionEventId = generateEventId();
        sendMetaConversionEvent('Lead', $conversionEventId, $guestEmail, $artist);
    }
}

$pageUrl = siteUrl('/' . $slug . '/servizi/' . $serviceId);
$ogImage = $service['cover_path'] ? siteUrl($service['cover_path']) : ($service['avatar_path'] ? siteUrl($service['avatar_path']) : null);
$ogDescription = $service['description'] ? textExcerpt($service['description'], 160) : ($service['display_name'] . ' — scopri il servizio su ' . siteName());
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($service['custom_feed_guid'] ?? null, $service['custom_feed_guid_since'] ?? null, $service['created_at']); ?>
<title><?= e($service['title']) ?> — <?= e($service['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($service['title']) ?> — <?= e($service['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($service['title']) ?> — <?= e($service['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($service['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($service['theme_color'])) ?>; }</style>
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

  <?php if ($isOwner && (!(int) $service['show_in_feed'] || $isScheduledFuture)): ?>
    <div class="alert error">Questo servizio non è visibile al pubblico al momento (privato o programmato per il futuro) — lo vedi solo tu, come proprietario del profilo.</div>
  <?php endif; ?>

  <div class="card" style="text-align:center;">
    <?= renderPhotoCarousel($photos, $serviceId) ?>
    <h1 style="font-size:22px;margin:0 0 4px;"><?= e($service['title']) ?></h1>
    <?php if (!empty($service['description'])): ?>
      <p style="text-align:left;color:rgba(var(--text-rgb),0.9);margin:16px 0 0;"><?= nl2br(e($service['description'])) ?></p>
    <?php endif; ?>
  </div>

  <?php if ((int) $service['accepts_inquiries'] === 1): ?>
    <div class="section-title">Richiedi informazioni</div>
    <?php if ($formSent): ?>
      <div class="alert success">Richiesta inviata! Verrai ricontattato al più presto.</div>
      <?= embedClientSideConversionEvent('Lead', $conversionEventId, $artist) ?>
    <?php else: ?>
      <?php if ($formError): ?><div class="alert error"><?= e($formError) ?></div><?php endif; ?>
      <form method="post" class="card">
        <?= csrfField() ?>
        <label>Nome</label>
        <input type="text" name="guest_name" required>
        <label>Email</label>
        <input type="email" name="guest_email" required>
        <label>Telefono (opzionale)</label>
        <input type="tel" name="guest_phone">
        <label>Messaggio (opzionale)</label>
        <textarea name="message" rows="4"></textarea>
        <button type="submit" class="btn">Invia richiesta</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <p><a href="/<?= e($slug) ?>/servizi">← Tutti i servizi di <?= e($service['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>

<?php if (count($photos) > 1): ?>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>">
<script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script>
<?php endif; ?>
</body>
</html>
