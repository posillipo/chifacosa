<?php
// Ingresso segreto alla dashboard classica: /classic-<TOKEN>. Solo per il proprietario (vedi fn_classic_gate.php).
session_start();
require_once __DIR__ . '/../src/functions.php';
$uidA = (int) ($_SESSION['user_id'] ?? 0);
if (!classicDashConfig()) {
    // Senza variabili d'ambiente: voce di menu riservata agli amministratori.
    if ($uidA > 0 && isSiteAdmin($uidA)) {
        $_SESSION['classic_ok'] = true;
        header('Location: /dashboard_timeline.php');
        exit;
    }
    http_response_code(404);
    exit('Not found');
}
$cfg = classicDashConfig();
$k = (string) ($_GET['k'] ?? '');
if (!$cfg || !hash_equals($cfg['token'], $k)) {
    http_response_code(404);
    exit('Not found');
}
if (!classicDashOwnerLoggedIn($cfg)) {
    header('Location: /login.php');
    exit;
}
setcookie('cdash', classicDashCookieValue($cfg), ['expires' => time() + 86400 * 30, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
header('Location: /dashboard_timeline.php');
exit;
