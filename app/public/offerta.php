<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$offerId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, p.privacy_tracking_settings, p.custom_feed_guid, p.custom_feed_guid_since, so.*
                          FROM special_offers so
                          JOIN users u ON u.id = so.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND so.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $offerId]);
$offer = $stmt->fetch();

if (!$offer) {
    http_response_code(404);
    exit('Offerta non trovata.');
}

$isOwner = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $offer['user_id'];
$now = date('Y-m-d H:i:s');
$isCurrentlyValid = (!$offer['valid_from'] || $offer['valid_from'] <= $now) && (!$offer['valid_until'] || $offer['valid_until'] >= $now);
if (!$isOwner && (!(int) $offer['is_active'] || !$isCurrentlyValid)) {
    http_response_code(404);
    exit('Offerta non trovata.');
}

$artist = [
    'id' => $offer['user_id'],
    'slug' => $slug,
    'display_name' => $offer['display_name'],
    'avatar_path' => $offer['avatar_path'],
    'spotify_artist_id' => $offer['spotify_artist_id'],
    'spotify_show_id' => $offer['spotify_show_id'],
    'youtube_channel_id' => $offer['youtube_channel_id'],
    'privacy_tracking_settings' => $offer['privacy_tracking_settings'] ?? null,
    'genere' => $offer['genere'],
    'account_type' => $offer['account_type'],
    'page_theme' => $offer['page_theme'] ?? 'colorful',
    'dashboard_theme' => $offer['dashboard_theme'] ?? null,
];

$pageUrl = siteUrl('/' . $slug . '/offerte/' . $offerId);
$ogImage = $offer['cover_path'] ? siteUrl($offer['cover_path']) : ($offer['avatar_path'] ? siteUrl($offer['avatar_path']) : null);
$ogDescriptionParts = array_filter([$offer['price_label'], $offer['description'] ? textExcerpt($offer['description'], 160) : null]);
$ogDescription = $ogDescriptionParts ? implode(' — ', $ogDescriptionParts) : ($offer['display_name'] . ' — scopri l\'offerta su ' . siteName());
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($offer['custom_feed_guid'], $offer['custom_feed_guid_since'], $offer['created_at']); ?>
<title><?= e($offer['title']) ?> — <?= e($offer['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($offer['title']) ?> — <?= e($offer['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($offer['title']) ?> — <?= e($offer['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($offer['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($offer['theme_color'])) ?>; }</style>
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
  <?= publicProfileHeader($artist, 'offerte') ?>

  <?php if ($isOwner && (!(int) $offer['is_active'] || !$isCurrentlyValid)): ?>
    <div class="alert error">Questa offerta non è visibile al pubblico al momento (disattivata o fuori dal periodo di validità) — la vedi solo tu, come proprietario del profilo.</div>
  <?php endif; ?>

  <div class="card" style="text-align:center;">
    <?php if ($offer['cover_path']): ?>
      <img src="/<?= e($offer['cover_path']) ?>" alt="<?= e($offer['title']) ?>"
           style="max-width:100%;max-height:480px;display:block;margin:0 auto 16px;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,0.18);">
    <?php endif; ?>
    <h1 style="font-size:22px;margin:0 0 4px;"><?= e($offer['title']) ?></h1>
    <?php if ($offer['price_label']): ?>
      <p style="color:var(--accent);font-weight:800;font-size:18px;margin:4px 0;"><?= e($offer['price_label']) ?></p>
    <?php endif; ?>
    <?php if ($offer['valid_from'] || $offer['valid_until']): ?>
      <p style="color:rgba(var(--text-rgb),0.7);margin:4px 0;">
        <?= $offer['valid_from'] ? 'Dal ' . e(formatLocalDateTime($offer['valid_from'], $artist)) : '' ?>
        <?= $offer['valid_from'] && $offer['valid_until'] ? ' — ' : '' ?>
        <?= $offer['valid_until'] ? 'Fino al ' . e(formatLocalDateTime($offer['valid_until'], $artist)) : '' ?>
      </p>
    <?php endif; ?>
    <?php if (!empty($offer['description'])): ?>
      <p style="text-align:left;color:rgba(var(--text-rgb),0.9);margin:16px 0 0;"><?= nl2br(e($offer['description'])) ?></p>
    <?php endif; ?>
  </div>

  <p><a href="/<?= e($slug) ?>/offerte">← Tutte le offerte di <?= e($offer['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
