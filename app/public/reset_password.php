<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = null;
$success = false;

$stmt = getDB()->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_token_expires >= NOW()');
$stmt->execute([$token]);
$validUser = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (!$validUser) {
        $error = 'Il link non è valido o è scaduto. Richiedine uno nuovo.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($password) < 8) {
            $error = 'La password deve avere almeno 8 caratteri.';
        } elseif ($password !== $confirm) {
            $error = 'Le due password non coincidono.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $upd = getDB()->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?');
            $upd->execute([$hash, $validUser['id']]);
            $success = true;
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reimposta password — <?= e(siteName()) ?></title>
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
  <h2>Reimposta password</h2>

  <?php if ($success): ?>
    <div class="alert success">Password aggiornata! Ora puoi accedere con la nuova password.</div>
    <p><a class="btn" href="/login.php">Vai al login</a></p>
  <?php elseif (!$validUser): ?>
    <div class="alert error">Il link non è valido o è scaduto.</div>
    <p><a href="/forgot_password.php">Richiedi un nuovo link</a></p>
  <?php else: ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="card">
      <?= csrfField() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>Nuova password (min. 8 caratteri)</label>
      <input type="password" name="password" required>
      <label>Conferma nuova password</label>
      <input type="password" name="password_confirm" required>
      <button type="submit" class="btn">Salva nuova password</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
