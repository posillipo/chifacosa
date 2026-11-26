<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: stessi dati della pagina originale, solo la parte visiva a card */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'inbox';
$pageTitle = 'Messaggistica';

require_once __DIR__ . '/../../src/dash_right.php';
$isBandOrLabel = in_array($profile['account_type'] ?? 'band', ['band', 'label'], true);
$nav = array_column(getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']), 'is_visible', 'name');
$cards = dashInboxCards((int) $profile['id'], $isBandOrLabel, $nav);
$total = 0; foreach ($cards as $c) { $total += (int) $c[3]; }
$colors = ['messages' => 1, 'contacts' => 2, 'inquiries' => 3, 'reservations' => 4];

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx cam inbx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= $total ?></b><span>Da leggere in tutto</span></div>
      <div class="tlx-kpi"><b><?= count(array_filter($cards, fn ($c) => $c[3] > 0)) ?> / <?= count($cards) ?></b><span>Aree con novità</span></div>
    </div>

    <div class="tlx-listhead"><h2>Tutte le tue comunicazioni</h2></div>

    <div class="cam-grid">
      <?php foreach ($cards as $key => [$label, $icon, $url, $n, $desc]): ?>
        <div class="cam-tile cam-c<?= (int) ($colors[$key] ?? 1) ?><?= $n === 0 ? ' is-empty' : ' has-new' ?>">
          <a href="<?= e($url) ?>" class="cam-card">
            <span class="cam-ic"><i class="<?= e($icon) ?>"></i></span>
            <b><?= e($label) ?></b>
            <?php if ($n > 0): ?>
              <span class="cam-n inbx-n"><?= (int) $n ?> da leggere</span>
            <?php else: ?>
              <span class="cam-n">Tutto letto</span>
            <?php endif; ?>
            <span class="inbx-d"><?= e($desc) ?></span>
            <span class="cam-go">Apri <i class="fa-solid fa-arrow-right"></i></span>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
