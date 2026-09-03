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

$refSlug = $_SESSION['google_oauth_ref'] ?? '';
unset($_SESSION['google_oauth_ref']);

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

    // Link "porta un amico" (?ref=slug): stessa logica di register.php — nessuna "richiesta" da
    // approvare, la riga serve solo allo storico/alle statistiche di dashboard_invite.php.
    $referrerId = null;
    if ($refSlug !== '') {
        $stmt = $db->prepare('SELECT id FROM users WHERE slug = ? AND is_active = 1');
        $stmt->execute([$refSlug]);
        $refUser = $stmt->fetch();
        if ($refUser) {
            $referrerId = (int) $refUser['id'];
            $stmt = $db->prepare("INSERT INTO access_requests (name, email, referrer_user_id, status, invite_used, decided_at) VALUES (?, ?, ?, 'approved', 1, NOW())");
            $stmt->execute([$name, $email, $referrerId]);
        }
    }
    $db->commit();

    notifyAdminsNewUser($email, $name, $slug);
    $conversionEventId = generateEventId();
    sendMetaConversionEvent('CompleteRegistration', $conversionEventId, $email);

    if ($referrerId) {
        $stmt = $db->prepare('INSERT IGNORE INTO account_follows (follower_user_id, followed_user_id) VALUES (?, ?), (?, ?)');
        $stmt->execute([$referrerId, $userId, $userId, $referrerId]);

        $stmt = $db->prepare('SELECT u.email, u.slug, p.display_name FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
        $stmt->execute([$referrerId]);
        $referrer = $stmt->fetch();
        if ($referrer) {
            notifyNewFollower($referrer['email'], $referrer['display_name'], $slug, $name);
            notifyNewFollower($email, $name, $referrer['slug'], $referrer['display_name']);
        }
    }

    $needsAccountType = true;
}

$_SESSION['user_id'] = $userId;

if ($needsAccountType) {
    header('Location: /onboarding_setup.php');
} else {
    header('Location: ' . ($redirect ?: '/dashboard.php'));
}
exit;
