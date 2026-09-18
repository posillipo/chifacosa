<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

$slug = $_POST['slug'] ?? $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name, p.menu_preconto_enabled FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist || !$artist['menu_preconto_enabled']) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /' . $artist['slug'] . '/menu');
    exit;
}

checkCsrf();

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$postalCode = trim($_POST['postal_code'] ?? '');
$followTermsContent = trim(getSiteSetting('follow_terms_content') ?: '');
$acceptedTerms = !empty($_POST['accept_terms']);

$message = null;
$isError = false;

if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || $postalCode === '') {
    $message = 'Compila tutti i campi con dati validi.';
    $isError = true;
} elseif ($followTermsContent !== '' && !$acceptedTerms) {
    $message = 'Devi accettare i Termini di Utilizzo per procedere.';
    $isError = true;
} else {
    $stmt = getDB()->prepare('SELECT id, verified, token FROM followers WHERE user_id = ? AND email = ?');
    $stmt->execute([$artist['id'], $email]);
    $existing = $stmt->fetch();

    $token = $existing['token'] ?? bin2hex(random_bytes(32));

    if ($existing) {
        $stmt = getDB()->prepare('UPDATE followers SET first_name=?, last_name=?, phone=?, postal_code=? WHERE id=?');
        $stmt->execute([$firstName, $lastName, $phone, $postalCode, $existing['id']]);
    } else {
        $stmt = getDB()->prepare('INSERT INTO followers (user_id, email, token, first_name, last_name, phone, postal_code, accepted_terms_at) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$artist['id'], $email, $token, $firstName, $lastName, $phone, $postalCode, $acceptedTerms ? date('Y-m-d H:i:s') : null]);
    }

    if ($existing && $existing['verified']) {
        setcookie('preconto_ok_' . $artist['id'], $token, [
            'expires' => strtotime('+180 days'),
            'path' => '/',
            'secure' => requestScheme() === 'https',
            'samesite' => 'Lax',
        ]);
        header('Location: /' . $artist['slug'] . '/menu?preconto_ok=1');
        exit;
    }

    $confirmUrl = siteUrl('/menu_preconto_confirm.php?token=' . $token);
    notifyPrecontoConfirmation($email, $artist['display_name'], $token, $confirmUrl);
    $message = 'Controlla la tua email: ti abbiamo inviato un link per confermare e attivare il preconto.';
}

header('Location: /' . $artist['slug'] . '/menu?preconto_msg=' . urlencode($message ?? '') . '&preconto_err=' . ($isError ? '1' : '0'));
exit;
