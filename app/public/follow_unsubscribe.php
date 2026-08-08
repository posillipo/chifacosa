<?php
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$token = $_GET['token'] ?? '';
$done = false;
$artistName = '';

if ($token !== '') {
    $stmt = getDB()->prepare('SELECT f.id, p.display_name FROM followers f
                              JOIN users u ON u.id = f.user_id
                              JOIN profiles p ON p.user_id = u.id
                              WHERE f.token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
        getDB()->prepare('DELETE FROM followers WHERE id = ?')->execute([$row['id']]);
        $done = true;
        $artistName = $row['display_name'];
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Disiscrizione — <?= e(siteName()) ?></title>
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
  <h2>Disiscrizione</h2>
  <?php if ($done): ?>
    <div class="alert success">
      Non riceverai più notifiche da <strong><?= e($artistName) ?></strong>.
    </div>
  <?php else: ?>
    <div class="alert error">Link non valido: potresti già esserti disiscritto in precedenza.</div>
  <?php endif; ?>
</div>
</body>
</html>
