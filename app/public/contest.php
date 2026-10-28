<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/contest.php';

// Edizioni del contest di un profilo: /nomeartista/contest. Ogni edizione ha la sua pagina.
$artist = contestLoadArtist((string) ($_GET['slug'] ?? ''));
if (!$artist || !contestModuleEnabled((int) $artist['id'])) {
    http_response_code(404);
    exit('Pagina non trovata.');
}
$slug = $artist['slug'];
$viewer = currentUser();
$contests = contestListForProfile((int) $artist['id'], !contestCanSeeDrafts($viewer, (int) $artist['id']));

contestPageOpen($artist, 'Contest');
?>
  <header class="hero">
    <div class="logo">🏆</div>
    <h1>Contest di <?= e($artist['display_name']) ?></h1>
    <p>Gare a voti tra i brani delle band emergenti. Scegli un'edizione, ascolta, candida il tuo brano o vota.</p>
  </header>

  <?php foreach ($contests as $c): $ph = contestPhaseInfo($c); $range = contestDateRange($c['voting_start_at'] ?: $c['candidatures_open_at'], $c['voting_end_at'] ?: $c['candidatures_close_at']); ?>
    <a class="btn-link <?= in_array($c['status'], ['open', 'voting'], true) ? 'primary' : '' ?>" href="<?= e(contestUrl($c)) ?>">
      <span class="logo" style="width:46px;height:46px;margin:0;font-size:22px;border-width:2px;box-shadow:none;"><?= contestLogoHtml($c) ?></span>
      <span><?= e($c['name']) ?>
        <small><?= e($ph['label']) ?><?= $range ? ' · ' . e($range) : '' ?> · <?= (int) $c['entries_count'] ?> brani</small></span>
      <span class="arrow">›</span>
    </a>
  <?php endforeach; ?>
  <?php if (!$contests): ?>
    <div class="msg info">Nessun contest in programma al momento.</div>
  <?php endif; ?>

  <a class="back" href="/<?= e($slug) ?>">← Torna alla pagina di <?= e($artist['display_name']) ?></a>
<?php contestPageClose($artist); ?>
