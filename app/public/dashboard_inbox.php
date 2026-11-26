<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'inbox';
$pageTitle = 'Messaggistica';

require_once __DIR__ . '/../src/dash_right.php';
$isBandOrLabel = in_array($profile['account_type'] ?? 'band', ['band', 'label'], true);
$nav = array_column(getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']), 'is_visible', 'name');
$cards = dashInboxCards((int) $profile['id'], $isBandOrLabel, $nav);

include __DIR__ . '/_dash_header.php';
?>
<div class="container">
  <h2>Messaggistica</h2>
  <ul>
    <?php foreach ($cards as [$label, $icon, $url, $n, $desc]): ?>
      <li><a href="<?= e($url) ?>"><i class="<?= e($icon) ?>"></i> <?= e($label) ?></a> — <?= (int) $n ?> da leggere. <?= e($desc) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
