<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

$content = termsContent();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Condizioni d'uso — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="container" style="max-width:760px;">
  <h1>Condizioni d'uso</h1>
  <?php if ($content !== ''): ?>
    <div class="card"><?= nl2br(e($content)) ?></div>
  <?php else: ?>
    <div class="card">Nessuna condizione d'uso configurata al momento.</div>
  <?php endif; ?>
  <p><a href="/">← Torna alla home</a></p>
</div>
</body>
</html>
