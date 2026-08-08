<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim($_POST['email'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = getDB()->prepare('SELECT u.id, u.email, p.display_name FROM users u
                                   JOIN profiles p ON p.user_id = u.id
                                   WHERE u.email = ? AND u.is_active = 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if ($u) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $upd = getDB()->prepare('UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?');
            $upd->execute([$token, $expires, $u['id']]);
            notifyPasswordReset($u['email'], $u['display_name'], $token);
        }
        // Messaggio identico indipendentemente dal risultato, per non rivelare quali email sono registrate
    }
    $submitted = true;
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recupera password — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="navbar">
  <div class="brand"><a href="/"><?= e(siteName()) ?></a></div>
  <nav><a href="/login.php">Accedi</a></nav>
</div>
<div class="container">
  <h2>Recupera password</h2>
  <?php if ($submitted): ?>
    <div class="alert success">
      Se l'indirizzo inserito corrisponde a un account esistente, riceverai a breve un'email con
      il link per reimpostare la password.
    </div>
  <?php else: ?>
    <form method="post" class="card">
      <?= csrfField() ?>
      <label>Email di registrazione</label>
      <input type="email" name="email" required>
      <button type="submit" class="btn">Invia link di recupero</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
