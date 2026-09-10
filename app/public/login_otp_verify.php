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

    // Il confronto col codice non è più nella query: serve prima sapere se questo account ha
    // già esaurito i tentativi (vedi sotto), altrimenti un codice a 6 cifre sarebbe attaccabile
    // a forza bruta provandoli tutti via POST, senza alcun limite.
    $stmt = getDB()->prepare('SELECT * FROM users WHERE email = ? AND otp_code IS NOT NULL AND otp_expires_at > NOW()');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u) {
        $error = 'Codice non valido o scaduto.';
    } elseif ((int) $u['otp_attempts'] >= 5) {
        // Troppi tentativi falliti: il codice va invalidato subito, non basta aspettare che
        // scada — altrimenti resterebbe comunque attaccabile a forza bruta per tutti i minuti
        // di validità residua.
        getDB()->prepare('UPDATE users SET otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?')->execute([$u['id']]);
        $error = 'Troppi tentativi con questo codice. Richiedine uno nuovo.';
    } elseif (!hash_equals((string) $u['otp_code'], $code)) {
        getDB()->prepare('UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([$u['id']]);
        $error = 'Codice non valido o scaduto.';
    } else {
        // Codice monouso: lo invalidiamo subito dopo l'utilizzo
        $stmt = getDB()->prepare('UPDATE users SET otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?');
        $stmt->execute([$u['id']]);
        unset($_SESSION['otp_email']);

        // Rigenera l'ID di sessione PRIMA di autenticare: impedisce un attacco di "session
        // fixation" (un ID di sessione impostato dall'esterno prima del login, che altrimenti
        // resterebbe valido anche dopo — qui come su login.php/auth_google_callback.php).
        session_regenerate_id(true);
        $_SESSION['user_id'] = $u['id'];
        header('Location: ' . ($u['account_type_chosen'] ? '/dashboard.php' : '/onboarding_setup.php'));
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
