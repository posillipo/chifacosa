<?php
header("Content-Type: text/html; charset=utf-8");
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$stmt = getDB()->prepare('SELECT * FROM menu_categories WHERE user_id=? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$artist['id']]);
$categories = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT * FROM menu_items WHERE user_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC');
$stmt->execute([$artist['id']]);
$allItems = $stmt->fetchAll();
$itemsByCategory = [];
foreach ($allItems as $it) {
    $itemsByCategory[(int) $it['category_id']][] = $it;
}
// Le categorie senza piatti attivi non hanno senso da mostrare pubblicamente
$categories = array_values(array_filter($categories, fn($c) => !empty($itemsByCategory[(int) $c['id']])));

$pageUrl = siteUrl('/' . $slug . '/menu');
$ogDescription = 'Il menù di ' . $artist['display_name'] . ' su ' . siteName();
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Menù di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Menù di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>
:root { --accent: <?= e($artist['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($artist['theme_color'])) ?>; }

.menu-tabs-container {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding: 20px 0;
    margin-bottom: 30px;
    border-bottom: 2px solid #eee;
    flex-wrap: wrap;
}

.menu-tab {
    padding: 10px 16px;
    border: none;
    background: #f5f5f5;
    color: #333;
    cursor: pointer;
    border-radius: 6px;
    font-weight: 500;
    font-size: 14px;
    white-space: nowrap;
    transition: all 0.3s ease;
}

.menu-tab:hover {
    background: #e8e8e8;
}

.menu-tab.active {
    background: var(--accent);
    color: white;
}

.menu-category {
    display: none;
    animation: fadeIn 0.3s ease;
}

.menu-category.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@media (max-width: 768px) {
    .menu-tabs-container {
        gap: 6px;
        padding: 15px 0;
    }
    
    .menu-tab {
        padding: 8px 12px;
        font-size: 13px;
    }
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
<?php if (($artist['page_theme'] ?? 'colorful') === 'cinemapop'): ?><?= renderCinemaPopBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'startrek'): ?><?= renderStarTrekBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'galactic'): ?><?= renderGalacticBackground() ?><?php endif; ?>
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'menu') ?>

  <?php if ($categories): ?>
    <!-- MENU TABS -->
    <div class="menu-tabs-container">
      <?php foreach ($categories as $index => $cat): ?>
        <button class="menu-tab <?= $index === 0 ? 'active' : '' ?>" data-category-id="<?= (int) $cat['id'] ?>">
          <?= e($cat['name']) ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- MENU CONTENT -->
    <?php foreach ($categories as $index => $cat): ?>
      <div class="menu-category <?= $index === 0 ? 'active' : '' ?>" data-category-id="<?= (int) $cat['id'] ?>">
        <div class="menu-category-title"><?= e($cat['name']) ?></div>
        <?php foreach ($itemsByCategory[(int) $cat['id']] as $it): ?>
          <?php $allergens = parseMenuAllergens($it['allergens'] ?? null); ?>
          <div class="menu-item-row">
            <div class="menu-item-name">
              <?= e($it['name']) ?><?php foreach ($allergens as $aId): ?><sup title="<?= e(MENU_ALLERGENS[$aId]) ?>"><?= $aId ?></sup><?php endforeach; ?>
              <?php if ($it['description']): ?><div class="menu-item-desc"><?= e($it['description']) ?></div><?php endif; ?>
            </div>
            <?php if ($it['price'] !== null): ?>
              <div class="menu-item-price">€ <?= e(number_format((float) $it['price'], 2, ',', '.')) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="card">Il menù non è ancora disponibile.</div>
  <?php endif; ?>

  <?php if ($allItems && array_filter($allItems, fn($it) => parseMenuAllergens($it['allergens'] ?? null))): ?>
    <p class="menu-allergen-legend">
      Allergeni:
      <?php foreach (MENU_ALLERGENS as $aId => $aLabel): ?><?= $aId ?>. <?= e($aLabel) ?><?= $aId < count(MENU_ALLERGENS) ? ' · ' : '' ?><?php endforeach; ?>
    </p>
  <?php endif; ?>
</div>

<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($slug) ?>

<script>
// Menu tabs functionality
(function () {
  const tabs = document.querySelectorAll('.menu-tab');
  const categories = document.querySelectorAll('.menu-category');

  tabs.forEach(tab => {
    tab.addEventListener('click', function () {
      const categoryId = this.getAttribute('data-category-id');
      
      tabs.forEach(t => t.classList.remove('active'));
      categories.forEach(c => c.classList.remove('active'));
      
      this.classList.add('active');
      document.querySelector(`.menu-category[data-category-id="${categoryId}"]`)?.classList.add('active');
      document.querySelector('.menu-category.active')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();
</script>

<?= embedTrackingBodyEnd() ?>
</body>
</html>
