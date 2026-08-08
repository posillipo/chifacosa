<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/mailer.php';

$error = null;
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim($_POST['email'] ?? '');

    $stmt = getDB()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 AND email_verified = 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    // Non riveliamo se l'email esiste o meno: stesso messaggio di successo in entrambi i casi,
    // ma il codice viene generato e inviato solo se l'account esiste davvero.
    if ($u) {
        $code = (string) random_int(100000, 999999);
        $stmt = getDB()->prepare('UPDATE users SET otp_code = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?');
        $stmt->execute([$code, $u['id']]);

        $cfg = getSmtpConfig();
        if ($cfg['host']) {
            $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);
            $body = "Ciao,\n\nIl tuo codice di accesso a " . siteName() . " è: {$code}\n\nScade tra 10 minuti. Se non hai richiesto tu l'accesso, ignora questa email.";
            $mailer->send($cfg['from'], $cfg['fromName'], $email, $u['display_name'] ?? $email, 'Il tuo codice di accesso a ' . siteName(), $body);
        }
    }
    $_SESSION['otp_email'] = $email;
    $sent = true;
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accedi con un codice — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="auth-split">
  <div class="auth-split-brand">
    <div class="logo">my<span>Band</span>.it</div>
    <h1>Accedi con un <span class="highlight">codice</span><br>via email.</h1>
  </div>
  <div class="auth-split-form">
    <div class="auth-split-form-inner">
      <?php if ($sent): ?>
        <div class="alert success">
          Se l'indirizzo è associato a un account, hai ricevuto un'email con il codice.
        </div>
        <form method="post" action="/login_otp_verify.php">
          <?= csrfField() ?>
          <label>Codice ricevuto via email</label>
          <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" required autofocus>
          <button type="submit" class="btn-dark">Continua</button>
        </form>
        <p style="margin-top:14px;font-size:13px;"><a href="/login_otp_request.php">Non hai ricevuto nulla? Riprova</a></p>
      <?php else: ?>
        <p style="color:#444;font-size:14px;margin-bottom:20px;">Inserisci la tua email per ricevere un codice monouso.</p>
        <form method="post">
          <?= csrfField() ?>
          <label>Indirizzo email</label>
          <input type="email" name="email" required autofocus>
          <button type="submit" class="btn-dark">Continua</button>
        </form>
      <?php endif; ?>
      <p style="margin-top:18px;font-size:14px;"><a href="/login.php">← Accedi con la password</a></p>
    </div>
  </div>
</div>
</body>
</html>
