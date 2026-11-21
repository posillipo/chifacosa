<?php
require_once __DIR__ . '/_studio.php';
$c = studioBoot();
$mine = dashboardFeedEnrich(getTimelineFeedForUsers([$c['pid']], 5, 0, $c['pid']), $c['pid'], $c['profile']['slug']);
studioHead($c, 'Dashboard', 'home');
$name = (string) ($c['profile']['display_name'] ?? '');
$t = $c['tasks'];
$todoTot = array_sum($t);
?>
<div class="st-pagehead">
  <div><h1>Ciao, <?= e($name !== '' ? $name : 'benvenuto') ?></h1>
  <p class="st-muted"><?= $todoTot > 0 ? 'Hai ' . $todoTot . ' ' . ($todoTot === 1 ? 'cosa' : 'cose') . ' da gestire.' : 'Tutto in ordine: niente da gestire.' ?></p></div>
  <a class="st-btn" href="/studio/feed.php"><i class="fa-solid fa-list"></i><span>Vai al Feed</span></a>
</div>
<?php if ($c['isAdmin'] && $c['heavy']['api'] !== null): $api = $c['heavy']['api']; ?>
<section class="st-statrow">
  <a class="st-stat" href="/dashboard_api_tokens.php"><span>Chiamate (24 ore)</span><b><?= (int) $api['calls'] ?></b></a>
  <a class="st-stat<?= (int) $api['errors'] > 0 ? ' bad' : '' ?>" href="/dashboard_api_tokens.php"><span>Errori (24 ore)</span><b><?= (int) $api['errors'] ?></b></a>
</section>
<?php endif; ?>
<section class="st-card">
  <div class="st-cardhead"><h3>Ultimi contenuti</h3><a class="st-more" href="/studio/feed.php">Apri il Feed →</a></div>
  <div class="st-feed list st-compact">
    <?php foreach ($mine as $item): ?><?= studioCard($item, $c['profile']['slug']) ?><?php endforeach; ?>
  </div>
  <?php if (!$mine): ?>
    <div class="st-empty"><i class="fa-regular fa-folder-open"></i><p>Non hai ancora pubblicato nulla</p>
    <a class="st-btn" href="/dashboard_post.php"><i class="fa-solid fa-plus"></i><span>Nuovo contenuto</span></a></div>
  <?php endif; ?>
</section>
<?php
studioFoot(studioAside($c));
