<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'nav_menu';
$pageTitle = 'Menu di Navigazione';
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $items = getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']);
    foreach ($items as $item) {
        $isVisible = isset($_POST['visibility'][$item['id']]);
        getDB()->prepare('UPDATE profile_navigation_menu SET is_visible = ? WHERE id = ? AND user_id = ?')
            ->execute([$isVisible ? 1 : 0, $item['id'], $profile['id']]);
    }
    $success = 'Menu aggiornato.';
}

$items = getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']);
$visibleItems = array_values(array_filter($items, fn($it) => (bool) $it['is_visible']));

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Scegli quali voci mostrare nel menu di navigazione della tua pagina pubblica. Le
      integrazioni (Spotify, Podcast, Video, il pulsante "Segui") non sono elencate qui: compaiono
      automaticamente solo quando le colleghi dalla loro sezione dedicata.
    </p>
  </details>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <?php foreach ($items as $it): ?>
      <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px;font-weight:normal;">
        <input type="checkbox" name="visibility[<?= (int) $it['id'] ?>]" value="1" style="width:auto;" <?= $it['is_visible'] ? 'checked' : '' ?>>
        <?php if ($it['icon']): ?><i class="<?= e($it['icon']) ?>" style="width:20px;text-align:center;color:var(--text-muted);"></i><?php endif; ?>
        <strong><?= e($it['name']) ?></strong>
        <small style="color:var(--text-muted)"><?= e($it['url']) ?></small>
      </label>
    <?php endforeach; ?>
    <button type="submit" class="btn">Salva</button>
  </form>

  <div class="section-title">Anteprima menu pubblico</div>
  <div class="card">
    <?php if ($visibleItems): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach ($visibleItems as $it): ?>
          <span class="icon-btn" style="width:auto;padding:0 14px;gap:8px;display:inline-flex;">
            <?php if ($it['icon']): ?><i class="<?= e($it['icon']) ?>"></i><?php endif; ?>
            <?= e($it['name']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <span style="color:var(--text-muted)">Nessuna voce di menu visibile.</span>
    <?php endif; ?>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
