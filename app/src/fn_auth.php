<?php
// Sessione, login "ricordami", profili gestiti e controlli di accesso.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Profilo con cui si "agisce" sulle pagine pubbliche (Segui): quello scelto con lo switch in
// dashboard se l'utente loggato può gestirlo, altrimenti l'utente loggato stesso. Senza questo,
// "Segui" usava sempre l'account di login anche dopo lo switch su un altro profilo.
function publicActingUserId(): ?int {
    if (empty($_SESSION['user_id'])) return null;
    $loggedIn = (int) $_SESSION['user_id'];
    $acting = (int) ($_SESSION['acting_as_user_id'] ?? 0);
    if ($acting && $acting !== $loggedIn && canManageProfile($loggedIn, $acting)) {
        return $acting;
    }
    return $loggedIn;
}


function currentUser(): ?array {
    attemptRememberLogin();
    if (empty($_SESSION['user_id'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = getDB()->prepare('SELECT u.*, p.display_name, p.bio, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_artist_name, p.spotify_show_id, p.spotify_show_name, p.youtube_channel_id, p.youtube_channel_name, p.telegram_chat_id, p.telegram_chat_title, p.telegram_auto_publish, p.telegram_user_id, p.telegram_link_code, p.telegram_link_expires, p.genere, p.citta, p.provincia, p.telefono, p.custom_feed_guid, p.custom_feed_guid_since, p.privacy_tracking_settings, p.cinema_films_json_url, p.cinema_films_synced_at, p.cinema_ticket_price, p.menu_preconto_enabled, p.metricool_blog_id, p.metricool_networks, p.metricool_auto_social
                              FROM users u LEFT JOIN profiles p ON p.user_id = u.id
                              WHERE u.id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $cache = $stmt->fetch() ?: null;
    return $cache;
}


// ===== Login persistente "ricordami" (cookie selector/validator) =====
// Il cookie contiene "selector:validator" in chiaro, ma nel database salviamo solo l'hash del
// validator (mai il valore in chiaro) — così anche un accesso in lettura al database non
// permette di impersonare l'utente senza conoscere il validator originale dal cookie.

function issueRememberToken(int $userId): void {
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $stmt = getDB()->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,?)');
    $stmt->execute([$userId, $selector, $hash, $expiresAt]);

    setcookie('remember_me', $selector . ':' . $validator, [
        'expires' => strtotime('+30 days'),
        'path' => '/',
        // requestScheme(), non il semplice $_SERVER['HTTPS']: dietro il reverse proxy
        // (Nginx Proxy Manager) quest'ultimo non risulta mai valorizzato, quindi il flag
        // Secure non veniva mai impostato in produzione nonostante il sito sia sempre servito
        // in HTTPS — vedi il commento su requestScheme() più sotto in questo file.
        'secure' => requestScheme() === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}


// Revoca TUTTI i cookie "ricordami" di un utente, su qualunque dispositivo/browser — da
// chiamare quando si cambia la password (reset_password.php): altrimenti un cookie "ricordami"
// eventualmente già rubato prima del reset continuerebbe a funzionare per i suoi 30 giorni di
// validità residua, nonostante la password sia stata cambiata proprio per estrometterlo.
function revokeAllRememberTokensForUser(int $userId): void {
    getDB()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
}


function clearRememberToken(): void {
    if (!empty($_COOKIE['remember_me'])) {
        $parts = explode(':', $_COOKIE['remember_me'], 2);
        if (isset($parts[0]) && $parts[0] !== '') {
            getDB()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$parts[0]]);
        }
    }
    setcookie('remember_me', '', ['expires' => time() - 3600, 'path' => '/']);
}


// Se non c'è una sessione attiva ma esiste un cookie "ricordami" valido, effettua il login
// automatico e ruota il token (il vecchio viene invalidato, se ne emette uno nuovo) — pratica
// standard per limitare i danni in caso di furto del cookie.
function attemptRememberLogin(): void {
    if (!empty($_SESSION['user_id']) || empty($_COOKIE['remember_me'])) {
        return;
    }
    $parts = explode(':', $_COOKIE['remember_me'], 2);
    if (count($parts) !== 2) {
        return;
    }
    [$selector, $validator] = $parts;

    $stmt = getDB()->prepare('SELECT * FROM remember_tokens WHERE selector = ? AND expires_at >= NOW()');
    $stmt->execute([$selector]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        return;
    }

    $stmt = getDB()->prepare('SELECT is_active FROM users WHERE id = ?');
    $stmt->execute([$row['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !$u['is_active']) {
        return;
    }

    $_SESSION['user_id'] = (int) $row['user_id'];
    // Il vecchio token non si cancella subito ma scade fra 2 minuti: il browser fa più richieste
    // in parallelo (pagina, script, immagini) con lo stesso cookie, e se la prima lo ruotasse
    // all'istante le altre risulterebbero "non loggato" e la loro sessione vuota potrebbe
    // sovrascrivere quella buona.
    getDB()->prepare('UPDATE remember_tokens SET expires_at = LEAST(expires_at, DATE_ADD(NOW(), INTERVAL 2 MINUTE)) WHERE id = ?')->execute([$row['id']]);
    issueRememberToken((int) $row['user_id']);
}


function requireLogin(): array {
    $u = currentUser();
    if (!$u) {
        header('Location: /login.php');
        exit;
    }
    termsGate($u);
    return $u;
}


// ===== Sistema di co-gestione profili =====

function getManagedProfiles(int $viewerId): array {
    $stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name, p.avatar_path
        FROM profile_admins pa JOIN users u ON u.id = pa.owner_user_id JOIN profiles p ON p.user_id = u.id
        WHERE pa.admin_user_id = ? ORDER BY p.display_name ASC');
    $stmt->execute([$viewerId]);
    return $stmt->fetchAll();
}


function isSiteAdmin(int $userId): bool {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $stmt = getDB()->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $cache[$userId] = ($row && !empty($row['is_admin']));
}


function canManageProfile(int $viewerId, int $ownerId): bool {
    if ($viewerId === $ownerId) {
        return true;
    }
    if (isSiteAdmin($viewerId)) {
        return true;
    }
    $stmt = getDB()->prepare('SELECT 1 FROM profile_admins WHERE owner_user_id = ? AND admin_user_id = ?');
    $stmt->execute([$ownerId, $viewerId]);
    return (bool) $stmt->fetch();
}


// Un "owner" (a differenza di un semplice co-admin) ha accesso pieno a tutte le pagine di
// gestione contenuti di un profilo, non solo Timeline e Brani — è il caso di un profilo che
// l'utente ha creato da sé (vedi dashboard_profiles.php), non di un profilo altrui condiviso
// con lui. Titolare del profilo (viewerId === ownerId) conta sempre come full owner.
function isFullOwnerOf(int $viewerId, int $ownerId): bool {
    if ($viewerId === $ownerId) {
        return true;
    }
    if (isSiteAdmin($viewerId)) {
        return true;
    }
    $stmt = getDB()->prepare("SELECT 1 FROM profile_admins WHERE owner_user_id = ? AND admin_user_id = ? AND role = 'owner'");
    $stmt->execute([$ownerId, $viewerId]);
    return (bool) $stmt->fetch();
}


// Blocca le pagine di gestione contenuti (Link, Eventi, Blog, Menù, Profilo, Feed, Tema,
// integrazioni, ecc.) quando si sta agendo su un profilo per cui si è solo co-admin "normale"
// (role='coadmin') — quel ruolo resta volutamente limitato a Timeline e Brani, come sempre.
function requireFullOwnerAccess(array $loggedInUser, array $actingProfile): void {
    if (!isFullOwnerOf((int) $loggedInUser['id'], (int) $actingProfile['id'])) {
        http_response_code(403);
        exit('Come co-admin puoi gestire solo Timeline e Brani che amo per questo profilo.');
    }
}


// Crea un nuovo profilo posseduto dall'utente corrente: una riga users/profiles a tutti gli
// effetti (email sintetica univoca, password casuale mai comunicata — non pensata per un login
// diretto, solo per essere gestita via switch dal profilo che l'ha creata) più la riga
// profile_admins con role='owner' che dà accesso pieno. Restituisce l'ID del nuovo profilo.
function createOwnedProfile(int $creatorUserId, string $displayName, string $slug): int {
    $db = getDB();
    $syntheticEmail = 'profilo+' . $slug . '-' . bin2hex(random_bytes(6)) . '@profili.chifacosa.local';
    $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO users (slug, email, password_hash, is_active, email_verified, account_type, account_type_chosen) VALUES (?, ?, ?, 1, 1, 'band', 1)");
    $stmt->execute([$slug, $syntheticEmail, $randomPassword]);
    $newUserId = (int) $db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO profiles (user_id, display_name) VALUES (?, ?)');
    $stmt->execute([$newUserId, $displayName]);

    $stmt = $db->prepare("INSERT INTO profile_admins (owner_user_id, admin_user_id, role) VALUES (?, ?, 'owner')");
    $stmt->execute([$newUserId, $creatorUserId]);
    $db->commit();

    return $newUserId;
}


// Legge l'eventuale parametro ?acting_as= dalla URL e, se l'utente loggato è autorizzato ad
// agire su quel profilo (è il suo, o è stato promosso admin), aggiorna la sessione. Va
// richiamata da OGNI pagina della dashboard (lo fa _dash_header.php stesso, incluso da tutte),
// non solo dalle pagine che poi usano concretamente il profilo attivo — altrimenti lo switch
// funzionerebbe solo mentre ci si trova già su una di quelle pagine specifiche.
function syncActingProfileFromRequest(int $loggedInUserId): void {
    if (!isset($_GET['acting_as'])) {
        return;
    }
    $requestedId = (int) $_GET['acting_as'];
    if ($requestedId === $loggedInUserId) {
        // Tornare al proprio profilo: rimuoviamo del tutto il valore dalla sessione invece di
        // impostarlo al proprio ID — "nessuna voce in sessione" deve significare sempre e solo
        // "sto agendo su me stesso", senza ambiguità nel resto della dashboard.
        unset($_SESSION['acting_as_user_id']);
    } elseif (canManageProfile($loggedInUserId, $requestedId)) {
        $_SESSION['acting_as_user_id'] = $requestedId;
    }
}


function getActingProfile(array $loggedInUser): array {
    syncActingProfileFromRequest((int) $loggedInUser['id']);
    $actingId = $_SESSION['acting_as_user_id'] ?? (int) $loggedInUser['id'];
    if ((int) $actingId === (int) $loggedInUser['id']) {
        return $loggedInUser;
    }
    if (!canManageProfile((int) $loggedInUser['id'], (int) $actingId)) {
        unset($_SESSION['acting_as_user_id']);
        return $loggedInUser;
    }
    $stmt = getDB()->prepare('SELECT u.*, p.display_name, p.bio, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_artist_name, p.spotify_show_id, p.spotify_show_name, p.youtube_channel_id, p.youtube_channel_name, p.telegram_chat_id, p.telegram_chat_title, p.telegram_auto_publish, p.telegram_user_id, p.telegram_link_code, p.telegram_link_expires, p.genere, p.citta, p.provincia, p.telefono, p.custom_feed_guid, p.custom_feed_guid_since, p.privacy_tracking_settings, p.cinema_films_json_url, p.cinema_films_synced_at, p.cinema_ticket_price, p.menu_preconto_enabled, p.metricool_blog_id, p.metricool_networks, p.metricool_auto_social
                              FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
    $stmt->execute([(int) $actingId]);
    $profile = $stmt->fetch();
    return $profile ?: $loggedInUser;
}


function logAdminAction(int $ownerId, int $actorId, string $action, ?string $details = null): void {
    if ($ownerId === $actorId) {
        return;
    }
    $stmt = getDB()->prepare('INSERT INTO admin_action_logs (owner_user_id, actor_user_id, action, details) VALUES (?,?,?,?)');
    $stmt->execute([$ownerId, $actorId, $action, $details]);
}


function requireIsOwner(array $loggedInUser, array $actingProfile): void {
    if ((int) $loggedInUser['id'] !== (int) $actingProfile['id']) {
        http_response_code(403);
        exit('Questa azione è riservata al titolare del profilo, non ai co-admin.');
    }
}


// Blocca l'accesso a funzionalità riservate a Band/Artista ed Etichetta (Spotify artista,
// Podcast, YouTube, Eventi) — i Fan vengono rimandati alla dashboard con un messaggio.
function requireBandOrLabel(array $user): void {
    if (!in_array($user['account_type'] ?? 'band', ['band', 'label'], true)) {
        header('Location: /dashboard.php?error=solo_band_etichetta');
        exit;
    }
}


function requireAdmin(): array {
    $u = requireLogin();
    if (empty($u['is_admin'])) {
        http_response_code(403);
        die('Accesso riservato all\'amministratore.');
    }
    return $u;
}
