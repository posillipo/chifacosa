<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$postId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.privacy_tracking_settings, p.genere, p.custom_feed_guid, p.custom_feed_guid_since, tp.*
                          FROM timeline_posts tp
                          JOIN users u ON u.id = tp.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND tp.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $postId]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    exit('Contenuto non trovato.');
}

$isOwner = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $post['user_id'];
$isScheduledFuture = $post['publish_at'] && strtotime($post['publish_at']) > time();
if (!$isOwner && ($post['visibility'] === 'private' || $isScheduledFuture)) {
    http_response_code(404);
    exit('Contenuto non trovato.');
}

$artist = [
    'id' => $post['user_id'],
    'slug' => $slug,
    'display_name' => $post['display_name'],
    'avatar_path' => $post['avatar_path'],
    'spotify_artist_id' => $post['spotify_artist_id'],
    'spotify_show_id' => $post['spotify_show_id'],
    'youtube_channel_id' => $post['youtube_channel_id'],
    'privacy_tracking_settings' => $post['privacy_tracking_settings'] ?? null,
    'genere' => $post['genere'],
    'account_type' => $post['account_type'],
    'page_theme' => $post['page_theme'] ?? 'colorful',
];

// Foto in ordine di caricamento: la prima è sempre quella su image_path (l'unica che compare
// anche nel Feed/Timeline), le altre — se presenti — arrivano da timeline_post_photos e formano
// insieme a questa un carosello scorrevole stile Instagram sulla pagina di dettaglio.
$photos = array_values(array_filter(array_merge([$post['image_path']], getTimelinePostPhotos($postId))));

$pageUrl = siteUrl('/' . $slug . '/timeline/' . $postId);
$ogImage = $post['image_path'] ? siteUrl($post['image_path']) : ($post['avatar_path'] ? siteUrl($post['avatar_path']) : null);
$anteprima = $post['testo'] ? textExcerpt($post['testo'], 150) : ('Nuovo aggiornamento su ' . siteName());
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($post['custom_feed_guid'], $post['custom_feed_guid_since'], $post['created_at']); ?>
<title><?= e($post['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($anteprima) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($post['display_name']) ?> su <?= e(siteName()) ?>">
<meta property="og:description" content="<?= e($anteprima) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($post['display_name']) ?> su <?= e(siteName()) ?>">
<meta name="twitter:description" content="<?= e($anteprima) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($post['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($post['theme_color'])) ?>; }</style>
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
  <?= publicProfileHeader($artist, 'timeline') ?>

  <div class="card">
    <?php if (count($photos) > 1): ?>
      <div class="ig-carousel">
        <div class="ig-carousel-track" id="ig-track">
          <?php foreach ($photos as $ph): ?>
            <img src="/<?= e($ph) ?>" alt="" loading="lazy">
          <?php endforeach; ?>
        </div>
        <button type="button" class="ig-arrow ig-arrow-prev" id="ig-prev" aria-label="Foto precedente">‹</button>
        <button type="button" class="ig-arrow ig-arrow-next" id="ig-next" aria-label="Foto successiva">›</button>
        <div class="ig-carousel-dots" id="ig-dots">
          <?php foreach ($photos as $i => $ph): ?>
            <span class="ig-dot<?= $i === 0 ? ' active' : '' ?>" data-index="<?= $i ?>"></span>
          <?php endforeach; ?>
        </div>
      </div>
      <style>
        .ig-carousel { position:relative; max-width:400px; margin:0 auto 16px; }
        .ig-carousel-track { display:flex; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; border-radius:14px; box-shadow:0 8px 24px rgba(0,0,0,0.15); }
        .ig-carousel-track::-webkit-scrollbar { display:none; }
        .ig-carousel-track img { scroll-snap-align:center; flex:0 0 100%; width:100%; aspect-ratio:1/1; object-fit:cover; }
        .ig-carousel-dots { display:flex; justify-content:center; gap:6px; margin-top:10px; }
        .ig-dot { width:6px; height:6px; border-radius:50%; background:rgba(var(--text-rgb),0.25); cursor:pointer; transition:background .15s; }
        .ig-dot.active { background:var(--accent); }
        /* Frecce: servono solo a chi ha un mouse vero (niente swipe col dito) — su touch
           restano nascoste, lì basta e avanza scorrere con il dito come già accade. */
        .ig-arrow { display:none; }
        @media (hover:hover) and (pointer:fine) {
          .ig-arrow {
            display:flex; align-items:center; justify-content:center;
            position:absolute; top:50%; transform:translateY(-50%);
            width:32px; height:32px; border-radius:50%; border:none;
            background:rgba(0,0,0,0.45); color:#fff; font-size:20px; line-height:1;
            cursor:pointer; z-index:5;
          }
          .ig-arrow:hover { background:rgba(0,0,0,0.65); }
          .ig-arrow-prev { left:8px; }
          .ig-arrow-next { right:8px; }
        }
      </style>
      <script>
      (function () {
        var track = document.getElementById('ig-track');
        var dots = document.querySelectorAll('#ig-dots .ig-dot');
        var prevBtn = document.getElementById('ig-prev');
        var nextBtn = document.getElementById('ig-next');
        if (!track || !dots.length) return;
        var count = dots.length;

        function goTo(idx) {
          idx = Math.max(0, Math.min(count - 1, idx));
          track.scrollTo({ left: idx * track.clientWidth, behavior: 'smooth' });
        }
        function currentIndex() {
          return Math.round(track.scrollLeft / track.clientWidth);
        }

        dots.forEach(function (dot) {
          dot.addEventListener('click', function () { goTo(parseInt(dot.dataset.index, 10)); });
        });
        if (prevBtn) prevBtn.addEventListener('click', function () { goTo(currentIndex() - 1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { goTo(currentIndex() + 1); });

        var ticking = false;
        track.addEventListener('scroll', function () {
          if (ticking) return;
          ticking = true;
          requestAnimationFrame(function () {
            var idx = currentIndex();
            dots.forEach(function (dot, i) { dot.classList.toggle('active', i === idx); });
            ticking = false;
          });
        });
      })();
      </script>
    <?php elseif ($photos): ?>
      <img src="/<?= e($photos[0]) ?>" alt=""
           style="width:100%;max-width:400px;display:block;margin:0 auto 16px;border-radius:14px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.15);">
    <?php endif; ?>
    <small style="color:rgba(var(--text-rgb),0.6);"><?= date('d/m/Y H:i', strtotime($post['created_at'])) ?></small>
    <?php if ($post['testo']): ?>
      <p style="margin-top:8px;font-size:16px;"><?= nl2br(e($post['testo'])) ?></p>
    <?php endif; ?>
  </div>

  <p><a href="/<?= e($slug) ?>/timeline">← Tutta la Timeline di <?= e($post['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
