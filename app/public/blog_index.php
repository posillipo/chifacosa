<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userSlug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.genere
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$userSlug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$stmt = getDB()->prepare('SELECT * FROM blog_posts WHERE user_id=? ORDER BY published_at DESC');
$stmt->execute([$artist['id']]);
$posts = $stmt->fetchAll();

$pageUrl = siteUrl('/' . $userSlug . '/blog');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Blog di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Blog di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($artist['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($artist['theme_color'])) ?>; }</style>
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
  <?= publicProfileHeader($artist, 'blog') ?>

  <?php if (!$posts): ?>
    <div class="card">Nessun articolo pubblicato ancora.</div>
  <?php endif; ?>

  <?php foreach ($posts as $i => $p): ?>
    <a href="<?= e(blogPostUrl($userSlug, $p)) ?>" class="color-link-btn"
       style="background:<?= e(COLORFUL_PALETTE[$i % count(COLORFUL_PALETTE)]) ?>; display:flex; align-items:center; gap:14px; text-align:left;">
      <?php if ($p['cover_path']): ?>
        <img src="/<?= e($p['cover_path']) ?>" style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;">
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:10px;background:rgba(255,255,255,0.5);flex-shrink:0;display:flex;align-items:center;justify-content:center;">
          <svg viewBox="0 0 24 24" width="30" height="30" fill="rgba(var(--text-rgb),0.45)">
            <path d="M9 2L7.17 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3.17L15 2H9zm3 15a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
          </svg>
        </div>
      <?php endif; ?>
      <span style="flex:1;min-width:0;">
        <strong style="display:block;"><?= e($p['title']) ?></strong>
        <small style="opacity:.75;"><?= date('d/m/Y', strtotime($p['published_at'])) ?></small>
      </span>
    </a>
  <?php endforeach; ?>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($userSlug) ?>
</body>
</html>
