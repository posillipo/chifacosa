<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/google_oauth.php';

$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';
$expectedState = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);

$redirect = $_SESSION['google_oauth_redirect'] ?? '';
unset($_SESSION['google_oauth_redirect']);

if (!empty($_GET['error']) || $code === '' || $state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    header('Location: /login.php?google_error=1');
    exit;
}

$accessToken = googleOAuthExchangeCode($code);
$userInfo = $accessToken ? googleOAuthGetUserInfo($accessToken) : null;

if (!$userInfo) {
    header('Location: /login.php?google_error=1');
    exit;
}

$email = $userInfo['email'];
$name = $userInfo['name'] ?? $email;

$db = getDB();
$stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
$u = $stmt->fetch();

if ($u) {
    if (!$u['is_active']) {
        header('Location: /login.php?google_error=1');
        exit;
    }
    if (!$u['email_verified']) {
        // Google ha già verificato questa email: non serve più il doppio controllo via link.
        $stmt = $db->prepare('UPDATE users SET email_verified = 1 WHERE id = ?');
        $stmt->execute([$u['id']]);
    }
    $userId = (int) $u['id'];
    $needsAccountType = !$u['account_type_chosen'];
} else {
    // Nessun account con questa email: la registrazione è aperta a chiunque, se ne crea uno al
    // volo — stesso principio di register.php, ma senza password (l'accesso resterà sempre via
    // Google, la password è un valore casuale mai comunicato e mai usabile).
    $baseSlug = slugify($name) ?: ('utente-' . substr(md5($email), 0, 6));
    $slug = $baseSlug;
    $i = 2;
    while (in_array($slug, RESERVED_SLUGS, true) || slugExists($slug)) {
        $slug = $baseSlug . '-' . $i;
        $i++;
    }
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);

    $db->beginTransaction();
    $stmt = $db->prepare('INSERT INTO users (slug, email, password_hash, email_verified) VALUES (?, ?, ?, 1)');
    $stmt->execute([$slug, $email, $hash]);
    $userId = (int) $db->lastInsertId();
    $stmt = $db->prepare('INSERT INTO profiles (user_id, display_name) VALUES (?, ?)');
    $stmt->execute([$userId, $name]);
    $db->commit();

    notifyAdminsNewUser($email, $name, $slug);
    $conversionEventId = generateEventId();
    sendMetaConversionEvent('CompleteRegistration', $conversionEventId, $email);
    $needsAccountType = true;
}

$_SESSION['user_id'] = $userId;

if ($needsAccountType) {
    header('Location: /choose_account_type.php');
} else {
    header('Location: ' . ($redirect ?: '/dashboard.php'));
}
exit;
