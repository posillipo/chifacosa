<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$token = $_GET['token'] ?? '';
$success = false;
$errorMsg = null;

if ($token === '') {
    $errorMsg = 'Link di verifica non valido.';
} else {
    $stmt = getDB()->prepare('SELECT id FROM users WHERE verification_token = ? AND verification_expires >= NOW() AND email_verified = 0');
    $stmt->execute([$token]);
    $u = $stmt->fetch();

    if (!$u) {
        $errorMsg = 'Il link di verifica non è valido oppure è scaduto. Puoi richiederne uno nuovo.';
    } else {
        $stmt = getDB()->prepare('UPDATE users SET email_verified = 1, verification_token = NULL, verification_expires = NULL WHERE id = ?');
        $stmt->execute([$u['id']]);
        $success = true;
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verifica account — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<?= embedTrackingBodyStart() ?>
<div class="navbar">
  <div class="brand"><a href="/"><?= e(siteName()) ?></a></div>
</div>
<div class="container">
  <h2>Verifica account</h2>
  <?php if ($success): ?>
    <div class="alert success">Account confermato! Ora puoi accedere.</div>
    <p><a class="btn" href="/login.php">Vai al login</a></p>
  <?php else: ?>
    <div class="alert error"><?= e($errorMsg) ?></div>
    <p><a href="/resend_verification.php">Richiedi un nuovo invio</a></p>
  <?php endif; ?>
</div>
</body>
</html>
