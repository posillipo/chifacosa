<?php
// Guida alle pagine della dashboard: elenco e approfondimenti. I testi sono in tabella page_help
// (modificabili da Admin → Guide delle pagine); qui si leggono soltanto.
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$activeTab = 'guida';
$pageTitle = 'Guide';

$key = trim((string) ($_GET['p'] ?? ''));
$entry = $key !== '' ? pageHelpGet($key) : null;
$all = pageHelpAll();
if ($entry) {
    $pageTitle = $entry['title'];
}
include __DIR__ . '/_dash_header.php';
?>
  <div class="page-head"><h1 class="page-head-title"><?= $entry ? e($entry['title']) : 'Guide alle pagine' ?></h1></div>

  <?php if ($entry): ?>
    <p><a href="/guida.php"><i class="fa-solid fa-arrow-left"></i> Tutte le guide</a></p>
    <div class="card">
      <?= pageHelpFormat($entry['body']) ?>
      <?= pageHelpFormat($entry['more']) ?>
      <p style="margin-top:14px;"><a class="btn secondary" href="/<?= e($entry['page_key']) ?>.php"><i class="fa-solid fa-arrow-up-right-from-square"></i> Vai alla pagina</a></p>
    </div>
  <?php else: ?>
    <?php if (!$all): ?>
      <div class="card">Nessuna guida disponibile.</div>
    <?php else: ?>
      <div class="card">
        <p style="color:var(--text-muted);margin-top:0;">Ogni pagina della dashboard ha un <strong>?</strong> accanto al titolo con una spiegazione breve. Qui trovi anche gli approfondimenti.</p>
        <ul style="list-style:none;padding:0;margin:0;">
          <?php foreach ($all as $h): ?>
            <li style="padding:8px 0;border-top:1px solid rgba(128,128,128,.2);">
              <a href="/guida.php?p=<?= e(rawurlencode($h['page_key'])) ?>"><strong><?= e($h['title']) ?></strong></a><br>
              <small style="color:var(--text-muted);"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', strtr($h['body'], pageHelpPlaceholders())), 0, 140, '…')) ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
