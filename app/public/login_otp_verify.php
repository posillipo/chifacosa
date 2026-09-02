<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

$email = $_SESSION['otp_email'] ?? '';
$error = null;

if (!$email) {
    header('Location: /login_otp_request.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $code = trim($_POST['code'] ?? '');

    $stmt = getDB()->prepare('SELECT * FROM users WHERE email = ? AND otp_code = ? AND otp_expires_at > NOW()');
    $stmt->execute([$email, $code]);
    $u = $stmt->fetch();

    if (!$u) {
        $error = 'Codice non valido o scaduto.';
    } else {
        // Codice monouso: lo invalidiamo subito dopo l'utilizzo
        $stmt = getDB()->prepare('UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?');
        $stmt->execute([$u['id']]);
        unset($_SESSION['otp_email']);

        $_SESSION['user_id'] = $u['id'];
        header('Location: ' . ($u['account_type_chosen'] ? '/dashboard.php' : '/choose_account_type.php'));
        exit;
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verifica codice — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="auth-split">
  <div class="auth-split-brand">
    <div class="logo"><?= e(siteName()) ?></div>
    <h1>Inserisci il <span class="highlight">codice</span><br>che hai ricevuto.</h1>
  </div>
  <div class="auth-split-form">
    <div class="auth-split-form-inner">
      <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
      <p style="color:#444;font-size:14px;margin-bottom:20px;">Codice inviato a <strong><?= e($email) ?></strong></p>
      <form method="post">
        <?= csrfField() ?>
        <label>Codice a 6 cifre</label>
        <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" required autofocus>
        <button type="submit" class="btn-dark">Accedi</button>
      </form>
      <p style="margin-top:18px;font-size:14px;"><a href="/login_otp_request.php">Richiedi un nuovo codice</a></p>
    </div>
  </div>
</div>
</body>
</html>
