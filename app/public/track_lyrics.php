<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$trackId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.id AS user_id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.genere, ft.*
                          FROM favorite_tracks ft
                          JOIN users u ON u.id = ft.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND ft.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $trackId]);
$track = $stmt->fetch();

if (!$track) {
    http_response_code(404);
    exit('Brano non trovato.');
}

// Se non è mai stato aggiunto un testo, non ha senso avere questa pagina indicizzabile a sé:
// rimandiamo alla pagina di voto, che resta comunque il punto di riferimento del brano.
if (empty($track['lyrics'])) {
    header('Location: /' . $slug . '/brani/' . $trackId . '/votazioni');
    exit;
}

$artist = [
    'slug' => $track['slug'], 'display_name' => $track['display_name'], 'avatar_path' => $track['avatar_path'],
    'spotify_artist_id' => $track['spotify_artist_id'], 'spotify_show_id' => $track['spotify_show_id'],
    'youtube_channel_id' => $track['youtube_channel_id'], 'genere' => $track['genere'], 'account_type' => $track['account_type'], 'page_theme' => $track['page_theme'] ?? 'colorful',
];

$pageUrl = siteUrl('/' . $slug . '/brani/' . $trackId . '/testo');
$stats = getTrackRatingStats($trackId);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($track['track_name']) ?> — Testo e ascolto — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($track['track_name']) ?> — Testo e ascolto">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if ($track['track_image']): ?><meta property="og:image" content="<?= e($track['track_image']) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($track['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($track['theme_color'])) ?>; }</style>
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
  <?= publicProfileHeader($artist, 'brani') ?>

  <div class="card" style="text-align:center;">
    <?php if ($track['track_image']): ?>
      <img src="<?= e($track['track_image']) ?>" style="width:96px;height:96px;border-radius:12px;object-fit:cover;margin-bottom:10px;">
    <?php endif; ?>
    <div style="font-weight:800;font-size:18px;"><?= e($track['track_name']) ?></div>
    <div style="color:rgba(var(--text-rgb),0.6);margin-bottom:6px;"><?= e($track['artist_name']) ?></div>
    <?php if ($stats['count'] > 0): ?>
      <div style="margin-bottom:6px;"><?= renderCromeRating($stats['avg']) ?> <span style="font-size:12.5px;color:rgba(var(--text-rgb),0.55);">(<?= $stats['count'] ?>)</span></div>
    <?php endif; ?>
  </div>

  <?php if ($track['spotify_track_id']): ?>
    <div class="card" style="padding:0;overflow:hidden;">
      <iframe style="border-radius:12px;" src="https://open.spotify.com/embed/track/<?= e($track['spotify_track_id']) ?>?utm_source=generator"
        width="100%" height="152" frameborder="0" allowfullscreen loading="lazy"
        allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"></iframe>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="section-title" style="margin-bottom:10px;font-size:16px;letter-spacing:0.5px;">📝 Testo</div>
    <div style="white-space:pre-line;line-height:1.7;"><?= e($track['lyrics']) ?></div>
  </div>

  <p style="text-align:center;">
    <a href="/<?= e($slug) ?>/brani/<?= (int) $trackId ?>/votazioni">⭐ Vota questo brano →</a>
  </p>
  <p><a href="/<?= e($slug) ?>/brani">← Tutti i brani di <?= e($track['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($slug) ?>
</body>
</html>
