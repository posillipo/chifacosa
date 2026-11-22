<?php
// Profilo privato (profiles.is_private = 1): nascosto da ricerche, liste e sitemap, e con la pagina
// pubblica chiusa a chi non è autorizzato. API e server MCP NON passano da qui: vanno col token del
// profilo e continuano a funzionare come sempre.

// La colonna profiles.is_private esiste (migrazione 2026_11_33 eseguita)? Finché manca nessuna lista cambia.
function profilesHavePrivateColumn(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) getDB()->query("SHOW COLUMNS FROM profiles LIKE 'is_private'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

// Condizione SQL da aggiungere alle liste di profili (ricerca, "Scopri"): esclude i privati.
// $profilesAlias è l'alias della tabella profiles nella query.
function privateProfileExcludeSql(string $profilesAlias = 'p'): string {
    return profilesHavePrivateColumn() ? " AND {$profilesAlias}.is_private = 0" : '';
}

// Come sopra, per le query che hanno solo la tabella users (sitemap).
function privateUserExcludeSql(string $usersAlias = 'u'): string {
    return profilesHavePrivateColumn() ? " AND {$usersAlias}.id NOT IN (SELECT user_id FROM profiles WHERE is_private = 1)" : '';
}

// Chi può vedere la pagina pubblica di un profilo privato: lui stesso, chi lo gestisce, gli admin del
// sito e gli account che lo seguono (Segui tra account). Chi segue solo via email non è identificabile.
function privateProfileViewerAllowed(int $ownerId): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    $viewer = (int) $_SESSION['user_id'];
    if ($viewer === $ownerId || isSiteAdmin($viewer) || canManageProfile($viewer, $ownerId)) {
        return true;
    }
    $acting = publicActingUserId();
    if ($acting !== null && ($acting === $ownerId || isFollowingAccount($acting, $ownerId))) {
        return true;
    }
    return isFollowingAccount($viewer, $ownerId);
}

// Blocco centrale: tutte le pagine pubbliche del profilo ricevono ?slug=... dalle regole di .htaccess.
// Se il profilo è privato e chi guarda non è autorizzato risponde come una pagina inesistente (404) e
// chiede ai motori di ricerca di non indicizzare. Le pagine di dashboard, admin, API, accesso, "Segui" e
// i cron non sono pagine pubbliche del profilo e restano fuori.
function enforcePrivateProfileGate(): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    $slug = $_GET['slug'] ?? '';
    if (!is_string($slug) || $slug === '') {
        return;
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('~^(api_|dashboard|admin|login|logout|register|follow|auth_|cron_|webhook|install|forgot|reset|verify|resend|choose_account|accetta|account_|guida|credits|contest_vote|contest_sponsor|sitemap|robots)~i', $script)
        || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/studio/')) {
        return;
    }
    try {
        $st = getDB()->prepare('SELECT u.id, p.is_private FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return; // colonna non ancora migrata: nessun blocco
    }
    if (!$row || empty($row['is_private'])) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    if (privateProfileViewerAllowed((int) $row['id'])) {
        return;
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Pagina non trovata</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;margin-top:80px;"><h1>Pagina non trovata</h1></body></html>';
    exit;
}
