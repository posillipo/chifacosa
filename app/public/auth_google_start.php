<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/google_oauth.php';

if (!getGoogleOAuthClientId() || !getGoogleOAuthClientSecret()) {
    header('Location: /login.php');
    exit;
}

// Come in login.php: redirect opzionale verso la pagina di partenza, solo percorsi interni
// relativi per evitare un open-redirect.
$redirect = $_GET['redirect'] ?? '';
$isValidRedirect = $redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//') && !str_contains($redirect, '://');
if ($isValidRedirect) {
    $_SESSION['google_oauth_redirect'] = $redirect;
} else {
    unset($_SESSION['google_oauth_redirect']);
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

header('Location: ' . googleOAuthAuthUrl($state));
exit;
