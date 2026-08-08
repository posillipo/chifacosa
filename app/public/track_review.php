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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate_track') {
    checkCsrf();
    $viewerId = $_SESSION['user_id'] ?? null;
    $targetId = (int) ($_POST['target_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    if ($viewerId && $viewerId !== (int) $track['user_id'] && $rating >= 1 && $rating <= 5 && $targetId === (int) $track['id']) {
        $stmt = getDB()->prepare('INSERT INTO track_reviews (track_id, reviewer_user_id, rating) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating)');
        $stmt->execute([$targetId, $viewerId, $rating]);

        $stmt = getDB()->prepare('SELECT slug FROM users WHERE id = ?');
        $stmt->execute([$viewerId]);
        $voter = $stmt->fetch();
        $stmt = getDB()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$track['user_id']]);
        $owner = $stmt->fetch();
        if ($voter && $owner) {
            notifyNewVote($owner['email'], $track['display_name'], $voter['slug'], $rating, '"' . $track['track_name'] . '"', '/' . $slug . '/brani/' . $trackId . '/votazioni');
        }
    }
    header('Location: /' . $slug . '/brani/' . $trackId . '/votazioni');
    exit;
}

$artist = [
    'slug' => $track['slug'], 'display_name' => $track['display_name'], 'avatar_path' => $track['avatar_path'],
    'spotify_artist_id' => $track['spotify_artist_id'], 'spotify_show_id' => $track['spotify_show_id'],
    'youtube_channel_id' => $track['youtube_channel_id'], 'genere' => $track['genere'], 'account_type' => $track['account_type'], 'page_theme' => $track['page_theme'] ?? 'colorful',
];

$viewerId = $_SESSION['user_id'] ?? null;
$myRating = null;
if ($viewerId) {
    $stmt = getDB()->prepare('SELECT rating FROM track_reviews WHERE track_id=? AND reviewer_user_id=?');
    $stmt->execute([$trackId, $viewerId]);
    $row = $stmt->fetch();
    $myRating = $row ? (int) $row['rating'] : null;
}
$stats = getTrackRatingStats($trackId);
$reviewers = getDB()->prepare('SELECT tr.rating, u2.slug FROM track_reviews tr JOIN users u2 ON u2.id = tr.reviewer_user_id WHERE tr.track_id=? ORDER BY tr.created_at DESC LIMIT 30');
$reviewers->execute([$trackId]);
$reviewers = $reviewers->fetchAll();

$pageUrl = siteUrl('/' . $slug . '/brani/' . $trackId . '/votazioni');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vota: <?= e($track['track_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Vota: <?= e($track['track_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
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
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'brani') ?>

  <div class="card" style="text-align:center;">
    <?php if ($track['track_image']): ?>
      <img src="<?= e($track['track_image']) ?>" style="width:96px;height:96px;border-radius:12px;object-fit:cover;margin-bottom:10px;">
    <?php endif; ?>
    <div style="font-weight:800;font-size:18px;"><?= e($track['track_name']) ?></div>
    <div style="color:rgba(var(--text-rgb),0.6);margin-bottom:10px;"><?= e($track['artist_name']) ?></div>
    <?php if ($track['spotify_url']): ?>
      <a href="<?= e($track['spotify_url']) ?>" target="_blank" rel="noopener" style="font-size:13px;">
        <i class="fa-brands fa-spotify" style="color:#1DB954;"></i> Ascolta su Spotify
      </a>
    <?php endif; ?>
  </div>

  <div class="card" style="border:2px solid var(--accent);background:rgba(108,92,231,0.08);">
    <div class="section-title" style="margin-bottom:8px;font-size:16px;letter-spacing:0.5px;">⭐ Vota questo brano</div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
      <?= renderCromeRating($stats['avg']) ?>
      <?php if ($stats['count'] > 0): ?>
        <span style="font-size:13px;color:rgba(var(--text-rgb),0.6);"><?= $stats['avg'] ?> · <?= $stats['count'] ?> <?= $stats['count'] === 1 ? 'voto' : 'voti' ?></span>
      <?php endif; ?>
    </div>
    <?= renderRatingForm('rate_track', (int) $track['id'], $viewerId, (int) $track['user_id'], $myRating) ?>
    <?php if ($reviewers): ?>
      <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($reviewers as $r): ?>
          <span style="background:rgba(255,255,255,0.5);border-radius:999px;padding:4px 10px;font-size:12.5px;">
            @<?= e($r['slug']) ?> <?= renderCromeRating((float) $r['rating']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <p style="text-align:center;">
    <?php if (!empty($track['lyrics'])): ?>
      <a href="/<?= e($slug) ?>/brani/<?= (int) $trackId ?>/testo">📝 Testo e ascolto →</a>
    <?php endif; ?>
  </p>
  <p><a href="/<?= e($slug) ?>/brani">← Tutti i brani di <?= e($track['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($slug) ?>
</body>
</html>
