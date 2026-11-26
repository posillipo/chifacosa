<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/fn_cruscotto.php';
$user = requireAdmin(); // strumento riservato agli amministratori del sito
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'cruscotto';
$pageTitle = 'Cruscotto';
$nav = array_column(getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']), 'is_visible', 'name');
$data = cruscottoData($user, $profile, $nav);

include __DIR__ . '/_dash_header.php';
?>
<div class="container">
  <h2>Cruscotto</h2>
  <h3>Messaggistica (da leggere)</h3>
  <ul><?php foreach ($data['inbox']['now'] as $r): ?><li><a href="<?= e($r['url']) ?>"><?= e($r['label']) ?></a>: <?= (int) $r['value'] ?></li><?php endforeach; ?></ul>
  <h3>Coda Social (30 giorni)</h3>
  <ul><?php foreach ($data['queue']['status'] as $st => $n): ?><li><?= e($st) ?>: <?= (int) $n ?></li><?php endforeach; ?></ul>
  <h3>API / MCP (24 ore)</h3>
  <p>Chiamate: <?= (int) $data['api']['calls'] ?> · Errori: <?= (int) $data['api']['errors'] ?></p>
  <h3>Marketing (30 giorni)</h3>
  <p>In corso: <?= (int) $data['marketing']['runs']['running'] ?> · Completate: <?= (int) $data['marketing']['runs']['completed'] ?> · Interrotte: <?= (int) $data['marketing']['runs']['interrupted'] ?></p>
  <h3>Collegamenti</h3>
  <ul><?php foreach ($data['links'] as $l): ?><li><a href="<?= e($l['url']) ?>"><?= e($l['label']) ?></a> (<?= e($l['group']) ?>)</li><?php endforeach; ?></ul>
</div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
