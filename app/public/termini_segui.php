<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

$content = trim(getSiteSetting('follow_terms_content') ?: '');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Termini di Utilizzo — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="container" style="max-width:680px;">
  <h1>Termini di Utilizzo</h1>
  <?php if ($content !== ''): ?>
    <div class="card"><?= nl2br(e($content)) ?></div>
  <?php else: ?>
    <div class="card">Nessun termine di utilizzo configurato al momento.</div>
  <?php endif; ?>
  <p><a href="/">← Torna alla home</a></p>
</div>
</body>
</html>
