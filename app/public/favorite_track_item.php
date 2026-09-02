<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Pagina di dettaglio pubblica per un singolo "Brano che amo" (favorite_tracks) — stesso pattern
// di fan_favorite_item.php (Band/Attori/Film che amo), ma su un file a parte perché l'URL non può
// stare su /slug/brani/ID "nudo" (già occupato da track.php, tabella audio_tracks diversa): vive
// quindi su /slug/brani/ID/scheda.

$slug = $_GET['slug'] ?? '';
$trackId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.privacy_tracking_settings, p.genere, p.custom_feed_guid, p.custom_feed_guid_since
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$stmt = getDB()->prepare('SELECT * FROM favorite_tracks WHERE id = ? AND user_id = ?');
$stmt->execute([$trackId, $artist['id']]);
$track = $stmt->fetch();

if (!$track) {
    http_response_code(404);
    exit('Brano non trovato.');
}

$note = trim($track['note'] ?? '');
$personalImage = $track['image_path'] ?: null;
$image = $personalImage ?: $track['track_image'];
$imageUrl = $image ? (str_starts_with($image, 'http') ? $image : siteUrl($image)) : null;

$pageUrl = siteUrl('/' . $slug . '/brani/' . $trackId . '/scheda');
$ogDescription = $note !== '' ? $note : ($artist['display_name'] . ' ama "' . $track['track_name'] . '" — scoprilo su ' . siteName());
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'], $artist['custom_feed_guid_since'], $track['created_at']); ?>
<title><?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">

<!-- Open Graph / condivisione social -->
<meta property="og:type" content="music.song">
<meta property="og:title" content="<?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?>
<meta property="og:image" content="<?= e($imageUrl) ?>">
<?php endif; ?>

<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<?php if ($imageUrl): ?><meta name="twitter:image" content="<?= e($imageUrl) ?>"><?php endif; ?>

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
  <?= publicProfileHeader($artist, 'brani') ?>

  <div class="card" style="text-align:center;">
    <?php if ($imageUrl): ?>
      <img src="<?= e($imageUrl) ?>" alt="<?= e($track['track_name']) ?>"
           style="width:220px;height:220px;border-radius:16px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
    <?php endif; ?>
    <h1 style="font-size:22px;margin:0 0 4px;"><?= e($track['track_name']) ?></h1>
    <p style="opacity:0.75;margin-top:0;">
      Brano che amo di <?= e($artist['display_name']) ?><?php if ($track['artist_name']): ?> · <?= e($track['artist_name']) ?><?php endif; ?>
    </p>

    <?php if ($note !== ''): ?>
      <div class="card" style="text-align:left;margin-top:14px;">
        <strong>Perché <?= e($artist['display_name']) ?> lo ama</strong>
        <p style="margin:6px 0 0;"><?= nl2br(e($note)) ?></p>
      </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:16px;">
      <?php if ($track['spotify_url']): ?>
        <a href="<?= e($track['spotify_url']) ?>" target="_blank" rel="noopener" class="btn small">Ascolta su Spotify</a>
      <?php endif; ?>
      <?php if (!empty($track['lyrics'])): ?>
        <a href="/<?= e($slug) ?>/brani/<?= (int)$track['id'] ?>/testo" class="btn small secondary">📝 Testo</a>
      <?php endif; ?>
      <a href="/<?= e($slug) ?>/brani/<?= (int)$track['id'] ?>/votazioni" class="btn small secondary">★ Vota</a>
    </div>
  </div>

  <p><a href="/<?= e($slug) ?>/brani">← Tutti i brani che ama <?= e($artist['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
