<?php
// Dashboard classica riservata al proprietario del sito.
// - Le pagine /dashboard*.php (fuori da /studio/) rimandano alla versione Studio equivalente, per tutti.
// - Solo il proprietario, entrando una volta dal percorso segreto /classic-<TOKEN>, riceve un cookie che
//   riattiva la dashboard classica. Il token sta SOLO nella variabile d'ambiente CLASSIC_DASH_TOKEN, l'account
//   proprietario in CLASSIC_DASH_OWNER_EMAIL. Se una delle due manca la classica resta chiusa a tutti.

function classicDashConfig(): ?array {
    $t = (string) getenv('CLASSIC_DASH_TOKEN');
    $m = strtolower(trim((string) getenv('CLASSIC_DASH_OWNER_EMAIL')));
    return ($t !== '' && strlen($t) >= 16 && $m !== '') ? ['token' => $t, 'email' => $m] : null;
}

function classicDashCookieValue(array $cfg): string {
    return hash_hmac('sha256', 'classic-dash|' . $cfg['email'], $cfg['token']);
}

function classicDashOwnerLoggedIn(array $cfg): bool {
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    try {
        $st = getDB()->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$uid]);
        return strtolower((string) $st->fetchColumn()) === $cfg['email'];
    } catch (Throwable $e) {
        return false;
    }
}

function classicDashAllowed(): bool {
    $cfg = classicDashConfig();
    if (!$cfg) {
        return false;
    }
    $c = (string) ($_COOKIE['cdash'] ?? '');
    return $c !== '' && hash_equals(classicDashCookieValue($cfg), $c) && classicDashOwnerLoggedIn($cfg);
}

// Chiamata a fine functions.php: sulle pagine classiche non autorizzate rimanda a Studio.
function enforceClassicDashGate(): void {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (PHP_SAPI === 'cli' || strpos($script, '/studio/') !== false || strpos($script, '/') !== 0) {
        return;
    }
    $base = basename($script);
    if (!preg_match('~^dashboard(_[a-z0-9_]+)?\.php$~', $base)) {
        return;
    }
    if (classicDashAllowed()) {
        return;
    }
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: /studio/' . $base . ($qs !== '' ? '?' . $qs : ''), true, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 302 : 307);
    exit;
}
