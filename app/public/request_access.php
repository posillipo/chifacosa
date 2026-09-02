<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

$error = null;
$sent = false;

// Se l'invito arriva da un link "?ref=slug", risolviamo lo slug in un ID utente reale — se non
// corrisponde a nessun account attivo, semplicemente ignoriamo il riferimento (nessun errore).
$refSlug = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
$referrerUserId = null;
if ($refSlug !== '') {
    $stmt = getDB()->prepare('SELECT id FROM users WHERE slug = ? AND is_active = 1');
    $stmt->execute([$refSlug]);
    $refUser = $stmt->fetch();
    if ($refUser) {
        $referrerUserId = (int) $refUser['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $bandName = trim($_POST['band_name'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Compila nome ed email con un indirizzo valido.';
    } else {
        $stmt = getDB()->prepare('INSERT INTO access_requests (name, email, band_name, message, referrer_user_id) VALUES (?,?,?,?,?)');
        $stmt->execute([$name, $email, $bandName ?: null, $message ?: null, $referrerUserId]);
        $sent = true;
        $conversionEventId = generateEventId();
        sendMetaConversionEvent('Lead', $conversionEventId, $email);
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Richiedi l'accesso — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<?= embedTrackingBodyStart() ?>
<?php if ($sent): ?><?= embedClientSideConversionEvent('Lead', $conversionEventId) ?><?php endif; ?>
<div class="auth-split">
  <div class="auth-split-brand">
    <div class="logo"><?= e(siteName()) ?></div>
    <h1>Richiedi il tuo <span class="highlight">posto</span><br>su <?= e(siteName()) ?>.</h1>
  </div>
  <div class="auth-split-form">
    <div class="auth-split-form-inner">
      <?php if ($sent): ?>
        <div class="alert success">
          Richiesta ricevuta! Ti scriveremo appena verrà valutata, con le istruzioni per
          completare la registrazione.
        </div>
        <p style="margin-top:18px;font-size:14px;"><a href="/">← Torna alla home</a></p>
      <?php else: ?>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($referrerUserId): ?>
          <div class="alert success" style="font-size:13px;">✨ Sei stato invitato da un utente <?= e(siteName()) ?>!</div>
        <?php endif; ?>
        <p style="color:#444;font-size:14px;margin-bottom:20px;">
          <?= e(siteName()) ?> è ad accesso su invito: raccontaci chi sei, ti risponderemo appena possibile.
        </p>
        <form method="post">
          <?= csrfField() ?>
          <?php if ($refSlug): ?><input type="hidden" name="ref" value="<?= e($refSlug) ?>"><?php endif; ?>
          <label>Il tuo nome</label>
          <input type="text" name="name" required>
          <label>Email</label>
          <input type="email" name="email" required>
          <label>Nome dell'attività o del progetto (opzionale)</label>
          <input type="text" name="band_name">
          <label>Raccontaci brevemente di te (opzionale)</label>
          <textarea name="message" rows="3"></textarea>
          <button type="submit" class="btn-dark">Invia richiesta</button>
        </form>
      <?php endif; ?>
      <p style="margin-top:18px;font-size:14px;">Hai già un invito? <a href="/login.php">Accedi</a></p>
    </div>
  </div>
</div>
</body>
</html>
