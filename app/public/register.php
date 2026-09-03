<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/google_oauth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = null;
$registered = false;
$registeredEmailSent = false;

// Registrazione aperta a chiunque, nessun invito richiesto. Restano supportati due casi
// opzionali: un vecchio link di invito già approvato e non ancora usato (?invite=token, per
// compatibilità con email già inviate prima di questo cambiamento) — in quel caso l'email resta
// quella della richiesta, non modificabile — e un link di invito "porta un amico" (?ref=slug),
// che serve solo a far seguire automaticamente chi invita e chi si iscrive.
$token = trim($_GET['invite'] ?? $_POST['invite'] ?? '');
$invite = null;
if ($token !== '') {
    $stmt = getDB()->prepare("SELECT * FROM access_requests WHERE invite_token = ? AND status = 'approved' AND invite_used = 0");
    $stmt->execute([$token]);
    $invite = $stmt->fetch() ?: null;
}

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
    $displayName = trim($_POST['display_name'] ?? '');
    $email = $invite ? $invite['email'] : trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $slug = slugify($_POST['slug'] ?? $displayName);

    if ($displayName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || $slug === '') {
        $error = 'Compila tutti i campi con un\'email valida.';
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

            $referrerId = null;
            if ($invite) {
                $stmt = $db->prepare('UPDATE access_requests SET invite_used = 1 WHERE id = ?');
                $stmt->execute([$invite['id']]);
                $referrerId = $invite['referrer_user_id'] ? (int) $invite['referrer_user_id'] : null;
            } elseif ($referrerUserId) {
                $referrerId = $referrerUserId;
                // Nessuna "richiesta" da approvare in questo flusso aperto: la riga serve solo a
                // tenere lo storico/le statistiche di chi si è iscritto tramite un link di invito
                // (vedi dashboard_invite.php), riusando la stessa tabella del vecchio sistema.
                $stmt = $db->prepare("INSERT INTO access_requests (name, email, referrer_user_id, status, invite_used, decided_at) VALUES (?, ?, ?, 'approved', 1, NOW())");
                $stmt->execute([$displayName, $email, $referrerId]);
            }
            $db->commit();

            $emailSent = notifyEmailVerification($email, $displayName, $verifyToken);
            notifyAdminsNewUser($email, $displayName, $slug);

            // Chi invita e chi si iscrive tramite il suo link iniziano a seguirsi a vicenda in
            // automatico — quasi certamente si conoscono già, un collegamento reciproco dà a
            // entrambi subito qualcosa nella propria Timeline invece di partire da una rete vuota.
            if ($referrerId) {
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
<title>Registrati — <?= e(siteName()) ?></title>
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
        <?php if ($invite): ?>
          <p style="color:#444;font-size:14px;margin-bottom:20px;">
            Invito confermato per <strong><?= e($invite['email']) ?></strong> — completa i dati
            per creare la tua pagina.
          </p>
        <?php else: ?>
          <p style="color:#444;font-size:14px;margin-bottom:20px;">
            <?= e(siteName()) ?> è per tutti: crea la tua pagina in un minuto, nessun invito
            necessario.
          </p>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <?php $googleReady = getGoogleOAuthClientId() && getGoogleOAuthClientSecret(); ?>
        <?php if (!$invite && $googleReady): ?>
          <!-- Da qui in poi le nuove pagine si creano solo con Google — niente più email/password
               per la registrazione. Resta l'unico modo per chi ha un vecchio invito da completare
               ($invite) e come ripiego se Google non è configurato, così la registrazione non si
               blocca mai del tutto. -->
          <a href="/auth_google_start.php<?= $refSlug ? '?ref=' . urlencode($refSlug) : '' ?>" class="btn-dark" style="display:block;text-align:center;">Registrati con Google</a>
        <?php else: ?>
          <form method="post">
            <?= csrfField() ?>
            <?php if ($token): ?><input type="hidden" name="invite" value="<?= e($token) ?>"><?php endif; ?>
            <?php if ($refSlug): ?><input type="hidden" name="ref" value="<?= e($refSlug) ?>"><?php endif; ?>
            <label>Nome / Nome d'arte</label>
            <input type="text" name="display_name" required value="<?= e($_POST['display_name'] ?? '') ?>">
            <label>Email</label>
            <?php if ($invite): ?>
              <input type="email" name="email" value="<?= e($invite['email']) ?>" readonly>
            <?php else: ?>
              <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
            <?php endif; ?>
            <label>Nome pagina (<?= e(siteName()) ?>/<strong>nomepagina</strong>)</label>
            <input type="text" name="slug" placeholder="es. marco-rossi" value="<?= e($_POST['slug'] ?? '') ?>">
            <label>Password (min. 8 caratteri)</label>
            <input type="password" name="password" required>
            <button type="submit" class="btn-dark">Crea pagina</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
      <p style="margin-top:18px;font-size:14px;">Hai già un account? <a href="/login.php">Accedi</a></p>
    </div>
  </div>
</div>
</body>
</html>
