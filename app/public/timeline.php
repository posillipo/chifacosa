<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.privacy_tracking_settings, p.genere
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

const TIMELINE_PAGE_SIZE = 20;
$feed = getTimelineFeedForUsers([$artist['id']], TIMELINE_PAGE_SIZE, 0);
$pageUrl = siteUrl('/' . $slug . '/timeline');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Timeline di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Timeline di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
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
  <?= publicProfileHeader($artist, 'timeline') ?>

  <div id="timeline-feed">
    <?php if (!$feed): ?>
      <div class="card">Nessun contenuto pubblicato ancora.</div>
    <?php else: ?>
      <?php foreach ($feed as $item): ?>
        <?= renderTimelineFeedItem($item) ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div id="timeline-sentinel" style="height:1px;"></div>
  <p id="timeline-loading" style="text-align:center;color:rgba(var(--text-rgb),0.5);display:none;">Caricamento...</p>
  <p id="timeline-end" style="text-align:center;color:rgba(var(--text-rgb),0.5);display:none;">Hai visto tutto.</p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>

<script>
(function () {
  var slug = <?= json_encode($slug) ?>;
  var offset = <?= count($feed) ?>;
  var pageSize = <?= TIMELINE_PAGE_SIZE ?>;
  var loading = false;
  var finished = <?= count($feed) < TIMELINE_PAGE_SIZE ? 'true' : 'false' ?>;
  var feedEl = document.getElementById('timeline-feed');
  var loadingEl = document.getElementById('timeline-loading');
  var endEl = document.getElementById('timeline-end');
  var sentinel = document.getElementById('timeline-sentinel');

  function loadMore() {
    if (loading || finished) return;
    loading = true;
    loadingEl.style.display = 'block';
    fetch('/timeline_more.php?slug=' + encodeURIComponent(slug) + '&offset=' + offset)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        loadingEl.style.display = 'none';
        if (data.html) {
          feedEl.insertAdjacentHTML('beforeend', data.html);
        }
        offset += data.count;
        if (data.count < pageSize) {
          finished = true;
          endEl.style.display = 'block';
        }
        loading = false;
      })
      .catch(function () {
        loading = false;
        loadingEl.style.display = 'none';
      });
  }

  if (!finished && 'IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) loadMore();
    });
    observer.observe(sentinel);
  } else if (finished) {
    endEl.style.display = 'block';
  }
})();
</script>
</body>
</html>
