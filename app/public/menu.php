<?php
header("Content-Type: text/html; charset=utf-8");
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id, p.privacy_tracking_settings, p.menu_preconto_enabled
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

if (($artist['page_theme'] ?? 'colorful') === 'adminlte-profile') {
    echo renderAdminLteMenuPage($artist, $slug, $categories, $itemsByCategory);
    exit;
}

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

.menu-item-price-wrap { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.preconto-stepper { display: flex; align-items: center; gap: 6px; }
.preconto-stepper button { width: 24px; height: 24px; border-radius: 50%; border: none; background: #f0f0f0; color: #333; cursor: pointer; font-size: 14px; line-height: 1; flex-shrink: 0; }
.preconto-stepper button:hover { background: var(--accent); color: var(--accent-text); }
.preconto-qty { min-width: 16px; text-align: center; font-weight: 600; }
#preconto-bar { display: none; position: fixed; left: 0; right: 0; bottom: 0; z-index: 500; background: #fff; color: #222; box-shadow: 0 -2px 12px rgba(0,0,0,0.2); padding: 12px 16px; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
#preconto-bar-locked { display: flex; align-items: center; gap: 10px; background: none; border: none; color: var(--accent); font-weight: 700; font-size: 15px; cursor: pointer; padding: 0; }
#preconto-bar-unlocked { display: none; align-items: center; gap: 14px; flex-wrap: wrap; }
#preconto-total { color: var(--accent); }
#preconto-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 16px; }
#preconto-modal { background: #fff; color: #222; border-radius: 12px; padding: 20px; max-width: 380px; width: 100%; max-height: 90vh; overflow-y: auto; }
#preconto-modal h3 { color: #222; }
#preconto-modal label { display: block; margin-top: 10px; font-size: 13px; font-weight: 600; color: #222; }
#preconto-modal input[type="text"], #preconto-modal input[type="email"], #preconto-modal input[type="tel"] { width: 100%; padding: 8px 10px; margin-top: 4px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; color: #222; background: #fff; }
body.menu-preconto-active { padding-bottom: 64px; }
</style>
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
  <?= publicProfileHeader($artist, 'menu') ?>

  <?php if ($categories): ?>
    <div class="card">
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
              <div class="menu-item-price-wrap">
                <div class="menu-item-price">€ <?= e(number_format((float) $it['price'], 2, ',', '.')) ?></div>
                <?php if (!empty($artist['menu_preconto_enabled'])): ?>
                  <span class="preconto-stepper" data-id="<?= (int) $it['id'] ?>" data-price="<?= e((string) (float) $it['price']) ?>">
                    <button type="button" data-delta="-1">−</button>
                    <span class="preconto-qty">0</span>
                    <button type="button" data-delta="1">+</button>
                  </span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card">Il menù non è ancora disponibile.</div>
  <?php endif; ?>

  <?php if ($allItems && array_filter($allItems, fn($it) => parseMenuAllergens($it['allergens'] ?? null))): ?>
    <p class="menu-allergen-legend">
      Allergeni:
      <?php foreach (MENU_ALLERGENS as $aId => $aLabel): ?><?= $aId ?>. <?= e($aLabel) ?><?= $aId < count(MENU_ALLERGENS) ? ' · ' : '' ?><?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if (!empty($artist['menu_preconto_enabled']) && $allItems && array_filter($allItems, fn($it) => $it['price'] !== null)): ?>
    <?php
      $precontoMsg = $_GET['preconto_msg'] ?? '';
      $precontoErr = !empty($_GET['preconto_err']);
      $precontoFollowTerms = trim(getSiteSetting('follow_terms_content') ?: '');
    ?>
    <?php if ($precontoMsg): ?>
      <div class="alert <?= $precontoErr ? 'error' : 'success' ?>"><?= e($precontoMsg) ?></div>
    <?php endif; ?>

    <div id="preconto-bar">
      <button type="button" id="preconto-bar-locked">🧮 Calcola il tuo preconto</button>
      <div id="preconto-bar-unlocked">
        <span>Totale: <strong id="preconto-total">€ 0,00</strong></span>
        <button type="button" class="btn secondary" id="preconto-reset-btn" style="width:auto;">Svuota</button>
      </div>
    </div>

    <div id="preconto-modal-backdrop" style="display:none;">
      <div id="preconto-modal">
        <h3 style="margin-top:0;">Lascia i tuoi dati per attivare il preconto</h3>
        <form method="post" action="/menu_preconto.php">
          <?= csrfField() ?>
          <input type="hidden" name="slug" value="<?= e($slug) ?>">
          <label>Nome</label>
          <input type="text" name="first_name" required>
          <label>Cognome</label>
          <input type="text" name="last_name" required>
          <label>Email</label>
          <input type="email" name="email" required>
          <label>Telefono</label>
          <input type="tel" name="phone" required>
          <label>CAP</label>
          <input type="text" name="postal_code" required maxlength="10">
          <?php if ($precontoFollowTerms !== ''): ?>
            <label style="display:flex;align-items:flex-start;gap:6px;font-weight:normal;font-size:12px;">
              <input type="checkbox" name="accept_terms" value="1" required style="width:auto;margin-top:2px;">
              <span>Accetto i <a href="/termini_segui.php" target="_blank" rel="noopener">Termini di Utilizzo</a></span>
            </label>
          <?php endif; ?>
          <div style="display:flex;gap:8px;margin-top:14px;">
            <button type="submit" class="btn">Conferma e attiva</button>
            <button type="button" class="btn secondary" id="preconto-modal-cancel">Annulla</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    (function () {
      var userId = <?= (int) $artist['id'] ?>;
      var cookieName = 'preconto_ok_' + userId;
      var storageKey = 'preconto_qty_' + userId;
      var steppers = document.querySelectorAll('.preconto-stepper');
      if (!steppers.length) return;
      document.body.classList.add('menu-preconto-active');

      var bar = document.getElementById('preconto-bar');
      var barLocked = document.getElementById('preconto-bar-locked');
      var barUnlocked = document.getElementById('preconto-bar-unlocked');
      var totalEl = document.getElementById('preconto-total');
      var resetBtn = document.getElementById('preconto-reset-btn');
      var modalBackdrop = document.getElementById('preconto-modal-backdrop');
      var modalCancel = document.getElementById('preconto-modal-cancel');

      function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : null;
      }
      function isUnlocked() {
        return !!getCookie(cookieName);
      }
      function loadQty() {
        try { return JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (e) { return {}; }
      }
      function saveQty(qty) {
        try { localStorage.setItem(storageKey, JSON.stringify(qty)); } catch (e) {}
      }
      function formatEuro(v) {
        return '€ ' + v.toFixed(2).replace('.', ',');
      }
      function render() {
        var unlocked = isUnlocked();
        bar.style.display = 'flex';
        barLocked.style.display = unlocked ? 'none' : 'flex';
        barUnlocked.style.display = unlocked ? 'flex' : 'none';
        var qty = loadQty();
        var total = 0;
        steppers.forEach(function (stepper) {
          var id = stepper.getAttribute('data-id');
          var price = parseFloat(stepper.getAttribute('data-price'));
          var q = unlocked ? (qty[id] || 0) : 0;
          total += q * price;
          stepper.querySelector('.preconto-qty').textContent = q;
        });
        totalEl.textContent = formatEuro(total);
      }

      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.preconto-stepper button');
        if (btn) {
          if (!isUnlocked()) {
            modalBackdrop.style.display = 'flex';
            return;
          }
          var stepper = btn.closest('.preconto-stepper');
          var id = stepper.getAttribute('data-id');
          var delta = parseInt(btn.getAttribute('data-delta'), 10);
          var qty = loadQty();
          qty[id] = Math.max(0, (qty[id] || 0) + delta);
          saveQty(qty);
          render();
          return;
        }
        if (e.target.closest('#preconto-bar-locked')) {
          modalBackdrop.style.display = 'flex';
        }
      });
      resetBtn.addEventListener('click', function () {
        saveQty({});
        render();
      });
      modalCancel.addEventListener('click', function () {
        modalBackdrop.style.display = 'none';
      });

      render();
    })();
    </script>
  <?php endif; ?>
</div>

<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>

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
