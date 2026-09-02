<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/google_oauth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = null;
$unverified = false;
if (!empty($_GET['google_error'])) {
    $error = 'Accesso con Google non riuscito. Riprova, o accedi con email e password.';
}

// Redirect opzionale verso la pagina di partenza (es. "Vota" quando non loggato) — accettiamo
// solo percorsi interni relativi, mai URL esterni, per evitare un open-redirect.
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
$isValidRedirect = $redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//') && !str_contains($redirect, '://');
if (!$isValidRedirect) {
    $redirect = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = getDB()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        $error = 'Email o password non corretti.';
    } elseif (!$u['is_active']) {
        $error = 'Account disattivato.';
    } elseif (!$u['email_verified']) {
        $unverified = true;
    } else {
        $_SESSION['user_id'] = $u['id'];
        if (!empty($_POST['remember'])) {
            issueRememberToken((int) $u['id']);
        }
        if (!$u['account_type_chosen']) {
            header('Location: /choose_account_type.php');
        } else {
            header('Location: ' . ($redirect ?: '/dashboard.php'));
        }
        exit;
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accedi — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<?= embedTrackingBodyStart() ?>
<div class="auth-split">
  <div class="auth-split-brand">
    <div class="logo"><?= e(siteName()) ?></div>
    <h1>Ciao! <span class="highlight">Accedi</span><br>al tuo account <?= e(siteName()) ?>.</h1>
  </div>
  <div class="auth-split-form">
    <div class="auth-split-form-inner">
      <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
      <?php if ($unverified): ?>
        <div class="alert error">
          Devi prima confermare la tua email. Controlla la posta, oppure
          <a href="/resend_verification.php">richiedi un nuovo invio</a>.
        </div>
      <?php endif; ?>

      <form method="post">
        <?= csrfField() ?>
        <?php if ($redirect): ?><input type="hidden" name="redirect" value="<?= e($redirect) ?>"><?php endif; ?>
        <label>Email</label>
        <input type="email" name="email" required>
        <label>Password</label>
        <input type="password" name="password" required>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;font-weight:normal;">
          <input type="checkbox" name="remember" value="1" style="width:auto;">
          Ricordami su questo dispositivo (resta connesso per 30 giorni)
        </label>
        <button type="submit" class="btn-dark">Accedi</button>
      </form>

      <div class="auth-divider">oppure</div>
      <a href="/login_otp_request.php" class="btn-outline">✨ Accedi con un codice via email</a>
      <?php if (getGoogleOAuthClientId() && getGoogleOAuthClientSecret()): ?>
        <a href="/auth_google_start.php<?= $redirect ? '?redirect=' . urlencode($redirect) : '' ?>" class="btn-outline">Accedi con Google</a>
      <?php endif; ?>

      <p style="margin-top:18px;font-size:14px;"><a href="/forgot_password.php">Password dimenticata?</a></p>
      <p style="font-size:14px;">Non hai un account? <a href="/register.php">Registrati</a></p>
    </div>
  </div>
</div>
</body>
</html>
