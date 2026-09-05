<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/geocoding.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Pagina di dettaglio pubblica per un singolo "Viaggio" (fan_favorite_trips) — stesso pattern di
// fan_favorite_item.php (Band/Attori/Film/Libri che amo), ma su un file a parte perché qui non
// c'è un'API esterna con un ID/dettagli canonici da recuperare: al suo posto c'è una mappa
// interattiva sulla posizione salvata (stesso embed OpenStreetMap già usato nel modulo Link).

$slug = $_GET['slug'] ?? '';
$tripId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.privacy_tracking_settings, p.genere, p.custom_feed_guid, p.custom_feed_guid_since
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_trips WHERE id = ? AND user_id = ?');
$stmt->execute([$tripId, $artist['id']]);
$trip = $stmt->fetch();

if (!$trip) {
    http_response_code(404);
    exit('Viaggio non trovato.');
}

$note = trim($trip['note'] ?? '');
// La foto caricata dal proprietario ha sempre la precedenza; senza di essa si usa la miniatura
// mappa generata automaticamente (Geoapify) — così ogni viaggio ha sempre un'immagine da
// mostrare nell'anteprima social, anche senza una foto propria.
$image = $trip['image_path'] ?: $trip['map_image_path'];
$imageUrl = $image ? siteUrl($image) : null;

// Altri viaggi pubblicati lo stesso giorno di questo — così chi arriva da un link personale a
// un solo viaggio (es. una foto condivisa) vede subito anche gli altri della stessa giornata,
// senza dover andare a sfogliare la Timeline.
$sameDayItems = getSameDayFavorites('fan_favorite_trips', $artist['id'], $trip['publish_at'], $trip['created_at'], $tripId);

$pageUrl = siteUrl('/' . $slug . '/viaggi/' . $tripId);
$ogDescription = $note !== '' ? $note : ($artist['display_name'] . ' è stato a ' . $trip['place_name'] . ' — scoprilo su ' . siteName());
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'], $artist['custom_feed_guid_since'], $trip['created_at']); ?>
<title><?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">

<!-- Open Graph / condivisione social -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?>
<meta property="og:image" content="<?= e($imageUrl) ?>">
<?php endif; ?>

<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?>">
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
  <?= publicProfileHeader($artist, 'viaggi') ?>

  <div class="card" style="text-align:center;">
    <?php if ($trip['image_path']): ?>
      <img src="/<?= e($trip['image_path']) ?>" alt="<?= e($trip['place_name']) ?>"
           style="width:100%;max-width:420px;max-height:280px;border-radius:14px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
    <?php endif; ?>
    <h1 style="font-size:22px;margin:0 0 4px;"><?= e($trip['place_name']) ?></h1>
    <p style="opacity:0.75;margin-top:0;">
      Viaggio di <?= e($artist['display_name']) ?>
      <?php if (!empty($trip['address']) && $trip['address'] !== $trip['place_name']): ?> · <?= e($trip['address']) ?><?php endif; ?>
    </p>

    <?php if ($note !== ''): ?>
      <div class="card" style="text-align:left;margin-top:14px;">
        <strong>Il racconto di <?= e($artist['display_name']) ?></strong>
        <p style="margin:6px 0 0;"><?= nl2br(e($note)) ?></p>
      </div>
    <?php endif; ?>

    <div style="margin-top:16px;"><?= renderOsmEmbed((float) $trip['lat'], (float) $trip['lng']) ?></div>
  </div>

  <?php if ($sameDayItems): ?>
    <div class="section-title" style="text-align:center;color:rgba(var(--text-rgb),0.6);margin:22px 0 10px;">
      Altri di questa giornata (<?= count($sameDayItems) ?>)
    </div>
    <?php foreach ($sameDayItems as $s): ?>
      <?php
        $sImage = $s['image_path'] ?: $s['map_image_path'];
        $sImageUrl = $sImage ? siteUrl($sImage) : null;
        $sWhen = $s['publish_at'] ?: $s['created_at'];
      ?>
      <a href="/<?= e($slug) ?>/viaggi/<?= (int) $s['id'] ?>"
         class="card" style="display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit;">
        <?php if ($sImageUrl): ?>
          <img src="<?= e($sImageUrl) ?>" alt="<?= e($s['place_name']) ?>" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex-shrink:0;">
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <small style="color:rgba(var(--text-rgb),0.6);text-transform:uppercase;">✈️ Viaggio</small><br>
          <strong><?= e($s['place_name']) ?></strong><br>
          <small style="color:rgba(var(--text-rgb),0.6);"><?= e(date('d/m/Y H:i', strtotime($sWhen))) ?></small>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <p><a href="/<?= e($slug) ?>/viaggi">← Tutti i viaggi di <?= e($artist['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
