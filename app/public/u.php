<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/geocoding.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.*, p.display_name, p.bio, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    echo '<h1 style="font-family:sans-serif;text-align:center;margin-top:80px;">Pagina non trovata</h1>';
    exit;
}

// Se Spotify è collegato, sulla Home mostriamo un'anteprima del profilo Spotify (stile
// Link in Bio, quadrati) al posto di "Band che amo" — quest'ultima resta comunque disponibile
// sulla sua pagina dedicata, semplicemente non occupa questo spazio sulla Home quando c'è
// già un profilo Spotify da mostrare.
$spotifyPreview = [];
$spotifyPreviewTotal = 0;
if (!empty($artist['spotify_artist_id'])) {
    require_once __DIR__ . '/../src/spotify.php';
    $spotifyAlbums = spotifyGetArtistAlbums($artist['spotify_artist_id']);
    $spotifyPreviewTotal = count($spotifyAlbums);
    $spotifyPreview = array_slice($spotifyAlbums, 0, 6);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate_band') {
    checkCsrf();
    $viewerId = $_SESSION['user_id'] ?? null;
    $targetId = (int) ($_POST['target_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    if ($viewerId && $viewerId !== $targetId && $rating >= 1 && $rating <= 5 && $targetId === (int) $artist['id']) {
        $stmt = getDB()->prepare('INSERT INTO band_reviews (band_user_id, reviewer_user_id, rating) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating)');
        $stmt->execute([$targetId, $viewerId, $rating]);

        $stmt = getDB()->prepare('SELECT slug FROM users WHERE id = ?');
        $stmt->execute([$viewerId]);
        $voter = $stmt->fetch();
        if ($voter && !empty($artist['email'])) {
            notifyNewVote($artist['email'], $artist['display_name'], $voter['slug'], $rating, 'il tuo profilo', '/' . $slug . '#recensioni');
        }
    }
    header('Location: /' . $slug . '#recensioni');
    exit;
}

$uid = $artist['id'];

$links = getDB()->prepare('SELECT * FROM links WHERE user_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC');
$links->execute([$uid]);
$links = $links->fetchAll();

$pageUrl = siteUrl('/' . $slug);
$ogImage = $artist['avatar_path'] ? siteUrl($artist['avatar_path']) : null;
$ogDescription = $artist['bio'] ? textExcerpt($artist['bio']) : ('La pagina di ' . $artist['display_name'] . ' su ' . siteName());

// Separiamo i link riconosciuti come social (icona tonda in alto, una sola per piattaforma)
// dagli altri (pulsante colorato grande) — include eventuali social ripetuti
[$socialLinks, $actionLinks] = splitSocialAndActionLinks($links);

$followerCount = getFollowerCount($uid);
$followMsg = $_GET['follow_msg'] ?? '';
$followErr = ($_GET['follow_err'] ?? '0') === '1';

$fanFavorites = [];
$stmt = getDB()->prepare('SELECT * FROM fan_favorite_bands WHERE user_id=? ORDER BY sort_order ASC');
$stmt->execute([$uid]);
$fanFavorites = $stmt->fetchAll();
$fanFavoritesTotal = count($fanFavorites);
$fanFavoritesPreview = array_slice($fanFavorites, 0, 6);

$bandRatingStats = getBandRatingStats((int) $uid);
$viewerId = $_SESSION['user_id'] ?? null;
$myBandRating = null;
if ($viewerId) {
    $stmt = getDB()->prepare('SELECT rating FROM band_reviews WHERE band_user_id=? AND reviewer_user_id=?');
    $stmt->execute([$uid, $viewerId]);
    $row = $stmt->fetch();
    $myBandRating = $row ? (int) $row['rating'] : null;
}
$bandReviewers = getDB()->prepare('SELECT br.rating, u2.slug FROM band_reviews br JOIN users u2 ON u2.id = br.reviewer_user_id WHERE br.band_user_id=? ORDER BY br.created_at DESC LIMIT 20');
$bandReviewers->execute([$uid]);
$bandReviewers = $bandReviewers->fetchAll();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">

<!-- Open Graph / condivisione social -->
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= e($artist['display_name']) ?> — <?= e(siteName()) ?>" href="<?= e(siteUrl('/' . $slug . '/feed')) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<style>
  :root {
    --accent: <?= e($artist['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($artist['theme_color'])) ?>;
    --cf-1: #FFD6A5; --cf-2: #A0C4FF; --cf-3: #BDB2FF;
  }
</style>
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body class="<?= e(getPageThemeClass($artist['page_theme'] ?? 'colorful')) ?>">
<?php if (str_starts_with($artist['page_theme'] ?? 'colorful', 'wave')): ?><?= renderWaveBackground($artist['theme_color'] ?? '#6C5CE7', $artist['page_theme']) ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'circuit'): ?><?= renderCircuitBackground($artist['theme_color'] ?? '#6C5CE7') ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'napoli'): ?><?= renderNapoliBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'startrek'): ?><?= renderStarTrekBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'galactic'): ?><?= renderGalacticBackground() ?><?php endif; ?>
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'home', true) ?>

  <?php if ($followMsg): ?>
    <div class="alert <?= $followErr ? 'error' : 'success' ?>"><?= e($followMsg) ?></div>
  <?php endif; ?>

  <div id="segui-widget" style="text-align:center;margin-bottom:18px;scroll-margin-top:20px;">
    <?php if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] !== (int)$uid): ?>
      <?php $alreadyFollowing = isFollowingAccount((int)$_SESSION['user_id'], (int)$uid); ?>
      <form method="post" action="/follow_account.php" style="display:inline;">
        <?= csrfField() ?>
        <input type="hidden" name="user_id" value="<?= (int)$uid ?>">
        <input type="hidden" name="action" value="<?= $alreadyFollowing ? 'unfollow' : 'follow' ?>">
        <input type="hidden" name="redirect" value="/<?= e($slug) ?>">
        <button type="submit" class="segui-pill">
          <?= $alreadyFollowing ? '✓ Segui già' : '✨ Segui' ?>
        </button>
      </form>
      <div style="color:rgba(var(--text-rgb),0.6);font-size:12px;margin-top:4px;">
        <?= getAccountFollowerCount((int)$uid) ?> ti seguono su <?= e(siteName()) ?>
      </div>
    <?php else: ?>
      <details class="segui-pill-details">
        <summary class="segui-pill">✨ Segui</summary>
        <form method="post" action="/follow.php" style="display:flex;gap:6px;flex-wrap:wrap;justify-content:center;align-items:center;margin-top:8px;">
          <?= csrfField() ?>
          <input type="hidden" name="slug" value="<?= e($slug) ?>">
          <input type="email" name="email" placeholder="La tua email" required style="flex:1;min-width:160px;max-width:240px;margin-bottom:0;font-size:13px;padding:8px 12px;">
          <button type="submit" class="btn small" style="background:rgb(108,92,231);">Conferma</button>
        </form>
      </details>
      <div style="color:rgba(var(--text-rgb),0.6);font-size:12px;margin-top:4px;">
        <?= $followerCount > 0 ? $followerCount . ($followerCount === 1 ? ' persona segue' : ' persone seguono') : 'ricevi una notifica quando pubblica' ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($socialLinks): ?>
    <div class="social-icons-row">
      <?php foreach ($socialLinks as $l): ?>
        <a class="social-icon-btn" title="<?= e($l['platform']['label']) ?>" target="_blank" rel="noopener"
           href="/link.php?id=<?= (int)$l['id'] ?>"><i class="<?= e($l['platform']['icon_class']) ?>"></i></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($actionLinks): ?>
    <?php $colorIdx = 0; ?>
    <?php foreach ($actionLinks as $l): ?>
      <?php if (($l['link_type'] ?? 'link') === 'divider'): ?>
        <div class="link-divider"><span><?= e($l['label']) ?></span></div>
      <?php elseif (($l['link_type'] ?? 'link') === 'map'): ?>
        <?php if ($l['label']): ?><div class="link-map-label"><?= e($l['label']) ?></div><?php endif; ?>
        <?= renderOsmEmbed((float)$l['map_lat'], (float)$l['map_lng']) ?>
      <?php else: ?>
        <a class="color-link-btn" style="background:<?= e(COLORFUL_PALETTE[$colorIdx % count(COLORFUL_PALETTE)]) ?>;"
           target="_blank" rel="noopener"
           href="/link.php?id=<?= (int)$l['id'] ?>">
          <?php if ($l['cover_path']): ?>
            <img src="/<?= e($l['cover_path']) ?>" class="btn-cover-icon">
          <?php endif; ?>
          <?= e($l['label']) ?>
        </a>
        <?php $colorIdx++; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($spotifyPreview): ?>
    <div class="section-title" style="text-align:center;color:rgba(var(--text-rgb),0.6);margin:18px 0 10px;">Spotify</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;margin-bottom:10px;">
      <?php foreach ($spotifyPreview as $a): ?>
        <a href="<?= e($a['spotify_url']) ?>" target="_blank" rel="noopener" style="text-decoration:none;color:inherit;">
          <?php if ($a['image']): ?>
            <img src="<?= e($a['image']) ?>" alt="" style="width:100%;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,0.12);">
          <?php endif; ?>
          <div style="margin-top:6px;font-size:13px;font-weight:700;"><?= e($a['name']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($spotifyPreviewTotal > 6): ?>
      <p style="text-align:center;margin-bottom:18px;">
        <a href="/<?= e($slug) ?>/spotify">Vedi tutto su Spotify →</a>
      </p>
    <?php endif; ?>
  <?php elseif ($fanFavorites): ?>
    <div class="section-title" style="text-align:center;color:rgba(var(--text-rgb),0.6);margin:18px 0 10px;">Band che amo</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:10px;">
      <?php foreach ($fanFavoritesPreview as $f): ?>
        <a href="https://open.spotify.com/artist/<?= e($f['spotify_artist_id']) ?>" target="_blank" rel="noopener"
           class="card" style="text-align:center;text-decoration:none;color:inherit;padding:14px 8px;">
          <?php if ($f['artist_image']): ?>
            <img src="<?= e($f['artist_image']) ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;margin-bottom:8px;">
          <?php endif; ?>
          <div style="font-weight:700;font-size:13px;"><?= e($f['spotify_artist_name']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($fanFavoritesTotal > 6): ?>
      <p style="text-align:center;margin-bottom:18px;">
        <a href="/<?= e($slug) ?>/band-che-amo">Vedi tutte (<?= $fanFavoritesTotal ?>) →</a>
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <div id="recensioni" class="card" style="scroll-margin-top:20px;">
    <div class="section-title" style="margin-bottom:8px;">Recensioni</div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
      <?= renderCromeRating($bandRatingStats['avg']) ?>
      <?php if ($bandRatingStats['count'] > 0): ?>
        <span style="font-size:13px;color:rgba(var(--text-rgb),0.6);"><?= $bandRatingStats['avg'] ?> · <?= $bandRatingStats['count'] ?> <?= $bandRatingStats['count'] === 1 ? 'voto' : 'voti' ?></span>
      <?php endif; ?>
    </div>
    <?= renderRatingForm('rate_band', (int) $uid, $viewerId, (int) $uid, $myBandRating) ?>
    <?php if ($bandReviewers): ?>
      <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($bandReviewers as $r): ?>
          <span style="background:rgba(255,255,255,0.5);border-radius:999px;padding:4px 10px;font-size:12.5px;">
            @<?= e($r['slug']) ?> <?= renderCromeRating((float) $r['rating']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($slug) ?>
</body>
</html>
