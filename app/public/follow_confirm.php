<?php
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$token = $_GET['token'] ?? '';
$success = false;
$artistName = '';
$artistSlug = '';

if ($token !== '') {
    $stmt = getDB()->prepare('SELECT f.id, f.user_id, f.email, p.display_name, p.privacy_tracking_settings, u.slug
                              FROM followers f
                              JOIN users u ON u.id = f.user_id
                              JOIN profiles p ON p.user_id = u.id
                              WHERE f.token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if ($row) {
        getDB()->prepare('UPDATE followers SET verified = 1 WHERE id = ?')->execute([$row['id']]);
        $success = true;
        $artistName = $row['display_name'];
        $artistSlug = $row['slug'];
        sendMetaConversionEvent('Follow', generateEventId(), $row['email'] ?? null, $row);
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conferma iscrizione — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="navbar">
  <div class="brand"><a href="/"><?= e(siteName()) ?></a></div>
</div>
<div class="container">
  <h2>Conferma iscrizione</h2>
  <?php if ($success): ?>
    <div class="alert success">
      Fatto! Ora segui <strong><?= e($artistName) ?></strong> su <?= e(siteName()) ?> — riceverai
      un'email quando pubblica un nuovo articolo o annuncia un nuovo concerto.
    </div>
    <p><a class="btn" href="/<?= e($artistSlug) ?>">Vai alla pagina di <?= e($artistName) ?></a></p>
  <?php else: ?>
    <div class="alert error">Link di conferma non valido o già usato.</div>
  <?php endif; ?>
</div>
</body>
</html>
