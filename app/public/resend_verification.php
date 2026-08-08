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
                                   WHERE u.email = ? AND u.email_verified = 0');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if ($u) {
            [$token, $expires] = generateVerificationToken();
            $upd = getDB()->prepare('UPDATE users SET verification_token = ?, verification_expires = ? WHERE id = ?');
            $upd->execute([$token, $expires, $u['id']]);
            notifyEmailVerification($u['email'], $u['display_name'], $token);
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
<title>Richiedi nuova email di verifica — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<?= embedTrackingBodyStart() ?>
<div class="navbar">
  <div class="brand"><a href="/"><?= e(siteName()) ?></a></div>
  <nav><a href="/login.php">Accedi</a></nav>
</div>
<div class="container">
  <h2>Richiedi un nuovo invio</h2>
  <?php if ($submitted): ?>
    <div class="alert success">
      Se l'indirizzo inserito corrisponde a un account in attesa di conferma, riceverai a breve
      una nuova email con il link di verifica.
    </div>
  <?php else: ?>
    <form method="post" class="card">
      <?= csrfField() ?>
      <label>Email di registrazione</label>
      <input type="email" name="email" required>
      <button type="submit" class="btn">Invia nuovo link</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
