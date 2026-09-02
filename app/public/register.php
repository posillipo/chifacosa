<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = null;
$registered = false;
$registeredEmailSent = false;

// La registrazione è solo su invito: serve un token valido, generato da un'approvazione in
// Area Admin (vedi admin_access_requests.php), non ancora usato.
$token = trim($_GET['invite'] ?? $_POST['invite'] ?? '');
$stmt = getDB()->prepare("SELECT * FROM access_requests WHERE invite_token = ? AND status = 'approved' AND invite_used = 0");
$stmt->execute([$token]);
$invite = $token !== '' ? $stmt->fetch() : null;

if (!$invite) {
    header('Location: /request_access.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $displayName = trim($_POST['display_name'] ?? '');
    $email = $invite['email']; // sempre quella della richiesta approvata, non modificabile
    $password = $_POST['password'] ?? '';
    $slug = slugify($_POST['slug'] ?? $displayName);

    if ($displayName === '' || $password === '' || $slug === '') {
        $error = 'Compila tutti i campi.';
    } elseif (strlen($password) < 8) {
        $error = 'La password deve avere almeno 8 caratteri.';
    } elseif (in_array($slug, RESERVED_SLUGS, true)) {
        $error = 'Questo nome pagina non è disponibile, scegline un altro.';
    } elseif (slugExists($slug)) {
        $error = 'Questo nome pagina è già in uso.';
    } else {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email già registrata.';
        } else {
            $db->beginTransaction();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            [$verifyToken, $expires] = generateVerificationToken();
            $stmt = $db->prepare('INSERT INTO users (slug, email, password_hash, email_verified, verification_token, verification_expires) VALUES (?, ?, ?, 0, ?, ?)');
            $stmt->execute([$slug, $email, $hash, $verifyToken, $expires]);
            $userId = (int) $db->lastInsertId();
            $stmt = $db->prepare('INSERT INTO profiles (user_id, display_name) VALUES (?, ?)');
            $stmt->execute([$userId, $displayName]);
            $stmt = $db->prepare('UPDATE access_requests SET invite_used = 1 WHERE id = ?');
            $stmt->execute([$invite['id']]);
            $db->commit();

            $emailSent = notifyEmailVerification($email, $displayName, $verifyToken);
            notifyAdminsNewUser($email, $displayName, $slug);

            // Se la registrazione arriva da un invito con un referrer riconosciuto, li facciamo
            // seguire a vicenda automaticamente — chi invita quasi certamente conosce già la
            // persona invitata, un collegamento reciproco dà a entrambi subito qualcosa nella
            // propria Timeline invece di partire da una rete vuota.
            if (!empty($invite['referrer_user_id'])) {
                $referrerId = (int) $invite['referrer_user_id'];
                $stmt = $db->prepare('INSERT IGNORE INTO account_follows (follower_user_id, followed_user_id) VALUES (?, ?), (?, ?)');
                $stmt->execute([$referrerId, $userId, $userId, $referrerId]);

                $stmt = $db->prepare('SELECT u.email, u.slug, p.display_name FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
                $stmt->execute([$referrerId]);
                $referrer = $stmt->fetch();
                if ($referrer) {
                    notifyNewFollower($referrer['email'], $referrer['display_name'], $slug, $displayName);
                    notifyNewFollower($email, $displayName, $referrer['slug'], $referrer['display_name']);
                }
            }

            $registered = true;
            $registeredEmailSent = $emailSent;
            $conversionEventId = generateEventId();
            sendMetaConversionEvent('CompleteRegistration', $conversionEventId, $email);
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Completa la registrazione — <?= e(siteName()) ?></title>
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
    <h1>Benvenuto! <span class="highlight">Crea</span><br>la tua pagina.</h1>
  </div>
  <div class="auth-split-form">
    <div class="auth-split-form-inner">
      <?php if ($registered): ?>
        <?= embedClientSideConversionEvent('CompleteRegistration', $conversionEventId) ?>
        <div class="alert success">
          <strong>Registrazione completata!</strong><br>
          <?php if ($registeredEmailSent): ?>
            Ti abbiamo inviato un'email di conferma: apri il link per attivare l'account
            (valido 24 ore).
          <?php else: ?>
            Account creato, ma non è stato possibile inviare l'email di conferma
            automaticamente — contatta l'amministratore.
          <?php endif; ?>
        </div>
        <p><a href="/login.php">Vai al login</a></p>
      <?php else: ?>
        <p style="color:#444;font-size:14px;margin-bottom:20px;">
          Invito confermato per <strong><?= e($invite['email']) ?></strong> — completa i dati
          per creare la tua pagina.
        </p>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="invite" value="<?= e($token) ?>">
          <label>Nome / Nome d'arte</label>
          <input type="text" name="display_name" required value="<?= e($_POST['display_name'] ?? $invite['band_name'] ?? '') ?>">
          <label>Nome pagina (<?= e(siteName()) ?>/<strong>nomepagina</strong>)</label>
          <input type="text" name="slug" placeholder="es. marco-rossi" value="<?= e($_POST['slug'] ?? '') ?>">
          <label>Password (min. 8 caratteri)</label>
          <input type="password" name="password" required>
          <button type="submit" class="btn-dark">Crea pagina</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
