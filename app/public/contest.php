<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/contest.php';

// Elenco dei contest pubblici. Ogni contest ha la sua pagina in /contest/<slug>.
$contests = contestPublicList();
contestHead('Contest');
?>
  <header class="hero">
    <div class="logo">🏆</div>
    <h1>Contest</h1>
    <p>Gare a voti tra i brani delle band emergenti. Scegli un contest, ascolta, candida il tuo brano o vota.</p>
  </header>

  <?php foreach ($contests as $c): $ph = contestPhaseInfo($c); $range = contestDateRange($c['voting_start_at'] ?: $c['candidatures_open_at'], $c['voting_end_at'] ?: $c['candidatures_close_at']); ?>
    <a class="btn-link <?= $c['status'] !== 'closed' ? 'primary' : '' ?>" href="<?= e(contestUrl($c)) ?>">
      <span class="logo" style="width:46px;height:46px;margin:0;font-size:22px;border-width:2px;box-shadow:none;"><?= contestLogoHtml($c) ?></span>
      <span><?= e($c['name']) ?>
        <small><?= e($ph['label']) ?><?= $range ? ' · ' . e($range) : '' ?> · <?= (int) $c['entries_count'] ?> brani</small></span>
      <span class="arrow">›</span>
    </a>
  <?php endforeach; ?>
  <?php if (!$contests): ?>
    <div class="msg info">Nessun contest in programma al momento.</div>
  <?php endif; ?>

  <a class="back" href="/" style="margin-top:18px;">← Torna alla home</a>
<?php contestFoot(); ?>
