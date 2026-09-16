<?php
require_once __DIR__ . '/db.php';

/**
 * Controlla se il sito è stato configurato.
 * Se nessun utente esiste, reindirizza a install.php
 */
function checkInstallation(): void {
    // Whitelist di file che non richiedono installazione
    $basename = basename($_SERVER['PHP_SELF']);
    $exempt_pages = ['install.php', 'login.php', 'register.php', 'verify.php', 'password_reset.php'];
    
    if (in_array($basename, $exempt_pages)) {
        return; // Non fare controlli su queste pagine
    }
    
    // Tre situazioni diverse, da non confondere tra loro:
    //   1. Nessuna configurazione DB trovata (né variabili d'ambiente né file) — installazione
    //      nuova, mai partita: al wizard, che ora sa chiedere anche le credenziali del database.
    //   2. Configurazione presente ma il database è irraggiungibile (credenziali sbagliate, host
    //      non risolvibile, container non ancora pronto) — problema diverso da "schema non
    //      ancora importato": confonderli manderebbe al wizard di reinstallazione anche quando i
    //      dati esistevano già ma erano temporaneamente irraggiungibili, col rischio, completando
    //      di nuovo il wizard, di sembrare "ripartiti da zero" mentre i dati erano solo nascosti.
    //   3. Connessione riuscita: si prosegue con i controlli sotto.
    try {
        $pdo = getDB();
    } catch (DbNotConfiguredException $e) {
        header('Location: /install.php');
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        die('<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Database non raggiungibile</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:60px 20px;background:#f5f5f5;">'
            . '<h1>⚠️ Database non raggiungibile</h1>'
            . '<p style="color:#666;max-width:480px;margin:0 auto;">Controlla le variabili d\'ambiente del '
            . 'container (<code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code>) — '
            . 'se hai appena ricreato lo stack, assicurati che corrispondano esattamente a quelle usate in '
            . 'precedenza. I tuoi dati non sono stati toccati da questo errore, sono solo temporaneamente '
            . 'irraggiungibili.</p></body></html>');
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $user_count = $stmt->fetchColumn();

        if ($user_count === 0) {
            // Connessione riuscita ma nessun utente — schema vuoto, wizard genuinamente necessario
            header('Location: /install.php');
            exit;
        }
    } catch (Exception $e) {
        // Connessione riuscita ma la tabella users non esiste — schema non ancora importato
        header('Location: /install.php');
        exit;
    }
}

function slugify(string $text): string {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Aggiunge un parametro di versione basato sulla data di modifica del file (cache-busting),
// così quando aggiorniamo il CSS il browser scarica sempre la versione corretta invece di
// usare una copia vecchia in cache.
// Nota: il percorso è quello reale della document root DENTRO il container Docker
// (/var/www/html, impostato dal Dockerfile) — non il percorso relativo del repository, che è
// diverso (src/ e public/ vengono copiati in due cartelle separate, non una dentro l'altra).
function assetUrl(string $path): string {
    $file = '/var/www/html' . $path;
    $v = @filemtime($file);
    return $path . ($v ? ('?v=' . $v) : '');
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function checkCsrf(): void {
    if (!isset($_POST['csrf']) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        die('Richiesta non valida (CSRF).');
    }
}

// Valida un percorso di redirect (login.php/auth_google_start.php, "torna dove eri" dopo il
// login) come interno al sito, per evitare un open-redirect verso un dominio esterno — usato per
// phishing ("questo link è di chifacosa.it" quando in realtà porta altrove). Deve iniziare con un
// solo "/", non contenere "://" né alcun backslash: un secondo "/" o un "\" subito dopo il primo
// "/" sono entrambi modi per ottenere un URL protocol-relative, dato che i browser normalizzano
// "\" a "/" prima di interpretare l'URL — un percorso interno di questo sito non ha mai bisogno
// di un backslash, quindi si rifiuta a prescindere da dove compare, non solo all'inizio.
function isSafeInternalRedirect(?string $path): bool {
    return $path
        && str_starts_with($path, '/')
        && !str_starts_with($path, '//')
        && !str_contains($path, '\\')
        && !str_contains($path, '://');
}

function currentUser(): ?array {
    attemptRememberLogin();
    if (empty($_SESSION['user_id'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = getDB()->prepare('SELECT u.*, p.display_name, p.bio, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_artist_name, p.spotify_show_id, p.spotify_show_name, p.youtube_channel_id, p.youtube_channel_name, p.genere, p.citta, p.provincia, p.telefono, p.custom_feed_guid, p.custom_feed_guid_since, p.cinema_films_json_url, p.cinema_films_synced_at
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
    getDB()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
    issueRememberToken((int) $row['user_id']);
}

function requireLogin(): array {
    $u = currentUser();
    if (!$u) {
        header('Location: /login.php');
        exit;
    }
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

function canManageProfile(int $viewerId, int $ownerId): bool {
    if ($viewerId === $ownerId) {
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
    $stmt = getDB()->prepare('SELECT u.*, p.display_name, p.bio, p.avatar_path, p.theme_color, p.page_theme, p.dashboard_theme, p.spotify_artist_id, p.spotify_artist_name, p.spotify_show_id, p.spotify_show_name, p.youtube_channel_id, p.youtube_channel_name, p.genere, p.citta, p.provincia, p.telefono, p.custom_feed_guid, p.custom_feed_guid_since, p.privacy_tracking_settings, p.cinema_films_json_url, p.cinema_films_synced_at
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

function getSiteSetting(string $key): ?string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = getDB()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $cache[$key] = $row ? $row['setting_value'] : null;
}

function setSiteSetting(string $key, string $value): void {
    $stmt = getDB()->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

// Nome del sito installato, impostato dal wizard install.php e modificabile da Area Admin.
// "Chi Fa Cosa" (il nome del software) resta come fallback solo se il wizard non è ancora
// stato completato — ogni installazione configurata mostra il proprio nome ovunque, mai
// quello del software sottostante (eccetto Crediti e header Admin, che restano branding fisso).
function siteName(): string {
    $name = trim(getSiteSetting('site_name') ?? '');
    return $name !== '' ? $name : 'Chi Fa Cosa';
}

// Se il profilo ha un link personalizzato per il feed attivo (Dashboard → Timeline) e questo
// contenuto è stato pubblicato da quando è stato impostato, reindirizza i visitatori umani
// all'URL esterno via JS non appena la pagina carica. I bot che leggono solo l'HTML statico
// (Metricool e la maggior parte degli scraper Open Graph) non eseguono JavaScript e continuano
// a leggere gli og:image/og:title corretti di questa pagina — solo chi clicca davvero dal
// social finisce sul sito esterno. Va richiamata nell'<head>, il prima possibile.
function emitCustomFeedLinkRedirect(?string $customFeedLink, ?string $customFeedLinkSince, string $contentPublishedAt): void {
    if (!$customFeedLink || !$customFeedLinkSince) {
        return;
    }
    if (strtotime($contentPublishedAt) < strtotime($customFeedLinkSince)) {
        return;
    }
    echo '<script>location.replace(' . json_encode($customFeedLink) . ');</script>' . "\n";
}

// Decodifica le impostazioni Privacy/Cookie e Tracking personalizzate di UN profilo (Dashboard
// → Privacy e Tracking) — salvate come un unico campo JSON invece di tante colonne separate,
// per non dover aggiungere una colonna a ogni nuovo parametro futuro. Vuoto/non impostato se il
// profilo non ha compilato nulla, o se la query che ha caricato $profile non porta con sé questa
// colonna (pagine di sistema senza un profilo specifico, es. login/registrazione).
function getProfileTracking(?array $profile): array {
    $raw = $profile['privacy_tracking_settings'] ?? null;
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Restituisce lo script privacy/cookie (es. Iubenda) da stampare nell'<head> di una pagina
// pubblica: quello del PROFILO se lo ha impostato lui stesso (Dashboard → Privacy e Tracking),
// altrimenti quello impostato dall'admin per l'intero sito. Senza un profilo (pagine di sistema
// come login/registrazione) resta il comportamento di sempre, solo quello dell'admin.
function embedPrivacyScript(?array $profile = null): string {
    $own = trim(getProfileTracking($profile)['privacy_script'] ?? '');
    if ($own !== '') {
        return $own;
    }
    return getSiteSetting('privacy_script') ?: '';
}

// Genera lo snippet standard di Google Analytics (gtag.js) a partire dal solo Measurement ID
// (es. G-XXXXXXXXXX), così l'admin non deve incollare script complessi a mano.
/**
 * Ottiene il tema grafico corrente
 */
function getCurrentTheme(): array {
    try {
        $pdo = getDB();
        
        // Fetch current theme ID from settings
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'current_theme_id'");
        $stmt->execute();
        $theme_id = $stmt->fetchColumn() ?: 1;
        
        // Fetch theme data
        $stmt = $pdo->prepare("SELECT * FROM themes WHERE id = ?");
        $stmt->execute([$theme_id]);
        $theme = $stmt->fetch();
        
        return $theme ?: [
            'primary_color' => '#ff6b6b',
            'deep_color' => '#cc5555',
            'light_color' => '#ffe8e8',
            'accent_color' => '#ff8e8e',
            'text_primary' => '#1A1A1A',
            'text_secondary' => '#757575',
            'success_color' => '#4CAF50',
            'error_color' => '#F44336'
        ];
    } catch (Exception $e) {
        return [
            'primary_color' => '#ff6b6b',
            'deep_color' => '#cc5555',
            'light_color' => '#ffe8e8',
            'accent_color' => '#ff8e8e',
            'text_primary' => '#1A1A1A',
            'text_secondary' => '#757575',
            'success_color' => '#4CAF50',
            'error_color' => '#F44336'
        ];
    }
}

/**
 * Genera CSS dinamico basato sul tema corrente
 */
function embedThemeCSS(): string {
    $theme = getCurrentTheme();
    return '<style>
        :root {
            --primary-color: ' . $theme['primary_color'] . ';
            --deep-color: ' . $theme['deep_color'] . ';
            --light-color: ' . $theme['light_color'] . ';
            --accent-color: ' . $theme['accent_color'] . ';
            --text-primary: ' . $theme['text_primary'] . ';
            --text-secondary: ' . $theme['text_secondary'] . ';
            --success-color: ' . $theme['success_color'] . ';
            --error-color: ' . $theme['error_color'] . ';
        }
        
        /* Button Styles */
        .btn-primary, .button-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover, .button-primary:hover { opacity: 0.9; }
        
        .btn-secondary, .button-secondary { color: var(--primary-color); border-color: var(--primary-color); }
        .btn-secondary:hover, .button-secondary:hover { background: var(--primary-color); color: white; }
        
        /* Links */
        a { color: var(--primary-color); }
        a:hover { color: var(--deep-color); }
        
        /* Forms */
        input:focus, textarea:focus, select:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(0, 119, 221, 0.1) !important;
        }
        
        /* Hero */
        .hero h1 span { color: var(--primary-color); }
        
        /* Alerts */
        .alert.success { background: rgba(76, 175, 80, 0.1); border-color: var(--success-color); color: var(--success-color); }
        .alert.error { background: rgba(244, 67, 54, 0.1); border-color: var(--error-color); color: var(--error-color); }
    </style>';
}

function embedGoogleAnalytics(?array $profile = null): string {
    $id = trim(getProfileTracking($profile)['ga_measurement_id'] ?? '');
    if ($id === '') {
        $id = trim(getSiteSetting('ga_measurement_id') ?: '');
    }
    if ($id === '') {
        return '';
    }
    $safeIdAttr = e($id);
    $safeIdJs = json_encode($id);
    return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $safeIdAttr . '"></script>' . "\n"
         . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
         . 'gtag("js",new Date());gtag("config",' . $safeIdJs . ');</script>';
}

// Riconosce la piattaforma social da un URL, tra quelle mostrate come icona in cima alla pagina
// pubblica. Ogni voce ha una "key" univoca usata per la deduplicazione (un solo link per
// piattaforma viene mostrato come icona) e una classe Font Awesome completa per l'icona.
function detectPlatform(string $url): ?array {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $map = [
        'spotify.com'      => ['key' => 'spotify',    'icon_class' => 'fa-brands fa-spotify',    'label' => 'Spotify'],
        'music.apple.com'  => ['key' => 'apple_music','icon_class' => 'fa-brands fa-apple',      'label' => 'Apple Music'],
        'instagram.com'    => ['key' => 'instagram',  'icon_class' => 'fa-brands fa-instagram',  'label' => 'Instagram'],
        'facebook.com'     => ['key' => 'facebook',   'icon_class' => 'fa-brands fa-facebook-f', 'label' => 'Facebook'],
        'fb.com'           => ['key' => 'facebook',   'icon_class' => 'fa-brands fa-facebook-f', 'label' => 'Facebook'],
        'tiktok.com'       => ['key' => 'tiktok',     'icon_class' => 'fa-brands fa-tiktok',     'label' => 'TikTok'],
        'youtube.com'      => ['key' => 'youtube',    'icon_class' => 'fa-brands fa-youtube',    'label' => 'YouTube'],
        'youtu.be'         => ['key' => 'youtube',    'icon_class' => 'fa-brands fa-youtube',    'label' => 'YouTube'],
        'linkedin.com'     => ['key' => 'linkedin',   'icon_class' => 'fa-brands fa-linkedin-in','label' => 'LinkedIn'],
        'soundcloud.com'   => ['key' => 'soundcloud', 'icon_class' => 'fa-brands fa-soundcloud', 'label' => 'SoundCloud'],
        'whatsapp.com'     => ['key' => 'whatsapp',   'icon_class' => 'fa-brands fa-whatsapp',   'label' => 'WhatsApp'],
        'wa.me'            => ['key' => 'whatsapp',   'icon_class' => 'fa-brands fa-whatsapp',   'label' => 'WhatsApp'],
        'x.com'            => ['key' => 'x',          'icon_class' => 'fa-brands fa-x-twitter',  'label' => 'X'],
        'twitter.com'      => ['key' => 'x',          'icon_class' => 'fa-brands fa-x-twitter',  'label' => 'X'],
    ];
    foreach ($map as $domain => $info) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return $info;
        }
    }
    return null;
}

// Separa i link di un utente in: icone social (una sola per piattaforma, la PRIMA incontrata
// scorrendo l'elenco così come ordinato dal band manager nella propria dashboard) e pulsanti
// azione (tutto il resto: ripetizioni della stessa piattaforma, e link non riconosciuti).
// Un link marcato manualmente come "sito web personale" diventa sempre un'icona (globo),
// indipendentemente dal dominio, perché un sito personale non è riconoscibile automaticamente.
function splitSocialAndActionLinks(array $links): array {
    $socialLinks = [];
    $actionLinks = [];
    $seenKeys = [];
    foreach ($links as $l) {
        if (!empty($l['is_website_icon']) && !isset($seenKeys['website'])) {
            $socialLinks[] = $l + ['platform' => ['key' => 'website', 'icon_class' => 'fa-solid fa-globe', 'label' => 'Sito web']];
            $seenKeys['website'] = true;
            continue;
        }
        $platform = detectPlatform($l['url']);
        if ($platform && !isset($seenKeys[$platform['key']])) {
            $socialLinks[] = $l + ['platform' => $platform];
            $seenKeys[$platform['key']] = true;
        } else {
            $actionLinks[] = $l;
        }
    }
    return [$socialLinks, $actionLinks];
}

// Palette di colori pastello per i pulsanti "azione" nel tema colorato della pagina pubblica
// Registro dei temi grafici disponibili per la pagina pubblica — aggiungerne uno nuovo in
// futuro significa solo aggiungere una voce qui + le regole CSS corrispondenti (vedi
// style.css), senza toccare le singole pagine pubbliche.
const PAGE_THEMES = [
    'colorful' => ['label' => 'Colorful', 'description' => 'Sfumatura pastello, il classico CHI FA COSA', 'body_class' => 'colorful-page'],
    'rock' => ['label' => 'Rock', 'description' => 'Sfondo scuro, angoli netti, tono più deciso', 'body_class' => 'rock-page'],
    'wave' => ['label' => 'Wave', 'description' => 'Sfondo 3D scuro, griglia di cubi che ondeggia al passaggio del mouse', 'body_class' => 'wave-page'],
    'wave-light' => ['label' => 'Wave Chiaro', 'description' => 'Stessa griglia animata, in versione chiara e più ariosa', 'body_class' => 'wave-light-page'],
    'wave-neon' => ['label' => 'Wave Neon', 'description' => 'Griglia più fitta, colonne invece di cubi, tono più notturno', 'body_class' => 'wave-neon-page'],
    'aurora' => ['label' => 'Aurora', 'description' => 'Cielo stellato che sfuma verso il tramonto, pulsanti corallo con profondità', 'body_class' => 'aurora-page'],
    'plasma' => ['label' => 'Plasma', 'description' => 'Sfumatura viola-magenta-blu satura, pulsanti arancioni a pillola', 'body_class' => 'plasma-page'],
    'golden' => ['label' => 'Golden', 'description' => 'Tramonto caldo, pulsanti bianchi minimal, atmosfera quieta', 'body_class' => 'golden-page'],
    'la-caraffa' => ['label' => 'La Caraffa', 'description' => 'Tema blu corporativo ispirato al logo di La Caraffa Ristorante', 'body_class' => 'la-caraffa-page'],
    'electric' => ['label' => 'Electric', 'description' => 'Sfondo scuro con un bordo elettrico animato attorno al profilo', 'body_class' => 'electric-page'],
    'circuit' => ['label' => 'Circuit', 'description' => 'Griglia 3D di tubi che ruotano lentamente, come un circuito stampato', 'body_class' => 'circuit-page'],
    'cosplay-blue' => ['label' => 'Cosplay Blu', 'description' => 'Sfondo blu pastello, pulsanti a pillola con bordo nero e ombra netta, stile sticker/fumetto', 'body_class' => 'cosplay-blue-page'],
    'cosplay-pink' => ['label' => 'Cosplay Rosa', 'description' => 'Stessa grafica a sticker con bordo nero, in rosa confetto', 'body_class' => 'cosplay-pink-page'],
    'cosplay-mint' => ['label' => 'Cosplay Menta', 'description' => 'Stessa grafica a sticker con bordo nero, in verde menta', 'body_class' => 'cosplay-mint-page'],
    'cosplay-yellow' => ['label' => 'Cosplay Giallo', 'description' => 'Stessa grafica a sticker con bordo nero, in giallo pastello', 'body_class' => 'cosplay-yellow-page'],
    'scifi-cyan' => ['label' => 'Fantascienza Cyan', 'description' => 'Pannelli in stile HUD di un\'astronave, bordo neon ciano su sfondo scuro con scanline', 'body_class' => 'scifi-cyan-page'],
    'scifi-magenta' => ['label' => 'Fantascienza Magenta', 'description' => 'Stessi pannelli HUD, neon magenta cyberpunk al posto del ciano', 'body_class' => 'scifi-magenta-page'],
    'horror-blood' => ['label' => 'Horror Sangue', 'description' => 'Sfondo scuro con vignettatura, angoli netti e bordi rosso sangue', 'body_class' => 'horror-blood-page'],
    'horror-fog' => ['label' => 'Horror Nebbia', 'description' => 'Stessa atmosfera inquietante, tono verde-grigio nebbioso al posto del rosso', 'body_class' => 'horror-fog-page'],
    'thriller-noir' => ['label' => 'Thriller Noir', 'description' => 'Bianco e nero da spionaggio, vignettatura a faretto, accento rosso', 'body_class' => 'thriller-noir-page'],
    'thriller-shadow' => ['label' => 'Thriller Ombra', 'description' => 'Stessa tensione da thriller, tono blu-grigio freddo al posto del rosso', 'body_class' => 'thriller-shadow-page'],
    'agent-gold' => ['label' => '007 Oro', 'description' => 'Nero elegante con dettagli oro, stile titoli di apertura da film di spionaggio', 'body_class' => 'agent-gold-page'],
    'agent-silver' => ['label' => '007 Argento', 'description' => 'Stessa eleganza da agente segreto, argento al posto dell\'oro', 'body_class' => 'agent-silver-page'],
    'bunny' => ['label' => 'Coniglietti', 'description' => 'Pastello rosa soffice, con vere orecchie da coniglio sull\'avatar e zampette 🐾 sui pulsanti', 'body_class' => 'bunny-page'],
    'zebra' => ['label' => 'Zebrato', 'description' => 'Lo sfondo della pagina è a vere righe zebrate bianche e nere, dettagli rosa acceso', 'body_class' => 'zebra-page'],
    'polka' => ['label' => 'Pois', 'description' => 'Sfondo giallo a pois bianchi, dettagli rosso ciliegia, tono vintage/picnic', 'body_class' => 'polka-page'],
    'meme67' => ['label' => '67', 'description' => 'Il meme "6 7": numeri giganti sullo sfondo, sfondo a raggiera, verde neon e arancione', 'body_class' => 'meme67-page'],
    'napoli' => ['label' => 'Forza Napoli', 'description' => 'Cielo azzurro sul golfo con il Vesuvio all\'orizzonte, sole che ruota dietro l\'avatar, coriandoli azzurro/oro che salgono dal basso', 'body_class' => 'napoli-page'],
    'startrek' => ['label' => 'Frontiera Stellare', 'description' => 'Ispirato a Star Trek: pannelli in stile LCARS, campo stellare animato, lampi di "salto nel warp" e un distintivo circolare originale sull\'avatar (non il logo ufficiale del franchise)', 'body_class' => 'startrek-page'],
    'galactic' => ['label' => 'Console Galattica', 'description' => 'Iperspazio animato su canvas con salto al passaggio del mouse, nebulosa che si muove, avatar olografico con scanline e glitch, pulsanti console e 4 stili di pulsante animati, cursore a lama energetica, suoni sintetizzati silenziabili — elementi originali, nessun logo o personaggio di alcun franchise', 'body_class' => 'galactic-page'],
    'cinemapop' =>['label' => 'Cinema Pop', 'description' => 'Ispirato a una sala cinematografica: sfondo scuro con un bagliore arancione da faretto dietro l\'avatar, pellicola con fori da film in alto e in basso, popcorn dorati che salgono dal basso in continuo, pulsanti a righe come un secchiello di popcorn con un riflesso lucido che scorre — colori e atmosfera originali, nessun logo di alcun cinema', 'body_class' => 'cinemapop-page'],
    'nightdrop' => ['label' => 'Drop Notturno', 'description' => 'Sfondo blu notte da vetrina "shop", pulsanti bianchi a pillola con freccina a cerchio sulla destra, un pulsante ogni tanto in rosso acceso come evidenziato, card scure per le griglie di contenuto', 'body_class' => 'nightdrop-page'],
    // body_class = 'colorful-page' di proposito, non un nome dedicato: il tema AdminLTE sostituisce
    // solo la Home (vedi il primo "if" in u.php), le altre pagine pubbliche (Timeline, Blog...) non
    // hanno mai avuto un vestito AdminLTE e continuano a usare lo scheletro condiviso — senza
    // questo fallback prenderebbero una classe CSS che non esiste da nessuna parte, cioè nessuno
    // stile, non "Colorful in prestito".
    'adminlte-profile' => ['label' => 'AdminLTE', 'description' => 'Layout a card in stile pannello gestionale (basato su AdminLTE 4): card "About" con follower e recensioni, i tuoi link, e le sezioni del sito raccolte in tab — Timeline, Che Amo, Podcast, Blog e le altre che hai attive. Vale solo per la Home: le altre pagine restano nel tema Colorful.', 'body_class' => 'colorful-page'],
];

// Parametri della griglia 3D per ciascuna variante Wave — stesso script (wave-bg.js), letto
// tramite attributi data-* sulla canvas, così ogni variante cambia forma/dimensione/colori
// senza duplicare codice JavaScript.
const WAVE_THEME_PARAMS = [
    'wave' => ['base' => '#1a1a1a', 'shape' => 'box', 'gridSize' => 22, 'cubeSize' => 0.75, 'gap' => 0.18, 'cubeHeight' => 2.4, 'ambientIntensity' => 0.6, 'lightIntensity' => 2.2],
    'wave-light' => ['base' => '#d8d8e0', 'shape' => 'box', 'gridSize' => 18, 'cubeSize' => 0.85, 'gap' => 0.28, 'cubeHeight' => 1.6, 'ambientIntensity' => 1.1, 'lightIntensity' => 1.6],
    'wave-neon' => ['base' => '#0d0d14', 'shape' => 'cylinder', 'gridSize' => 30, 'cubeSize' => 0.55, 'gap' => 0.08, 'cubeHeight' => 2.8, 'ambientIntensity' => 0.4, 'lightIntensity' => 2.6],
];

// Sfondo animato Three.js per i temi "Wave" — canvas fisso dietro al contenuto, caricato solo
// se il profilo ha scelto uno di questi temi. Fallisce in silenzio se il browser non supporta
// WebGL. I parametri di forma/dimensione/colore variano in base al tema scelto.
function renderWaveBackground(string $accentColor, string $themeKey = 'wave'): string {
    $p = WAVE_THEME_PARAMS[$themeKey] ?? WAVE_THEME_PARAMS['wave'];
    return '<canvas id="wave-bg-canvas"'
        . ' data-accent="' . e($accentColor) . '"'
        . ' data-base="' . e($p['base']) . '"'
        . ' data-shape="' . e($p['shape']) . '"'
        . ' data-grid-size="' . (int) $p['gridSize'] . '"'
        . ' data-cube-size="' . e($p['cubeSize']) . '"'
        . ' data-gap="' . e($p['gap']) . '"'
        . ' data-cube-height="' . e($p['cubeHeight']) . '"'
        . ' data-ambient-intensity="' . e($p['ambientIntensity']) . '"'
        . ' data-light-intensity="' . e($p['lightIntensity']) . '"'
        . '></canvas>
    <script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
    <script src="' . assetUrl('/assets/js/wave-bg.js') . '"></script>';
}

// Sfondo animato per il tema "Circuit" — griglia CSS/JS (nessuna dipendenza esterna) di
// tessere che ruotano lentamente, a formare un disegno stile circuito stampato. Container
// fisso dietro al contenuto, caricato solo se il profilo ha scelto questo tema.
function renderCircuitBackground(string $accentColor): string {
    return '<div id="circuit-bg" data-accent="' . e($accentColor) . '"></div>
    <script src="' . assetUrl('/assets/js/circuit-bg.js') . '"></script>';
}

// Sfondo animato per il tema "Forza Napoli" — silhouette del Vesuvio fissa in basso (puro CSS,
// vedi style.css) e coriandoli azzurro/bianco/oro generati da napoli-fx.js che salgono dal
// basso in continuo. Container fisso dietro al contenuto, caricato solo se il profilo ha scelto
// questo tema, stesso schema di renderCircuitBackground()/renderWaveBackground().
function renderNapoliBackground(): string {
    return '<div id="napoli-bg"><div class="napoli-vesuvio"></div></div>
    <script src="' . assetUrl('/assets/js/napoli-fx.js') . '"></script>';
}

// Sfondo animato per il tema "Cinema Pop" — le due bande da pellicola cinematografica (fori
// bianchi su nero) in alto e in fondo sono puro CSS, i popcorn dorati che salgono dal basso in
// continuo sono generati da cinemapop-fx.js, stesso schema di napoli-fx.js (elementi CSS
// animati riciclati uno alla volta, nessun canvas/WebGL).
function renderCinemaPopBackground(): string {
    return '<div id="cinemapop-bg"><div class="cinemapop-filmstrip top"></div><div class="cinemapop-filmstrip bottom"></div></div>
    <script src="' . assetUrl('/assets/js/cinemapop-fx.js') . '"></script>';
}

// Sfondo animato per il tema "Frontiera Stellare" (ispirato a Star Trek) — campo stellare che
// scintilla e strisce di "salto nel warp" generate da startrek-fx.js. Container fisso dietro al
// contenuto, caricato solo se il profilo ha scelto questo tema, stesso schema delle altre
// funzioni renderXBackground().
function renderStarTrekBackground(): string {
    return '<div id="startrek-bg"></div>
    <script src="' . assetUrl('/assets/js/startrek-fx.js') . '"></script>';
}

// Sfondo animato per il tema "Console Galattica" — campo stellare su canvas che simula un
// viaggio nell'iperspazio (galactic-fx.js), più un pulsante per attivare/disattivare i suoni
// sintetizzati (spenti di default, l'utente li accende esplicitamente). Container fisso dietro
// al contenuto, caricato solo se il profilo ha scelto questo tema, stesso schema delle altre
// funzioni renderXBackground().
function renderGalacticBackground(): string {
    return '<div id="galactic-bg"></div>
    <button type="button" id="galactic-sound-toggle" class="floating-btn" style="bottom:130px;" title="Attiva i suoni" aria-pressed="false"></button>
    <script src="' . assetUrl('/assets/js/galactic-fx.js') . '"></script>';
}

// Rende un colore CSS (esadecimale "#rrggbb" o "hsl(h,s%,l%)", le due forme usate nei temi)
// semi-trasparente in modo sicuro — non si può semplicemente accodare un suffisso alpha
// esadecimale a un hsl(), non è CSS valido: va convertito nella variante rgba()/hsla() giusta.
function cssColorWithAlpha(string $color, float $alpha): string {
    $color = trim($color);
    if (str_starts_with($color, '#')) {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) === 6 && ctype_xdigit($hex)) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
        }
    } elseif (preg_match('/^hsl\(([^)]+)\)$/i', $color, $m)) {
        return 'hsla(' . $m[1] . ',' . $alpha . ')';
    }
    return $color; // formato non riconosciuto: meglio lasciarlo invariato che generare CSS rotto
}

// Calcola se il testo sopra un colore di sfondo debba essere bianco o scuro, in base alla
// luminosità percepita — usato per il pulsante attivo del menu e "Segui", che altrimenti
// sarebbero sempre bianchi anche quando il colore scelto dal profilo è già chiaro (es. un
// turchese acceso), risultando illeggibili.
function getContrastTextColor(?string $hexColor): string {
    $hex = ltrim($hexColor ?: '#6C5CE7', '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '#fff';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    // Luminosità percepita (formula standard W3C, approssimata)
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.6 ? '#22223b' : '#fff';
}

// Icona Bootstrap Icons per ciascun modulo "che amo" (CHE_AMO_MODULES usa icone FontAwesome, non
// caricate in questo tema) — condivisa fra il tab Che Amo della Home e la vetrina standalone
// (che_amo.php).
const ADMINLTE_CHE_AMO_ICONS = [
    'bandcheamo' => 'bi-heart-pulse', 'attorichamo' => 'bi-mask', 'filmcheamo' => 'bi-film',
    'libricheamo' => 'bi-book', 'viaggi' => 'bi-airplane', 'brani' => 'bi-music-note-beamed',
    'playlistcheamo' => 'bi-music-note-list', 'albumcheamo' => 'bi-disc', 'ricettecheamo' => 'bi-egg-fried',
    'squadrecheamo' => 'bi-shield-fill', 'calciatoricheamo' => 'bi-person-badge-fill', 'partitecheamo' => 'bi-calendar-event-fill',
];

// Configurazione condivisa dei 6 moduli "che amo" con contenuto arricchito da un'API esterna
// (Spotify/TMDb/Google Books) — stessa forma di FAN_FAVORITE_KINDS in fan_favorite_item.php (tema
// Colorful), duplicata qui volutamente: due temi che vivono in file diversi, senza <head>/<body>
// condivisi — tenerle vicine al proprio tema evita un accoppiamento sottile tra i due.
const ADMINLTE_FAN_FAVORITE_KINDS = [
    'band' => ['table' => 'fan_favorite_bands', 'external_id_col' => 'spotify_artist_id', 'name_col' => 'spotify_artist_name', 'image_col' => 'artist_image', 'label' => 'Band che amo', 'nav_key' => 'bandcheamo', 'list_url_segment' => 'band-che-amo', 'external_label' => 'Vedi su Spotify', 'external_url' => 'https://open.spotify.com/artist/', 'image_shape' => 'circle'],
    'actor' => ['table' => 'fan_favorite_actors', 'external_id_col' => 'tmdb_person_id', 'name_col' => 'actor_name', 'image_col' => 'actor_image', 'label' => 'Attori che amo', 'nav_key' => 'attorichamo', 'list_url_segment' => 'attori-che-amo', 'external_label' => 'Vedi su TMDb', 'external_url' => 'https://www.themoviedb.org/person/', 'image_shape' => 'circle'],
    'movie' => ['table' => 'fan_favorite_movies', 'external_id_col' => 'tmdb_movie_id', 'name_col' => 'movie_title', 'image_col' => 'movie_image', 'label' => 'Film che amo', 'nav_key' => 'filmcheamo', 'list_url_segment' => 'film-che-amo', 'external_label' => 'Vedi su TMDb', 'external_url' => 'https://www.themoviedb.org/movie/', 'image_shape' => 'circle'],
    'book' => ['table' => 'fan_favorite_books', 'external_id_col' => 'google_books_id', 'name_col' => 'book_title', 'image_col' => 'book_image', 'label' => 'Libri che amo', 'nav_key' => 'libricheamo', 'list_url_segment' => 'libri-che-amo', 'external_label' => 'Vedi su Google Books', 'external_url' => 'https://books.google.com/books?id=', 'image_shape' => 'book'],
    'playlist' => ['table' => 'fan_favorite_playlists', 'external_id_col' => 'spotify_playlist_id', 'name_col' => 'playlist_name', 'image_col' => 'playlist_image', 'label' => 'Playlist che amo', 'nav_key' => 'playlistcheamo', 'list_url_segment' => 'playlist-che-amo', 'external_label' => 'Ascolta su Spotify', 'external_url' => 'https://open.spotify.com/playlist/', 'image_shape' => 'square'],
    'album' => ['table' => 'fan_favorite_albums', 'external_id_col' => 'spotify_album_id', 'name_col' => 'album_name', 'image_col' => 'album_image', 'label' => 'Album che amo', 'nav_key' => 'albumcheamo', 'list_url_segment' => 'album-che-amo', 'external_label' => 'Ascolta su Spotify', 'external_url' => 'https://open.spotify.com/album/', 'image_shape' => 'square'],
    'recipe' => ['table' => 'fan_favorite_recipes', 'external_id_col' => 'spoonacular_recipe_id', 'name_col' => 'recipe_title', 'image_col' => 'recipe_image', 'label' => 'Ricette che amo', 'nav_key' => 'ricettecheamo', 'list_url_segment' => 'ricette-che-amo', 'external_label' => 'Vedi ricetta completa', 'external_url' => 'https://spoonacular.com/recipes/-', 'image_shape' => 'square'],
    'team' => ['table' => 'fan_favorite_teams', 'external_id_col' => 'thesportsdb_team_id', 'name_col' => 'team_name', 'image_col' => 'team_badge', 'label' => 'Squadre che amo', 'nav_key' => 'squadrecheamo', 'list_url_segment' => 'squadre-che-amo', 'external_label' => 'Vedi su TheSportsDB', 'external_url' => 'https://www.thesportsdb.com/team/', 'image_shape' => 'square'],
    'footballer' => ['table' => 'fan_favorite_players', 'external_id_col' => 'thesportsdb_player_id', 'name_col' => 'player_name', 'image_col' => 'player_photo', 'label' => 'Calciatori che amo', 'nav_key' => 'calciatoricheamo', 'list_url_segment' => 'calciatori-che-amo', 'external_label' => 'Vedi su TheSportsDB', 'external_url' => 'https://www.thesportsdb.com/player/', 'image_shape' => 'circle'],
    'match' => ['table' => 'fan_favorite_matches', 'external_id_col' => 'thesportsdb_event_id', 'name_col' => 'match_title', 'image_col' => 'match_image', 'label' => 'Partite che amo', 'nav_key' => 'partitecheamo', 'list_url_segment' => 'partite-che-amo', 'external_label' => 'Vedi su TheSportsDB', 'external_url' => 'https://www.thesportsdb.com/event/', 'image_shape' => 'square'],
];

// Helper condivisi per TUTTE le pagine pubbliche a tema AdminLTE oltre alla Home (Timeline, Blog,
// Che Amo, Spotify, Podcast, Video, Menù, Offerte, Foto, Servizi, Eventi, Contatti...) — evitano di
// ripetere lo stesso ~30 righe di link CSS/breadcrumb/footer in ognuna.
function adminLteAssetLinks(): string {
    return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous" media="print" onload="this.media=\'all\'">' . "\n"
         . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">' . "\n"
         . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">' . "\n"
         . '<link rel="stylesheet" href="' . assetUrl('/assets/themes/adminlte-profile/css/adminlte.min.css') . '">' . "\n"
         // La miniatura degli articoli del blog (renderAdminLteBlogRows()) è pensata per stare
         // accanto al titolo: a 140px fissi, su schermi stretti mangia più di un terzo della
         // larghezza e schiaccia il titolo in una colonna strettissima che va a capo parola per
         // parola. Qui sotto 576px si riduce.
         . '<style>.adminlte-blog-thumb{width:140px;height:140px;object-fit:cover;flex-shrink:0;}'
         . '@media (max-width:575.98px){.adminlte-blog-thumb{width:88px;height:88px;}}'
         // Nome del profilo nel brand della barra di navigazione (adminLteTopNav()): troncato per
         // non spingere fuori schermo le voci di menu quando il nome è lungo.
         . '.topnav-brand-name{max-width:180px;display:inline-block;}'
         // Con molte voci visibili contemporaneamente (Che Amo, Spotify, Podcast, Video, Blog,
         // Menù, Offerte, Foto, Servizi, Eventi, Contatti) la barra può non stare su una riga sola
         // alle larghezze desktop più strette: Bootstrap non manda a capo le voci di ".navbar-nav"
         // di default (le farebbe uscire dallo schermo) — qui si permette il ritorno a capo invece
         // che il taglio.
         . '@media (min-width:992px){#topNavMenu .navbar-nav{flex-wrap:wrap;row-gap:.25rem;}}'
         // Le tre colonne del profilo (sidebar, contenuto, extra) sono già alte quanto la più alta
         // delle tre (comportamento predefinito di flexbox su ".row") — ma la card della colonna
         // centrale (.adminlte-main-col) si fermava alla propria altezza naturale, lasciando lo
         // sfondo grigio della pagina sotto quando il contenuto era più corto delle colonne
         // laterali (es. il modulo di Contatti): pareva "non estendersi" come le altre, con uno
         // spazio vuoto sprecato. Qui la card si allunga fino a riempire tutta l'altezza
         // disponibile, e il suo card-body con lei (le card sono già "display:flex;flex-direction:
         // column" di loro — vedi Bootstrap — basta farle crescere). Solo da tablet in su: sotto le
         // colonne sono impilate e non c'è nessuna altezza da pareggiare.
         . '@media (min-width:768px){.adminlte-main-col{display:flex;flex-direction:column;}'
         . '.adminlte-main-col>.card{flex:1 1 auto;}'
         . '.adminlte-main-col>.card>.card-body{flex:1 1 auto;}}'
         // Sotto i 768px le tre colonne (sidebar, contenuto, extra) sono impilate una sopra
         // l'altra: il padding del container Bootstrap (.75rem) sommato al gutter della riga
         // (.5rem, da "row g-3") lasciava fino a 20px di sfondo vuoto per lato attorno alle card,
         // uno spreco su schermi già stretti. Qui sotto vengono azzerati entrambi, così le card
         // toccano i bordi dello schermo (il loro padding interno, card-body, resta invariato: il
         // testo non tocca comunque i bordi).
         . '@media (max-width:767.98px){.app-content .container-fluid{padding-left:0;padding-right:0;}'
         . '.app-content .row.g-3{--bs-gutter-x:0;}}'
         // Gli elementi della Timeline (.timeline-item/.timeline-body) hanno un padding/margine
         // laterale importato di serie da AdminLTE, pensato per un contenuto testuale — con le
         // foto (che ora sono anche più grandi, 140px invece di 80px, vedi renderAdminLteTimelineRows())
         // lasciava via via più spazio vuoto sui lati via via che la card si stringeva. Qui viene
         // ridotto: il gutter per l'icona/linea a sinistra resta uguale (è l'allineamento
         // strutturale del widget), stringe solo il margine destro e il padding interno.
         . '.timeline>div>.timeline-item{margin-right:6px;}'
         . '.timeline>div>.timeline-item>.timeline-body{padding:6px;}'
         . '</style>';
}

// Blocco breadcrumb + titolo H1 dell'app-content-header. $trail sono le tappe intermedie tra
// l'artista e la pagina corrente, come coppie [etichetta => url] (es. ['Che Amo' => '/slug/che-amo']
// per la pagina di un singolo elemento) — vuoto per le pagine di primo livello.
function adminLteBreadcrumbHeader(string $slug, string $displayName, string $current, array $trail = []): string {
    // Sulla Home dell'artista $current è lo stesso $displayName (il titolo H1 è il suo nome):
    // niente link di metà percorso in quel caso, altrimenti il nome comparirebbe due volte di
    // fila nel breadcrumb ("Chi Fa Cosa / Nome / Nome").
    $isOwnHome = ($current === $displayName) && !$trail;
    ob_start();
    ?>
    <div class="app-content-header">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12 d-none d-sm-block">
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="/"><?= e(siteName()) ?></a></li>
                <?php if (!$isOwnHome): ?>
                  <li class="breadcrumb-item"><a href="/<?= e($slug) ?>"><?= e($displayName) ?></a></li>
                <?php endif; ?>
                <?php foreach ($trail as $label => $url): ?>
                  <li class="breadcrumb-item"><a href="<?= e($url) ?>"><?= e($label) ?></a></li>
                <?php endforeach; ?>
                <li class="breadcrumb-item active" aria-current="page"><?= e($current) ?></li>
              </ol>
            </nav>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

function adminLteFooterBlock(array $artist): string {
    $footerPrivacyUrl = trim(getProfileTracking($artist)['privacy_policy_url'] ?? '') ?: (getSiteSetting('privacy_policy_url') ?: '');
    ob_start();
    ?>
  <footer class="app-footer">
    <div class="float-end d-none d-sm-inline">
      <a href="#" class="cky-banner-element text-decoration-none">Preferenze Cookie</a>
      · <a href="<?= $footerPrivacyUrl !== '' ? e($footerPrivacyUrl) : '/' ?>" class="text-decoration-none"<?= $footerPrivacyUrl !== '' ? ' target="_blank" rel="noopener"' : '' ?>>Privacy</a>
      · <a href="/credits.php" class="text-decoration-none">Crediti</a>
    </div>
    <strong><?= e($artist['display_name']) ?></strong> su <a href="/" class="text-decoration-none"><?= e(siteName()) ?></a>
  </footer>
  <button type="button" id="adminlte-back-to-top" class="btn btn-primary rounded-circle shadow d-none" style="position:fixed;right:20px;bottom:20px;width:46px;height:46px;z-index:1030;" aria-label="Torna su">
    <i class="bi bi-arrow-up" aria-hidden="true"></i>
  </button>
  <script>
  (function () {
    var btn = document.getElementById('adminlte-back-to-top');
    if (!btn) return;
    window.addEventListener('scroll', function () {
      btn.classList.toggle('d-none', window.scrollY <= 400);
    });
    btn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  })();
  </script>
    <?php
    return ob_get_clean();
}

// Barra di navigazione in cima a OGNI pagina pubblica a tema AdminLTE — layout "Top Nav" di
// AdminLTE 4 (https://adminlte.io/themes/v4/layout/top-nav.html): niente <aside>, il menu vive
// in un <nav class="app-header navbar navbar-expand-lg"> con toggler su mobile, sempre visibile
// (la pagina chiamante aggiunge "fixed-header" al <body>, che lo rende "sticky" in cima durante
// lo scroll — vedi .fixed-header .app-header nel CSS di AdminLTE).
//
// Prima queste voci (Che Amo, Spotify, Podcast, Video, Blog...) vivevano in fondo alla colonna
// del profilo, raggiungibili solo dopo aver scrollato oltre la card utente e le statistiche: da
// qui sono raggiungibili subito, da qualunque pagina, senza scroll. "Che Amo" diventa un menu a
// tendina con tutti i moduli (Band/Attori/Film/Libri/Playlist/Album/Viaggi/Brani che amo) invece
// di portare prima alla vetrina e poi a un secondo click — un passaggio in meno per arrivare al
// contenuto specifico. "Segui" e Accedi/Registrati, prima sotto la card profilo, sono spostati
// qui per lo stesso motivo: azioni, non contenuto, restano più utili sempre a portata di mano.
function adminLteTopNav(array $artist, string $slug, string $activeKey): string {
    $uid = (int) $artist['id'];
    $isBandOrLabel = in_array($artist['account_type'] ?? 'band', ['band', 'label'], true);
    $hiddenKeys = getHiddenNavKeys($uid);

    // Stessa selezione di che_amo.php (la vetrina): un modulo compare nel menu a tendina solo se
    // non nascosto da "Menu di Navigazione" e ha davvero del contenuto pubblico. "cheamo" tra le
    // chiavi nascoste spegne l'intero menu a tendina, non solo i singoli moduli.
    $cheAmoModules = [];
    if (!in_array('cheamo', $hiddenKeys, true)) {
        foreach (CHE_AMO_MODULES as $key => $m) {
            if (in_array($key, $hiddenKeys, true)) {
                continue;
            }
            if ($m['check'] === null || $m['check']($uid)) {
                $cheAmoModules[$key] = $m;
            }
        }
    }

    $navItems = [];
    if ($isBandOrLabel && !empty($artist['spotify_artist_id'])) {
        $navItems['spotify'] = ['label' => 'Spotify', 'url' => '/' . $slug . '/spotify'];
    }
    if ($isBandOrLabel && !empty($artist['spotify_show_id'])) {
        $navItems['podcast'] = ['label' => 'Podcast', 'url' => '/' . $slug . '/podcast'];
    }
    if ($isBandOrLabel && !empty($artist['youtube_channel_id'])) {
        $navItems['video'] = ['label' => 'Video', 'url' => '/' . $slug . '/video'];
    }
    $navItems['blog'] = ['label' => 'Blog', 'url' => '/' . $slug . '/blog'];
    if (!in_array('menu', $hiddenKeys, true) && menuHasItems($uid)) {
        $navItems['menu'] = ['label' => 'Menù', 'url' => '/' . $slug . '/menu'];
    }
    if (!in_array('offerte', $hiddenKeys, true) && hasActiveOffers($uid)) {
        $navItems['offerte'] = ['label' => 'Offerte', 'url' => '/' . $slug . '/offerte'];
    }
    if (!in_array('foto', $hiddenKeys, true) && hasPublicPhotoContent($uid)) {
        $navItems['foto'] = ['label' => 'Foto', 'url' => '/' . $slug . '/foto'];
    }
    if (!in_array('servizi', $hiddenKeys, true) && hasVisibleServices($uid)) {
        $navItems['servizi'] = ['label' => 'Servizi', 'url' => '/' . $slug . '/servizi'];
    }
    if ($isBandOrLabel && !in_array('eventi', $hiddenKeys, true)) {
        $navItems['eventi'] = ['label' => 'Eventi', 'url' => '/' . $slug . '/eventi'];
    }
    $navItems['contatti'] = ['label' => 'Contatti', 'url' => '/' . $slug . '/contatti'];

    $avatarUrl = adminLteAvatarUrl($artist);

    // Solo sul proprio profilo (lo stesso account con cui si è loggati, non un profilo diverso
    // gestito come co-admin — quelli restano seguibili come qualunque altro) il pulsante "Segui"
    // non ha senso: diventa un accesso rapido alla dashboard di gestione. Altrimenti si comporta
    // come "Segui" negli altri temi grafici (Colorful) — riflette se lo si sta già seguendo
    // (isFollowingAccount()), stesso link all'ancora #segui-widget della Home.
    $viewerId = $_SESSION['user_id'] ?? null;
    $isOwnProfile = $viewerId && (int) $viewerId === $uid;
    $isFollowing = ($viewerId && !$isOwnProfile) ? isFollowingAccount((int) $viewerId, $uid) : false;

    ob_start();
    ?>
      <nav class="app-header navbar navbar-expand-lg bg-body">
        <div class="container-fluid">
          <a href="/<?= e($slug) ?>" class="navbar-brand d-flex align-items-center">
            <img src="<?= e($avatarUrl) ?>" alt="" width="30" height="30" class="rounded-circle shadow-sm me-2">
            <span class="fw-semibold text-truncate topnav-brand-name"><?= e($artist['display_name']) ?></span>
          </a>
          <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNavMenu" aria-controls="topNavMenu" aria-expanded="false" aria-label="Menu di navigazione">
            <span class="navbar-toggler-icon"></span>
          </button>
          <div class="collapse navbar-collapse" id="topNavMenu">
            <ul class="navbar-nav mx-lg-auto">
              <li class="nav-item">
                <!-- Porta comunque a /timeline (la pagina resta la stessa, con lo stesso feed): solo
                     l'etichetta nel menu diventa "Home", più intuitiva come primo/punto di ingresso
                     rispetto a "Timeline" per chi non conosce già il sito. -->
                <a href="/<?= e($slug) ?>/timeline" class="nav-link<?= $activeKey === 'timeline' ? ' active' : '' ?>">Home</a>
              </li>
              <?php if ($cheAmoModules): ?>
              <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle<?= $activeKey === 'cheamo' ? ' active' : '' ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false">Che Amo</a>
                <ul class="dropdown-menu">
                  <li><a class="dropdown-item" href="/<?= e($slug) ?>/che-amo">Tutti</a></li>
                  <li><hr class="dropdown-divider"></li>
                  <?php foreach ($cheAmoModules as $m): ?>
                  <li><a class="dropdown-item" href="/<?= e($slug) ?>/<?= e($m['segment']) ?>"><i class="<?= e($m['icon']) ?> me-2" aria-hidden="true"></i><?= e($m['label']) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </li>
              <?php endif; ?>
              <?php foreach (['spotify', 'podcast', 'video', 'blog', 'menu', 'offerte', 'foto', 'servizi', 'eventi', 'contatti'] as $key): ?>
                <?php if (isset($navItems[$key])): ?>
              <li class="nav-item">
                <a href="<?= e($navItems[$key]['url']) ?>" class="nav-link<?= $activeKey === $key ? ' active' : '' ?>"><?= e($navItems[$key]['label']) ?></a>
              </li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ul>
            <ul class="navbar-nav align-items-lg-center">
              <?php if (empty($_SESSION['user_id'])): ?>
              <li class="nav-item"><a class="nav-link" href="/login.php"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Accedi</a></li>
              <li class="nav-item"><a class="nav-link" href="/register.php"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Registrati</a></li>
              <?php endif; ?>
              <li class="nav-item">
                <?php if ($isOwnProfile): ?>
                <a href="/dashboard.php" class="btn btn-primary btn-sm my-2 my-lg-0">
                  <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Dashboard
                </a>
                <?php elseif ($isFollowing): ?>
                <a href="/<?= e($slug) ?>#segui-widget" class="btn btn-outline-primary btn-sm my-2 my-lg-0">
                  <i class="bi bi-person-check-fill me-1" aria-hidden="true"></i>Segui già
                </a>
                <?php else: ?>
                <a href="/<?= e($slug) ?>#segui-widget" class="btn btn-primary btn-sm my-2 my-lg-0">
                  <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Segui
                </a>
                <?php endif; ?>
              </li>
            </ul>
          </div>
        </div>
      </nav>
    <?php
    return ob_get_clean();
}

// Colonna sinistra del profilo (avatar/follower/recensioni) — era solo nella Home, ora condivisa
// da OGNI pagina pubblica a tema AdminLTE (Timeline, Che Amo, Spotify...), così chi naviga dentro
// il profilo la vede sempre, non solo in Home. Self-contained: calcola da sola i dati che le
// servono a partire da $artist/$slug, così ogni pagina chiamante non deve ripetere le stesse
// query. Il menu di navigazione, "Segui" e Accedi/Registrati vivevano prima qui sotto le
// statistiche: sono passati alla barra in cima alla pagina (adminLteTopNav()) — qui resta solo
// l'identità.
function renderAdminLteProfileSidebar(array $artist, string $slug, bool $showFollowWidget = false): string {
    $uid = (int) $artist['id'];
    $db = getDB();

    $followerCount = getAccountFollowerCount($uid);
    $reviewStats = getBandRatingStats($uid);
    $contentCount = 0;
    $stmt = $db->prepare("SELECT COUNT(*) c FROM timeline_posts WHERE user_id=? AND visibility='public'");
    $stmt->execute([$uid]);
    $contentCount += (int) $stmt->fetch()['c'];
    $stmt = $db->prepare('SELECT COUNT(*) c FROM blog_posts WHERE user_id=? AND published_at <= NOW()');
    $stmt->execute([$uid]);
    $contentCount += (int) $stmt->fetch()['c'];

    $avatarUrl = adminLteAvatarUrl($artist);

    // "I miei link" e "Chi sono" vivevano nella colonna extra (renderAdminLteProfileExtras, a
    // destra insieme a "Che Amo"): spostati qui per bilanciare le due colonne laterali — a
    // sinistra tutto ciò che riguarda il profilo (identità, link, informazioni), a destra solo
    // "Che Amo".
    $stmt = $db->prepare('SELECT citta, provincia FROM profiles WHERE user_id=?');
    $stmt->execute([$uid]);
    $extra = $stmt->fetch() ?: [];
    $citta = trim($extra['citta'] ?? '');
    $provincia = trim($extra['provincia'] ?? '');
    $hasSpotify = !empty($artist['spotify_artist_id']);
    $hasYoutube = !empty($artist['youtube_channel_id']);
    $hasPodcast = !empty($artist['spotify_show_id']);

    $stmt = $db->prepare("SELECT id, label, url, cover_path, is_website_icon FROM links WHERE user_id=? AND is_active=1 AND link_type='link' ORDER BY sort_order ASC, id ASC LIMIT 6");
    $stmt->execute([$uid]);
    $links = $stmt->fetchAll();

    ob_start();
    ?>
          <div class="col-md-3 order-2 order-md-1">
            <div class="card widget-user-2 mb-0">
              <div class="widget-user-header text-bg-warning">
                <div class="widget-user-image">
                  <img class="rounded-circle shadow" src="<?= e($avatarUrl) ?>" alt="<?= e($artist['display_name']) ?>">
                </div>
                <div>
                  <h3 class="widget-user-username"><?= e($artist['display_name']) ?></h3>
                  <h5 class="widget-user-desc">@<?= e($slug) ?></h5>
                </div>
              </div>
              <div class="card-footer p-0">
                <ul class="nav flex-column">
                  <li class="nav-item">
                    <span class="nav-link link-body-emphasis">
                      Follower
                      <span class="float-end badge text-bg-primary"><?= (int) $followerCount ?></span>
                    </span>
                  </li>
                  <li class="nav-item">
                    <span class="nav-link link-body-emphasis">
                      Contenuti pubblicati
                      <span class="float-end badge text-bg-info"><?= (int) $contentCount ?></span>
                    </span>
                  </li>
                  <li class="nav-item">
                    <span class="nav-link link-body-emphasis">
                      Recensioni
                      <span class="float-end badge text-bg-success"><?= $reviewStats['count'] ? e((string) $reviewStats['avg']) . ' ★' : '–' ?></span>
                    </span>
                  </li>
                </ul>
              </div>
            </div>

            <?php if ($links): ?>
            <div class="card mt-3">
              <div class="card-header"><h3 class="card-title">I miei link</h3></div>
              <div class="list-group list-group-flush">
                <?php foreach ($links as $lk): ?>
                <?php
                    // Stessa icona per piattaforma già usata dal tema Colorful (detectPlatform()):
                    // Instagram, Spotify, YouTube ecc. riconosciuti dal dominio dell'URL. Un link
                    // segnato come "sito web personale" mostra sempre il globo, una copertina
                    // personalizzata (se caricata) sostituisce l'icona con una miniatura.
                    $platform = !empty($lk['is_website_icon'])
                        ? ['icon_class' => 'fa-solid fa-globe', 'label' => 'Sito web']
                        : (detectPlatform($lk['url']) ?? ['icon_class' => 'bi-link-45deg', 'label' => 'Link']);
                ?>
                <a href="/link.php?id=<?= (int) $lk['id'] ?>" target="_blank" rel="noopener" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                  <?php if (!empty($lk['cover_path'])): ?>
                    <img src="/<?= e($lk['cover_path']) ?>" alt="" class="rounded-circle" style="width:28px;height:28px;object-fit:cover;flex-shrink:0;">
                  <?php else: ?>
                    <span class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:28px;height:28px;">
                      <i class="<?= e($platform['icon_class']) ?> text-primary" style="font-size:.85rem;" aria-hidden="true" title="<?= e($platform['label']) ?>"></i>
                    </span>
                  <?php endif; ?>
                  <span class="flex-grow-1 text-truncate"><?= e($lk['label']) ?></span>
                  <i class="bi bi-chevron-right text-secondary small flex-shrink-0" aria-hidden="true"></i>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($artist['bio']) || $citta || !empty($artist['genere']) || $hasSpotify || $hasYoutube || $hasPodcast): ?>
            <div class="card mt-3">
              <div class="card-header"><h3 class="card-title">Chi sono</h3></div>
              <div class="card-body small">
                <?php if (!empty($artist['bio'])): ?>
                  <p class="fw-semibold mb-1"><i class="bi bi-person-lines-fill me-1 text-secondary" aria-hidden="true"></i>Bio</p>
                  <p class="text-secondary mb-3"><?= nl2br(e($artist['bio'])) ?></p>
                <?php endif; ?>
                <?php if ($citta): ?>
                  <p class="fw-semibold mb-1"><i class="bi bi-geo-alt me-1 text-secondary" aria-hidden="true"></i>Località</p>
                  <p class="text-secondary mb-3"><?= e($citta) ?><?= $provincia ? ', ' . e($provincia) : '' ?></p>
                <?php endif; ?>
                <?php if (!empty($artist['genere'])): ?>
                  <p class="fw-semibold mb-1"><i class="bi bi-tags me-1 text-secondary" aria-hidden="true"></i>Genere</p>
                  <p class="mb-3"><span class="badge text-bg-secondary"><?= e($artist['genere']) ?></span></p>
                <?php endif; ?>
                <?php if ($hasSpotify || $hasYoutube || $hasPodcast): ?>
                  <p class="fw-semibold mb-1"><i class="bi bi-link-45deg me-1 text-secondary" aria-hidden="true"></i>Presente su</p>
                  <p class="mb-0">
                    <?php if ($hasSpotify): ?><span class="badge text-bg-success me-1"><i class="bi bi-spotify me-1"></i>Spotify</span><?php endif; ?>
                    <?php if ($hasYoutube): ?><span class="badge text-bg-danger me-1"><i class="bi bi-youtube me-1"></i>YouTube</span><?php endif; ?>
                    <?php if ($hasPodcast): ?><span class="badge text-bg-warning"><i class="bi bi-mic-fill me-1"></i>Podcast</span><?php endif; ?>
                  </p>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($showFollowWidget && !in_array('segui', getHiddenNavKeys($uid), true)): ?>
            <div class="card mt-3" id="segui-widget">
              <div class="card-body text-center">
                <?php if (!empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] !== $uid): ?>
                  <?php $alreadyFollowing = isFollowingAccount((int) $_SESSION['user_id'], $uid); ?>
                  <form method="post" action="/follow_account.php" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <input type="hidden" name="action" value="<?= $alreadyFollowing ? 'unfollow' : 'follow' ?>">
                    <input type="hidden" name="redirect" value="/<?= e($slug) ?>">
                    <button type="submit" class="btn <?= $alreadyFollowing ? 'btn-outline-primary' : 'btn-primary' ?>">
                      <i class="bi <?= $alreadyFollowing ? 'bi-person-check-fill' : 'bi-person-plus' ?> me-1" aria-hidden="true"></i><?= $alreadyFollowing ? 'Segui già' : 'Segui' ?>
                    </button>
                  </form>
                  <div class="text-secondary small mt-2"><?= (int) $followerCount ?> ti seguono su <?= e(siteName()) ?></div>
                <?php else: ?>
                  <?php $followTermsContent = trim(getSiteSetting('follow_terms_content') ?: ''); ?>
                  <details id="segui-follow-details">
                    <summary class="btn btn-primary" style="list-style:none;cursor:pointer;">
                      <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Segui
                    </summary>
                    <div class="mt-3 text-start">
                      <p class="small text-secondary">Ti mandiamo un link di conferma via email: dopo averlo aperto ricevi un avviso ogni volta che <?= e($artist['display_name']) ?> pubblica qualcosa di nuovo.</p>
                      <form method="post" action="/follow.php" class="d-flex flex-wrap gap-2 align-items-start">
                        <?= csrfField() ?>
                        <input type="hidden" name="slug" value="<?= e($slug) ?>">
                        <input type="email" name="email" class="form-control form-control-sm flex-grow-1" placeholder="La tua email" required style="min-width:160px;">
                        <?php if ($followTermsContent !== ''): ?>
                        <label class="form-check d-flex align-items-start gap-2 w-100 small">
                          <input type="checkbox" name="accept_terms" value="1" class="form-check-input mt-1" required>
                          <span>Accetto i <a href="/termini_segui.php" target="_blank" rel="noopener">Termini di Utilizzo</a></span>
                        </label>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm">Conferma</button>
                      </form>
                    </div>
                  </details>
                  <div class="text-secondary small mt-2">
                    <?= $followerCount > 0 ? $followerCount . ($followerCount === 1 ? ' persona segue' : ' persone seguono') : 'ricevi una notifica quando pubblica' ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <script>
            (function () {
              // Sia il "+" del profilo sia "Segui" nella barra di navigazione puntano qui
              // (/slug#segui-widget), anche da un'altra pagina: se il modulo email esiste
              // (visitatore non loggato), lo apre subito e ci mette il focus, invece di lasciare
              // un secondo click per aprirlo dopo lo scroll.
              function openFollowWidget() {
                if (window.location.hash !== '#segui-widget') return;
                var details = document.getElementById('segui-follow-details');
                if (details && !details.open) {
                  details.open = true;
                  var emailInput = details.querySelector('input[name="email"]');
                  if (emailInput) { setTimeout(function () { emailInput.focus(); }, 50); }
                }
                var widget = document.getElementById('segui-widget');
                if (widget) { widget.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
              }
              document.addEventListener('DOMContentLoaded', openFollowWidget);
              window.addEventListener('hashchange', openFollowWidget);
            })();
            </script>
            <?php endif; ?>
          </div>
    <?php
    return ob_get_clean();
}

// Terza colonna condivisa da TUTTE le pagine pubbliche a tema AdminLTE: solo "Che Amo" (accesso
// rapido ai moduli con contenuto, stessa vetrina dietro al tab omonimo nella barra di
// navigazione). "I miei link" e "Chi sono" sono passati alla colonna del profilo
// (renderAdminLteProfileSidebar) per bilanciare le due colonne laterali. Stesso principio
// "self-contained" delle altre funzioni condivise di questo tema: calcola da sé i propri dati a
// partire da $artist['id']. L'ordine visivo (colonna profilo, poi contenuto, poi questa) è
// fissato via CSS (order-*), non dalla posizione nel markup: così può essere richiamata subito
// dopo la sidebar in ogni pagina, invece di dover trovare il punto esatto di chiusura della
// colonna centrale in ciascuna delle funzioni che la usano.
function renderAdminLteProfileExtras(array $artist, string $slug, string $extraCardsHtml = ''): string {
    $uid = (int) $artist['id'];
    $db = getDB();

    // Stessa regola di visibilità di che_amo.php (moduli non nascosti da "Menu di Navigazione"
    // e con contenuto effettivo): il widget qui sotto elenca esattamente le stesse voci che
    // compaiono sulla vetrina "Che Amo" dietro al tab omonimo, mai svuotato dalla barra
    // orizzontale (resta lì, questo è solo un accesso rapido in più dalla colonna laterale).
    // Ogni voce visibile porta anche il conteggio dei suoi elementi (es. "Attori che amo").
    $hiddenKeys = getHiddenNavKeys($uid);
    $cheAmoItems = [];
    if (!in_array('cheamo', $hiddenKeys, true)) {
        foreach (CHE_AMO_MODULES as $key => $m) {
            if (in_array($key, $hiddenKeys, true)) {
                continue;
            }
            if ($m['check'] === null || $m['check']($uid)) {
                $stmt = $db->prepare("SELECT COUNT(*) c FROM {$m['table']} WHERE user_id=?");
                $stmt->execute([$uid]);
                $m['count'] = (int) $stmt->fetch()['c'];
                $cheAmoItems[$key] = $m;
            }
        }
    }

    if (!$cheAmoItems && $extraCardsHtml === '') {
        return '';
    }

    ob_start();
    ?>
          <div class="col-md-3 order-3 order-md-3">
            <?php if ($extraCardsHtml !== ''): ?>
            <?= $extraCardsHtml ?>
            <?php endif; ?>
            <?php if ($cheAmoItems): ?>
            <div class="card<?= $extraCardsHtml !== '' ? ' mt-3' : '' ?>">
              <div class="card-header"><h3 class="card-title">Che Amo</h3></div>
              <div class="card-body">
                <?php $cheAmoColors = ['primary', 'success', 'warning', 'danger', 'info', 'secondary']; $i = 0; ?>
                <?php foreach ($cheAmoItems as $key => $m): ?>
                <a href="/<?= e($slug) ?>/<?= e($m['segment']) ?>" class="info-box text-decoration-none text-body<?= $i > 0 ? ' mt-2' : '' ?>">
                  <span class="info-box-icon text-bg-<?= $cheAmoColors[$i % count($cheAmoColors)] ?> shadow-sm">
                    <i class="bi <?= e(ADMINLTE_CHE_AMO_ICONS[$key] ?? 'bi-heart') ?>" aria-hidden="true"></i>
                  </span>
                  <div class="info-box-content">
                    <span class="info-box-text"><?= e($m['label']) ?></span>
                    <span class="info-box-number"><?= (int) $m['count'] ?></span>
                  </div>
                </a>
                <?php $i++; endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
          </div>
    <?php
    return ob_get_clean();
}

// Card "Categorie del blog" (colonna destra) — elenco alfabetico delle categorie dell'artista,
// ognuna verso la sua pagina pubblica filtrata (blogCategoryUrl()). Passata come $extraCardsHtml a
// renderAdminLteProfileExtras() (che ora la mostra per prima, con "Che Amo" subito sotto) su
// tutte e tre le pagine pubbliche del blog: indice, categoria e articolo. Nessuna card se
// l'artista non ha ancora categorie. $activeCategoryId evidenzia la categoria corrente quando la
// card compare sulla sua stessa pagina, invece di lasciarla indistinguibile dalle altre.
function renderAdminLteBlogCategoriesNavCard(int $userId, string $slug, ?int $activeCategoryId = null): string {
    $stmt = getDB()->prepare('SELECT id, name, slug FROM blog_categories WHERE user_id=? ORDER BY name ASC');
    $stmt->execute([$userId]);
    $categories = $stmt->fetchAll();
    if (!$categories) {
        return '';
    }
    ob_start();
    ?>
            <div class="card">
              <div class="card-header"><h3 class="card-title">Categorie del blog</h3></div>
              <div class="list-group list-group-flush">
                <?php foreach ($categories as $cat): ?>
                <?php $isActive = $activeCategoryId !== null && (int) $cat['id'] === $activeCategoryId; ?>
                <a href="<?= e(blogCategoryUrl($slug, $cat)) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2<?= $isActive ? ' active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                  <i class="bi bi-folder2<?= $isActive ? '' : ' text-secondary' ?>" aria-hidden="true"></i>
                  <span class="flex-grow-1"><?= e($cat['name']) ?></span>
                  <i class="bi bi-chevron-right<?= $isActive ? '' : ' text-secondary' ?> small" aria-hidden="true"></i>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
    <?php
    return ob_get_clean();
}

// Icona/colore per ogni "tipo" prodotto da getTimelineFeedForUsers() — usati dal widget .timeline
// reale di AdminLTE (UI/timeline.html), condiviso fra il tab Timeline della Home a tema AdminLTE e
// la pagina Timeline standalone dello stesso tema (vedi renderAdminLteTimelineRows()).
const ADMINLTE_TIMELINE_TYPE_META = [
    'pensiero' => ['icon' => 'bi-chat-text', 'color' => 'primary'],
    'blog' => ['icon' => 'bi-newspaper', 'color' => 'info'],
    'brano' => ['icon' => 'bi-music-note', 'color' => 'warning'],
    'evento' => ['icon' => 'bi-calendar-event', 'color' => 'success'],
    'offerta' => ['icon' => 'bi-tag', 'color' => 'danger'],
    'servizio' => ['icon' => 'bi-briefcase', 'color' => 'secondary'],
    'band_favorita' => ['icon' => 'bi-heart-pulse', 'color' => 'danger'],
    'attore_favorito' => ['icon' => 'bi-mask', 'color' => 'secondary'],
    'film_favorito' => ['icon' => 'bi-film', 'color' => 'info'],
    'libro_favorito' => ['icon' => 'bi-book', 'color' => 'primary'],
    'viaggio_favorito' => ['icon' => 'bi-airplane', 'color' => 'success'],
    'playlist_favorita' => ['icon' => 'bi-music-note-list', 'color' => 'warning'],
    'album_favorito' => ['icon' => 'bi-disc', 'color' => 'primary'],
    'album_foto' => ['icon' => 'bi-images', 'color' => 'secondary'],
    'ricetta_favorita' => ['icon' => 'bi-egg-fried', 'color' => 'warning'],
    'squadra_favorita' => ['icon' => 'bi-shield-fill', 'color' => 'success'],
    'calciatore_favorito' => ['icon' => 'bi-person-badge-fill', 'color' => 'primary'],
    'partita_favorita' => ['icon' => 'bi-calendar-event-fill', 'color' => 'warning'],
];

// Righe del widget .timeline reale di AdminLTE (etichette di data + item), senza il contenitore
// <div class="timeline"> né il tappo finale: usata sia per il primo carico che, tramite
// timeline_more.php, per le pagine successive dello scroll infinito della pagina Timeline
// standalone. $afterDay è l'ultima etichetta di data già mostrata in pagina, per non ripeterla se
// il primo elemento di questa chiamata cade nello stesso giorno; il valore restituito serve a far
// proseguire correttamente la chiamata successiva.
function renderAdminLteTimelineRows(array $items, ?string $afterDay = null): array {
    // Foto aggiuntive dei post "pensiero" con più foto (carosello) presenti in questa pagina di
    // risultati: un'unica query per tutte invece di una per post, stesso principio del conteggio
    // già fatto in getTimelineFeedForUsers().
    $extraPhotosByPost = [];
    $multiPhotoIds = [];
    foreach ($items as $it) {
        if (($it['tipo'] ?? '') === 'pensiero' && !empty($it['has_multi_photo']) && !empty($it['id'])) {
            $multiPhotoIds[] = (int) $it['id'];
        }
    }
    if ($multiPhotoIds) {
        $ph = implode(',', array_fill(0, count($multiPhotoIds), '?'));
        $stmt = getDB()->prepare("SELECT post_id, image_path FROM timeline_post_photos WHERE post_id IN ($ph) ORDER BY sort_order ASC, id ASC");
        $stmt->execute($multiPhotoIds);
        foreach ($stmt->fetchAll() as $r) {
            $extraPhotosByPost[(int) $r['post_id']][] = $r['image_path'];
        }
    }

    $lastDay = $afterDay;
    ob_start();
    foreach ($items as $it):
        $day = formatLocalDateTime($it['data'], ['dashboard_theme' => $it['owner_tz'] ?? null], 'd/m/Y');
        $meta = ADMINLTE_TIMELINE_TYPE_META[$it['tipo']] ?? ['icon' => 'bi-star', 'color' => 'primary'];
        if ($day !== $lastDay): $lastDay = $day; ?>
        <div class="time-label"><span class="text-bg-<?= $meta['color'] ?>"><?= e($day) ?></span></div>
        <?php endif; ?>
        <div>
          <i class="timeline-icon bi <?= e($meta['icon']) ?> text-bg-<?= $meta['color'] ?>"></i>
          <div class="timeline-item">
            <span class="time"><i class="bi bi-clock-fill"></i> <?= e(formatLocalDateTime($it['data'], ['dashboard_theme' => $it['owner_tz'] ?? null], 'H:i')) ?></span>
            <h3 class="timeline-header no-border"><a href="<?= e($it['url']) ?>"><?= e($it['titolo']) ?></a></h3>
            <?php if (!empty($it['cover'])):
              $itCoverUrl = str_starts_with($it['cover'], 'http') ? $it['cover'] : '/' . $it['cover'];
              $extraPhotos = $extraPhotosByPost[(int) ($it['id'] ?? 0)] ?? [];
            ?>
            <div class="timeline-body">
              <a href="<?= e($it['url']) ?>"><img src="<?= e($itCoverUrl) ?>" alt="" loading="lazy" style="width:140px;height:140px;object-fit:cover;border-radius:6px;margin:<?= $extraPhotos ? '0 6px 6px 0' : '0' ?>;"></a>
              <?php foreach ($extraPhotos as $extra):
                $extraUrl = str_starts_with($extra, 'http') ? $extra : '/' . $extra;
              ?>
              <a href="<?= e($it['url']) ?>"><img src="<?= e($extraUrl) ?>" alt="" loading="lazy" style="width:140px;height:140px;object-fit:cover;border-radius:6px;margin:0 6px 6px 0;"></a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
    <?php endforeach;
    return ['html' => ob_get_clean(), 'lastDay' => $lastDay];
}

// Widget .timeline completo (contenitore + tappo finale), per un elenco fisso non paginato — il
// tab Timeline della Home a tema AdminLTE mostra solo gli ultimi elementi, senza scroll infinito.
function renderAdminLteTimelineWidget(array $items): string {
    if (!$items) {
        return '<p class="text-secondary">Nessun aggiornamento ancora.</p>';
    }
    $rows = renderAdminLteTimelineRows($items);
    return '<div class="timeline">' . $rows['html'] . '<div><i class="timeline-icon bi bi-clock-fill text-bg-secondary"></i></div></div>';
}

// Contenuto della card Timeline con scroll infinito reale — condiviso fra la Home (che ora mostra
// la Timeline per intero, non più un'anteprima di 5 elementi in un tab) e la pagina Timeline
// standalone: stesso identico blocco, cambia solo il resto della pagina attorno (head/breadcrumb).
// Self-contained: fa da sola la prima query, come sidebar/nav.
function renderAdminLteTimelineFeedBlock(array $artist, string $slug): string {
    $uid = (int) $artist['id'];
    $pageSize = 20;
    $feed = getTimelineFeedForUsers([$uid], $pageSize, 0);
    $rows = renderAdminLteTimelineRows($feed);
    $finished = count($feed) < $pageSize;
    ob_start();
    ?>
                <?php if (!$feed): ?>
                  <p class="text-secondary">Nessun aggiornamento ancora.</p>
                <?php else: ?>
                  <div class="timeline" id="timeline-feed"><?= $rows['html'] ?></div>
                <?php endif; ?>
                <p id="timeline-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
                <p id="timeline-end" class="text-secondary text-center small" style="display:<?= ($finished && $feed) ? 'block' : 'none' ?>;">Hai visto tutto.</p>
                <div id="timeline-sentinel" style="height:1px;"></div>
                <script>
                (function () {
                  var slug = <?= json_encode($slug) ?>;
                  var offset = <?= (int) count($feed) ?>;
                  var pageSize = <?= (int) $pageSize ?>;
                  var loading = false;
                  var finished = <?= $finished ? 'true' : 'false' ?>;
                  var lastDay = <?= json_encode($rows['lastDay']) ?>;
                  var feedEl = document.getElementById('timeline-feed');
                  var loadingEl = document.getElementById('timeline-loading');
                  var endEl = document.getElementById('timeline-end');
                  var sentinel = document.getElementById('timeline-sentinel');

                  function loadMore() {
                    if (loading || finished || !feedEl) return;
                    loading = true;
                    loadingEl.style.display = 'block';
                    fetch('/timeline_more.php?slug=' + encodeURIComponent(slug) + '&offset=' + offset + '&after_day=' + encodeURIComponent(lastDay || ''))
                      .then(function (r) { return r.json(); })
                      .then(function (data) {
                        loadingEl.style.display = 'none';
                        if (data.html) { feedEl.insertAdjacentHTML('beforeend', data.html); }
                        if (data.lastDay) { lastDay = data.lastDay; }
                        offset += data.count;
                        if (data.count < pageSize) {
                          finished = true;
                          endEl.style.display = 'block';
                        }
                        loading = false;
                      })
                      .catch(function () {
                        loading = false;
                        loadingEl.style.display = 'none';
                      });
                  }

                  if (!finished && sentinel && 'IntersectionObserver' in window) {
                    var observer = new IntersectionObserver(function (entries) {
                      if (entries[0].isIntersecting) loadMore();
                    });
                    observer.observe(sentinel);
                  }
                })();
                </script>
    <?php
    return ob_get_clean();
}

// Script di scroll infinito generico, condiviso da tutte le pagine a elenco a tema AdminLTE
// (Blog, i 6 moduli Che Amo, Brani, Offerte, Servizi, Eventi) — si appoggia
// all'endpoint unico adminlte_list_more.php?type=$type, che sa come interrogare e disegnare
// ciascun tipo di elenco. Gli elementi con questi ID devono già esistere nella pagina chiamante:
// #adminlte-list-feed (contenitore in cui appendere), #adminlte-list-sentinel,
// #adminlte-list-loading, #adminlte-list-end. Viaggi (raggruppato per mese) e Foto (griglia con
// lightbox che indicizza tutte le immagini insieme) restano elenchi completi, non paginati: la
// paginazione ne romperebbe rispettivamente il raggruppamento e l'indice della lightbox.
function adminLteInfiniteScrollScript(string $type, string $slug, int $initialCount, int $pageSize, bool $finished): string {
    ob_start();
    ?>
                <script>
                (function () {
                  var type = <?= json_encode($type) ?>;
                  var slug = <?= json_encode($slug) ?>;
                  var offset = <?= (int) $initialCount ?>;
                  var pageSize = <?= (int) $pageSize ?>;
                  var loading = false;
                  var finished = <?= $finished ? 'true' : 'false' ?>;
                  var feedEl = document.getElementById('adminlte-list-feed');
                  var loadingEl = document.getElementById('adminlte-list-loading');
                  var endEl = document.getElementById('adminlte-list-end');
                  var sentinel = document.getElementById('adminlte-list-sentinel');

                  function loadMore() {
                    if (loading || finished || !feedEl) return;
                    loading = true;
                    if (loadingEl) loadingEl.style.display = 'block';
                    fetch('/adminlte_list_more.php?type=' + encodeURIComponent(type) + '&slug=' + encodeURIComponent(slug) + '&offset=' + offset)
                      .then(function (r) { return r.json(); })
                      .then(function (data) {
                        if (loadingEl) loadingEl.style.display = 'none';
                        if (data.html) { feedEl.insertAdjacentHTML('beforeend', data.html); }
                        offset += data.count;
                        if (data.count < pageSize) {
                          finished = true;
                          if (endEl) endEl.style.display = 'block';
                        }
                        loading = false;
                      })
                      .catch(function () {
                        loading = false;
                        if (loadingEl) loadingEl.style.display = 'none';
                      });
                  }

                  if (!finished && sentinel && 'IntersectionObserver' in window) {
                    var observer = new IntersectionObserver(function (entries) {
                      if (entries[0].isIntersecting) loadMore();
                    });
                    observer.observe(sentinel);
                  }
                })();
                </script>
    <?php
    return ob_get_clean();
}

// Righe/card di un elenco paginato a tema AdminLTE — funzioni "pure" (solo item -> HTML, senza
// contenitore) riusate sia dal primo caricamento di ciascuna pagina sia da
// adminlte_list_more.php per le pagine successive dello scroll infinito.

function renderAdminLteBlogRows(array $posts, string $slug, array $artist): string {
    $html = '';
    foreach ($posts as $p) {
        ob_start();
        ?>
              <a href="<?= e(blogPostUrl($slug, $p)) ?>" class="card mb-3 text-decoration-none text-body">
                <div class="d-flex">
                  <?php if ($p['cover_path']): ?>
                    <img src="/<?= e($p['cover_path']) ?>" alt="" loading="lazy" class="rounded-start adminlte-blog-thumb">
                  <?php else: ?>
                    <div class="bg-body-tertiary rounded-start d-flex align-items-center justify-content-center adminlte-blog-thumb">
                      <i class="bi bi-file-earmark-text fs-1 text-secondary" aria-hidden="true"></i>
                    </div>
                  <?php endif; ?>
                  <div class="card-body">
                    <h3 class="h5 mb-1"><?= e($p['title']) ?></h3>
                    <p class="text-secondary small mb-2"><?= e(formatLocalDateTime($p['published_at'], $artist)) ?></p>
                    <?php if ($p['excerpt']): ?><p class="mb-0 text-secondary"><?= e(textExcerpt($p['excerpt'], 160)) ?></p><?php endif; ?>
                  </div>
                </div>
              </a>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}

function renderAdminLteFanFavoriteRows(array $favorites, string $slug, string $kind, array $artist): string {
    $cfg = ADMINLTE_FAN_FAVORITE_KINDS[$kind];
    $shapeStyle = ['circle' => 'width:64px;height:64px;border-radius:50%;', 'book' => 'width:64px;height:88px;border-radius:6px;', 'square' => 'width:80px;height:80px;border-radius:10px;'][$cfg['image_shape']];
    $html = '';
    foreach ($favorites as $f) {
        $img = $f[$cfg['image_col']] ?? null;
        ob_start();
        ?>
                    <div class="col-6 col-sm-4 col-lg-3">
                      <a href="/<?= e($slug) ?>/<?= e($cfg['list_url_segment']) ?>/<?= (int) $f['id'] ?>" class="card text-decoration-none text-body p-3 h-100">
                        <?php if ($img): ?>
                          <img src="<?= e($img) ?>" alt="" loading="lazy" class="mx-auto mb-2 d-block" style="<?= $shapeStyle ?>object-fit:cover;">
                        <?php endif; ?>
                        <div class="fw-semibold small"><?= e($f[$cfg['name_col']]) ?></div>
                        <?php if ($kind === 'album' && !empty($f['album_artist_name'])): ?><div class="text-secondary" style="font-size:11.5px;"><?= e($f['album_artist_name']) ?></div><?php endif; ?>
                        <small class="text-secondary" style="font-size:11px;"><?= e(publishedAtLabel($f['publish_at'], $f['created_at'], $artist)) ?></small>
                      </a>
                    </div>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}

function renderAdminLteBraniRows(array $tracks, string $slug, array $artist): string {
    $html = '';
    foreach ($tracks as $t) {
        $trackStats = getTrackRatingStats((int) $t['id']);
        ob_start();
        ?>
              <div class="card mb-3">
                <div class="card-body d-flex align-items-center gap-3">
                  <a href="/<?= e($slug) ?>/brani/<?= (int) $t['id'] ?>/scheda" class="d-flex align-items-center gap-3 text-decoration-none text-body flex-grow-1" style="min-width:0;">
                    <?php if ($t['track_image']): ?>
                      <img src="<?= e($t['track_image']) ?>" alt="" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                    <?php else: ?>
                      <div class="bg-body-tertiary rounded" style="width:64px;height:64px;flex-shrink:0;"></div>
                    <?php endif; ?>
                    <div style="min-width:0;">
                      <strong class="d-block text-truncate"><?= e($t['track_name']) ?></strong>
                      <small class="text-secondary"><?= e($t['artist_name']) ?></small><br>
                      <small><?= renderCromeRating($trackStats['avg']) ?><?php if ($trackStats['count'] > 0): ?> <span class="text-secondary">(<?= $trackStats['count'] ?>)</span><?php endif; ?></small><br>
                      <small class="text-secondary"><?= e(publishedAtLabel($t['publish_at'], $t['created_at'], $artist)) ?></small>
                    </div>
                  </a>
                  <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <?php if (!empty($t['lyrics'])): ?><a href="/<?= e($slug) ?>/brani/<?= (int) $t['id'] ?>/testo" title="Testo e ascolto" class="text-decoration-none"><i class="bi bi-file-text fs-5"></i></a><?php endif; ?>
                    <a href="/<?= e($slug) ?>/brani/<?= (int) $t['id'] ?>/votazioni" title="Vota questo brano" class="text-decoration-none text-warning"><i class="bi bi-star-fill fs-5"></i></a>
                  </div>
                </div>
              </div>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}

function renderAdminLteOfferteRows(array $offers, string $slug, array $artist): string {
    $html = '';
    foreach ($offers as $of) {
        ob_start();
        ?>
              <a href="/<?= e($slug) ?>/offerte/<?= (int) $of['id'] ?>" class="card mb-3 text-decoration-none text-body">
                <div class="card-body d-flex align-items-center gap-3">
                  <?php if ($of['cover_path']): ?><img src="/<?= e($of['cover_path']) ?>" alt="" style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;"><?php endif; ?>
                  <div class="flex-grow-1" style="min-width:0;">
                    <strong><?= e($of['title']) ?></strong>
                    <?php if ($of['price_label']): ?><div class="text-primary fw-bold"><?= e($of['price_label']) ?></div><?php endif; ?>
                    <?php if ($of['valid_until']): ?><small class="text-secondary d-block mt-1">Valida fino al <?= e(formatLocalDateTime($of['valid_until'], $artist, 'd/m/Y')) ?></small><?php endif; ?>
                  </div>
                </div>
              </a>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}

function renderAdminLteServiziRows(array $services, string $slug): string {
    $html = '';
    foreach ($services as $sv) {
        ob_start();
        ?>
              <a href="/<?= e($slug) ?>/servizi/<?= (int) $sv['id'] ?>" class="card mb-3 text-decoration-none text-body">
                <div class="card-body d-flex align-items-center gap-3">
                  <?php if ($sv['cover_path']): ?><img src="/<?= e($sv['cover_path']) ?>" alt="" style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;"><?php endif; ?>
                  <div class="flex-grow-1" style="min-width:0;">
                    <strong><?= e($sv['title']) ?></strong>
                    <?php if ($sv['description']): ?><div class="text-secondary small mt-1"><?= e(textExcerpt($sv['description'], 90)) ?></div><?php endif; ?>
                    <?php if ((int) $sv['accepts_inquiries'] === 1): ?><small class="text-primary fw-semibold d-block mt-1"><i class="bi bi-envelope me-1"></i>Richiedi informazioni</small><?php endif; ?>
                  </div>
                </div>
              </a>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}

function renderAdminLteEventiRows(array $events, string $slug, array $artist): string {
    $html = '';
    foreach ($events as $ev) {
        $scheduleLabel = eventScheduleLabel($ev['recurrence'] ?? 'none', (bool) ($ev['is_perpetual'] ?? false));
        ob_start();
        ?>
              <a href="/<?= e($slug) ?>/eventi/<?= (int) $ev['id'] ?>" class="card mb-3 text-decoration-none text-body">
                <div class="card-body d-flex align-items-center gap-3">
                  <?php if ($ev['cover_path']): ?><img src="/<?= e($ev['cover_path']) ?>" alt="" style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;"><?php endif; ?>
                  <div class="flex-grow-1" style="min-width:0;">
                    <small class="text-secondary d-block"><?= e(formatLocalDateTime($ev['event_date'], $artist)) ?></small>
                    <strong><?= e($ev['title']) ?></strong>
                    <?php if ($ev['venue'] || $ev['city']): ?><small class="text-secondary d-block"><?= e($ev['venue']) ?><?= $ev['venue'] && $ev['city'] ? ', ' : '' ?><?= e($ev['city']) ?></small><?php endif; ?>
                    <?php if ($scheduleLabel): ?><small class="fw-semibold d-block"><i class="bi bi-arrow-repeat me-1"></i><?= e($scheduleLabel) ?></small><?php endif; ?>
                  </div>
                </div>
              </a>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}

// Avatar per le pagine pubbliche a tema AdminLTE: foto reale se presente, altrimenti iniziali su
// cerchio colorato (data URI, nessun file richiesto) — condiviso fra la Home e la pagina Timeline
// dello stesso tema.
function adminLteAvatarUrl(array $artist): string {
    if (!empty($artist['avatar_path'])) {
        return '/' . e($artist['avatar_path']);
    }
    $words = preg_split('/\s+/', trim($artist['display_name'] ?? ''));
    $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><circle cx="48" cy="48" r="48" fill="#6c5ce7"/><text x="48" y="61" font-family="Arial,Helvetica,sans-serif" font-size="32" font-weight="700" fill="white" text-anchor="middle">' . $initials . '</text></svg>');
}

// Tema grafico pubblico basato su AdminLTE 4 (CSS/JS reali forniti dall'utente) — sostituisce
// l'intera Home pubblica con una propria pagina completa, stesso principio isolato dei
// precedenti temi "a scena" (Giardino Anomalo, Scorrimento Infinito, ora rimossi): non tocca in
// alcun modo lo scheletro HTML condiviso dagli altri ~30 temi. Mostra la Timeline per intero (con
// scroll infinito, come la pagina standalone — vedi renderAdminLteTimelineFeedBlock()): le altre
// sezioni (Che Amo, Spotify, Blog...) non sono più anteprime qui dentro, sono voci del menu di
// navigazione nella colonna laterale (renderAdminLteProfileSidebar()) che portano alla pagina
// vera di ciascuna — click reale, niente più tab Bootstrap in-pagina (vedi cronologia di questa
// funzione per la versione precedente, con le anteprime).
function renderAdminLteProfileTheme(array $artist, string $slug): string {
    $pageUrl = siteUrl('/' . $slug);
    $ogDescription = !empty($artist['bio']) ? textExcerpt($artist['bio'], 160) : ($artist['display_name'] . ' su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<!-- Tema pubblico: palette fissa scelta dall'artista, non deve seguire il dark mode del
     visitatore (vedi stesso ragionamento fatto per il prototipo). -->
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if (!empty($artist['avatar_path'])): ?><meta property="og:image" content="<?= e(siteUrl('/' . $artist['avatar_path'])) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'timeline') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $artist['display_name']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug, true) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <?php if (!empty($_GET['follow_msg'])): ?>
            <div class="alert <?= !empty($_GET['follow_err']) ? 'alert-danger' : 'alert-success' ?> mb-3"><?= e($_GET['follow_msg']) ?></div>
            <?php endif; ?>
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Timeline') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <?= renderAdminLteTimelineFeedBlock($artist, $slug) ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Timeline" a tema AdminLTE — stesso principio "a scena" della Home (vedi
// renderAdminLteProfileTheme()), ma qui con lo scroll infinito reale della pagina Timeline del
// tema Colorful (timeline.php/timeline_more.php) invece della sola anteprima di 5 elementi
// mostrata nel tab Timeline della Home.
function renderAdminLteTimelinePage(array $artist, string $slug): string {
    $pageUrl = siteUrl('/' . $slug . '/timeline');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Timeline di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Timeline di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'timeline') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Timeline') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Timeline') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <?= renderAdminLteTimelineFeedBlock($artist, $slug) ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Singolo aggiornamento della Timeline" a tema AdminLTE — stesso principio "a
// scena" della Home. $post/$photos/$sameDayPosts arrivano già pronti da timeline_post.php (stessa
// forma usata dal tema Colorful): qui cambia solo il vestito, non le query. Il carosello foto
// (renderPhotoCarousel(), classi .ig-carousel/.ig-lightbox) è un componente a sé, con il proprio
// CSS/JS dedicato — funziona identico dentro la card AdminLTE.
function renderAdminLteTimelinePostPage(array $post, array $artist, string $slug, array $photos, array $sameDayPosts): string {
    $pageUrl = siteUrl('/' . $slug . '/timeline/' . (int) $post['id']);
    $avatarUrl = adminLteAvatarUrl($artist);
    $ogImagePath = (count($photos) > 1 && $post['image_path']) ? getFeedShareImage($post['image_path']) : $post['image_path'];
    $ogImage = $ogImagePath ? siteUrl($ogImagePath) : ($post['avatar_path'] ? siteUrl($post['avatar_path']) : null);
    $anteprima = $post['testo'] ? textExcerpt($post['testo'], 150) : ('Nuovo aggiornamento su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($post['custom_feed_guid'], $post['custom_feed_guid_since'], $post['created_at']); ?>
<title><?= e($post['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($anteprima) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($post['display_name']) ?> su <?= e(siteName()) ?>">
<meta property="og:description" content="<?= e($anteprima) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($post['display_name']) ?> su <?= e(siteName()) ?>">
<meta name="twitter:description" content="<?= e($anteprima) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if ($photos && (count($photos) > 1 || $sameDayPosts)): ?>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>">
<?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'timeline') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $post['display_name'], 'Aggiornamento', ['Timeline' => '/' . $slug . '/timeline']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Aggiornamento') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

                <div class="card mb-3">
                  <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <img src="<?= e($avatarUrl) ?>" class="rounded-circle" style="width:28px;height:28px;object-fit:cover;" alt="">
                      <span class="text-secondary small"><?= e($post['display_name']) ?> · <?= e(formatLocalDateTime($post['created_at'], $artist)) ?></span>
                    </div>
                    <?= renderPhotoCarousel($photos, (int) $post['id']) ?>
                    <?php if ($post['testo']): ?><p class="mb-0"><?= nl2br(e($post['testo'])) ?></p><?php endif; ?>
                  </div>
                </div>

                <?php if ($sameDayPosts): ?>
                  <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title h6 mb-0">Altri di questa giornata (<?= count($sameDayPosts) ?>)</h3></div>
                  </div>
                  <?php foreach ($sameDayPosts as $sp):
                    $spPhotos = array_values(array_filter(array_merge([$sp['image_path']], getTimelinePostPhotos((int) $sp['id']))));
                  ?>
                  <div class="card mb-3">
                    <div class="card-body">
                      <?= renderPhotoCarousel($spPhotos, (int) $sp['id']) ?>
                      <small class="text-secondary"><?= e(formatLocalDateTime($sp['created_at'], $artist)) ?></small>
                      <?php if ($sp['testo']): ?><p class="mb-0 mt-1"><?= nl2br(e($sp['testo'])) ?></p><?php endif; ?>
                    </div>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>

                <a href="/<?= e($slug) ?>/timeline" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Torna alla Timeline</a>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if ($photos && (count($photos) > 1 || $sameDayPosts)): ?>
<script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script>
<?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Blog" (elenco) a tema AdminLTE — stesso principio "a scena" della Home, elenco
// completo (non un'anteprima) come la pagina Blog del tema Colorful (blog_index.php).
function renderAdminLteBlogIndexPage(array $artist, string $slug, array $posts): string {
    $pageUrl = siteUrl('/' . $slug . '/blog');
    $avatarUrl = adminLteAvatarUrl($artist);
    $pageSize = 20;
    $finished = count($posts) < $pageSize;
    // Descrizione SEO: cita gli articoli più recenti quando ce ne sono, altrimenti resta generica
    // — sempre meglio del "Blog di X" ripetuto identico su ogni profilo, che Google tratterebbe
    // come contenuto duplicato tra loro.
    $blogDescription = $posts
        ? 'Blog di ' . $artist['display_name'] . ': ' . textExcerpt(implode(', ', array_column(array_slice($posts, 0, 5), 'title')), 155)
        : 'Il blog di ' . $artist['display_name'] . ' su ' . siteName() . '.';
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Blog di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($blogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Blog di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($blogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= blogListJsonLd($pageUrl, 'Blog di ' . $artist['display_name'], $blogDescription, $posts, $slug) ?>
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'blog') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Blog') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, renderAdminLteBlogCategoriesNavCard((int) $artist['id'], $slug)) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Blog') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

                <?php if (!$posts): ?>
                  <div class="card"><div class="card-body text-secondary">Nessun articolo pubblicato ancora.</div></div>
                <?php else: ?>
                  <div id="adminlte-list-feed"><?= renderAdminLteBlogRows($posts, $slug, $artist) ?></div>
                  <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
                  <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
                  <div id="adminlte-list-sentinel" style="height:1px;"></div>
                  <?= adminLteInfiniteScrollScript('blog', $slug, count($posts), $pageSize, $finished) ?>
                <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Categoria del blog" a tema AdminLTE — stesso elenco della pagina Blog
// (renderAdminLteBlogRows()), filtrato per una categoria. Niente scroll infinito qui (a
// differenza del Blog intero): un elenco filtrato è normalmente già abbastanza corto da non
// servire, evitando di dover insegnare anche a adminlte_list_more.php a filtrare per categoria.
function renderAdminLteBlogCategoryPage(array $artist, string $slug, array $category, array $posts): string {
    $pageUrl = siteUrl(blogCategoryUrl($slug, $category));
    $categoryDescription = 'Articoli della categoria "' . $category['name'] . '" nel blog di ' . $artist['display_name'] . '.';
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($category['name']) ?> — Blog di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($categoryDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($category['name']) ?> — Blog di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($categoryDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= blogListJsonLd($pageUrl, $category['name'] . ' — Blog di ' . $artist['display_name'], $categoryDescription, $posts, $slug) ?>
<?= breadcrumbJsonLd([
    ['name' => $artist['display_name'], 'url' => siteUrl('/' . $slug)],
    ['name' => 'Blog', 'url' => siteUrl('/' . $slug . '/blog')],
    ['name' => $category['name'], 'url' => $pageUrl],
]) ?>
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'blog') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $category['name'], ['Blog' => '/' . $slug . '/blog']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, renderAdminLteBlogCategoriesNavCard((int) $artist['id'], $slug, (int) $category['id'])) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e($category['name']) ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

                <p class="mb-3"><a href="/<?= e($slug) ?>/blog"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Tutti gli articoli</a></p>

                <?php if (!$posts): ?>
                  <div class="card"><div class="card-body text-secondary">Nessun articolo in questa categoria ancora.</div></div>
                <?php else: ?>
                  <?= renderAdminLteBlogRows($posts, $slug, $artist) ?>
                <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Articolo del blog" a tema AdminLTE — stesso principio "a scena" della Home.
// $post contiene sia le colonne di blog_posts sia quelle di profiles/users già unite dalla query
// di blog_post.php (stessa forma usata dal tema Colorful); $artist è l'array "adattatore" già
// costruito lì per riusare le funzioni condivise (embedTrackingHead ecc.).
function renderAdminLteBlogPostPage(array $post, array $artist, string $slug): string {
    $permalink = siteUrl(blogPostUrl($slug, $post));
    $ogImage = $post['cover_path'] ? siteUrl($post['cover_path']) : ($post['avatar_path'] ? siteUrl($post['avatar_path']) : null);
    $avatarUrl = adminLteAvatarUrl($artist);
    $postCategories = getBlogPostCategories((int) $post['id']);
    $linkedAlbum = null;
    if (!empty($post['album_id'])) {
        $albStmt = getDB()->prepare('SELECT id, title, cover_path FROM photo_albums WHERE id=? AND user_id=?');
        $albStmt->execute([$post['album_id'], $post['user_id']]);
        $linkedAlbum = $albStmt->fetch() ?: null;
    }
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($post['custom_feed_guid'], $post['custom_feed_guid_since'], $post['published_at']); ?>
<title><?= e($post['title']) ?> — <?= e($post['display_name']) ?></title>
<meta name="description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">

<meta property="og:type" content="article">
<meta property="og:title" content="<?= e($post['title']) ?>">
<meta property="og:description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">
<meta property="og:url" content="<?= e($permalink) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($post['title']) ?>">
<meta name="twitter:description" content="<?= e($post['excerpt'] ?: textExcerpt($post['content'])) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($permalink) ?>">
<?= blogPostingJsonLd($post, $post['display_name'], $permalink, $ogImage) ?>
<?= breadcrumbJsonLd([
    ['name' => $post['display_name'], 'url' => siteUrl('/' . $slug)],
    ['name' => 'Blog', 'url' => siteUrl('/' . $slug . '/blog')],
    ['name' => $post['title'], 'url' => $permalink],
]) ?>
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'blog') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Articolo', ['Blog' => '/' . $slug . '/blog']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug, renderAdminLteBlogCategoriesNavCard((int) $post['user_id'], $slug)) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Articolo') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

                <article class="card mb-3">
                  <?php if ($post['cover_path']): ?>
                    <img src="/<?= e($post['cover_path']) ?>" alt="<?= e($post['title']) ?>" class="card-img-top" style="max-height:360px;object-fit:cover;">
                  <?php endif; ?>
                  <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <img src="<?= e($avatarUrl) ?>" class="rounded-circle" style="width:28px;height:28px;object-fit:cover;" alt="">
                      <span class="text-secondary small"><?= e($post['display_name']) ?> · <?= e(formatLocalDateTime($post['published_at'], $artist)) ?></span>
                    </div>
                    <h2 class="h3 mb-3"><?= e($post['title']) ?></h2>
                    <?php if ($postCategories): ?>
                      <p class="mb-3">
                        <?php foreach ($postCategories as $cat): ?>
                          <a href="<?= e(blogCategoryUrl($slug, $cat)) ?>" class="badge text-bg-secondary text-decoration-none me-1"><?= e($cat['name']) ?></a>
                        <?php endforeach; ?>
                      </p>
                    <?php endif; ?>
                    <div><?= nl2br(e($post['content'])) ?></div>
                    <?php if ($post['tags']): ?>
                      <p class="text-secondary small mt-3 mb-0"><i class="bi bi-tags me-1" aria-hidden="true"></i><?= e($post['tags']) ?></p>
                    <?php endif; ?>
                  </div>
                </article>

                <?php if ($linkedAlbum): ?>
                <a href="/<?= e($slug) ?>/album/<?= (int) $linkedAlbum['id'] ?>" class="card mb-3 text-decoration-none text-body">
                  <div class="d-flex align-items-center gap-3 p-3">
                    <?php if ($linkedAlbum['cover_path']): ?>
                      <img src="/<?= e($linkedAlbum['cover_path']) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;" alt="">
                    <?php endif; ?>
                    <div>
                      <small class="text-secondary d-block"><i class="bi bi-images me-1" aria-hidden="true"></i>Album collegato</small>
                      <strong><?= e($linkedAlbum['title']) ?></strong>
                    </div>
                  </div>
                </a>
                <?php endif; ?>

                <div class="card mb-3">
                  <div class="card-body">
                    <strong>Condividi questo articolo</strong><br>
                    <small class="text-secondary"><?= e($permalink) ?></small>
                  </div>
                </div>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Vetrina "Che Amo" a tema AdminLTE — griglia di card verso i moduli attivi (uguale a che_amo.php
// del tema Colorful, con le icone Bootstrap già usate nel tab Che Amo della Home).
function renderAdminLteCheAmoIndexPage(array $artist, string $slug, array $visibleModules): string {
    $pageUrl = siteUrl('/' . $slug . '/che-amo');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Che Amo — <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Che Amo — <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Che Amo') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Che Amo') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <?php if ($visibleModules): ?>
                <div class="row g-3 text-center">
                  <?php foreach ($visibleModules as $mKey => $m): ?>
                    <div class="col-6 col-sm-4 col-lg-3">
                      <a href="/<?= e($slug) ?>/<?= e($m['segment']) ?>" class="card text-decoration-none text-body p-3 h-100">
                        <div class="fs-2 text-primary mb-2"><i class="bi <?= e(ADMINLTE_CHE_AMO_ICONS[$mKey] ?? 'bi-heart') ?>" aria-hidden="true"></i></div>
                        <div class="fw-semibold small"><?= e($m['label']) ?></div>
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                  <div class="card"><div class="card-body text-secondary">Nessun contenuto ancora.</div></div>
                <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Elenco pubblico di un modulo "che amo" (Band/Attori/Film/Libri/Playlist/Album) a tema AdminLTE —
// una sola funzione generica al posto di 6 file quasi identici, guidata da ADMINLTE_FAN_FAVORITE_KINDS
// (stesso principio di configurazione già usato dal tema Colorful in fan_favorite_item.php).
function renderAdminLteFanFavoriteListPage(array $artist, string $slug, array $favorites, string $kind): string {
    $cfg = ADMINLTE_FAN_FAVORITE_KINDS[$kind];
    $pageUrl = siteUrl('/' . $slug . '/' . $cfg['list_url_segment']);
    $pageSize = 20;
    $finished = count($favorites) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $cfg['label'], ['Che Amo' => '/' . $slug . '/che-amo']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e($cfg['label']) ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <?php if ($favorites): ?>
                <div class="row g-3 text-center" id="adminlte-list-feed"><?= renderAdminLteFanFavoriteRows($favorites, $slug, $kind, $artist) ?></div>
                <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
                <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
                <div id="adminlte-list-sentinel" style="height:1px;"></div>
                <?= adminLteInfiniteScrollScript($kind, $slug, count($favorites), $pageSize, $finished) ?>
                <?php else: ?>
                  <div class="card"><div class="card-body text-secondary">Nessun elemento aggiunto ancora.</div></div>
                <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Dettaglio pubblico di un singolo elemento "che amo" a tema AdminLTE — stessa funzione generica
// riusata dai 6 moduli con API esterna, guidata da ADMINLTE_FAN_FAVORITE_KINDS. $apiDetails arriva
// già pronto dal chiamante (fan_favorite_item.php), che sa quale funzione dell'API chiamare per
// ciascun $kind.
function renderAdminLteFanFavoriteDetailPage(array $artist, string $slug, string $kind, array $item, array $sameDayItems, ?array $apiDetails, bool $isOwner = false, bool $isScheduledFuture = false): string {
    $cfg = ADMINLTE_FAN_FAVORITE_KINDS[$kind];
    $name = $item[$cfg['name_col']];
    $image = $item['image_path'] ?: ($item[$cfg['image_col']] ?? null);
    $imageUrl = $image ? (str_starts_with($image, 'http') ? $image : siteUrl($image)) : null;
    $note = trim($item['note'] ?? '');
    // Le ricette non hanno una pagina Spoonacular canonica raggiungibile solo dall'id: il link
    // migliore è quello del sito originale della ricetta (sourceUrl, dai dettagli live
    // dell'API) — l'URL fisso di Spoonacular resta solo un ripiego per quando l'API non risponde.
    $externalUrl = ($kind === 'recipe' && !empty($apiDetails['source_url']))
        ? $apiDetails['source_url']
        : $cfg['external_url'] . $item[$cfg['external_id_col']];
    $shapeStyle = ['circle' => 'width:160px;height:160px;border-radius:50%;', 'book' => 'width:140px;height:190px;border-radius:8px;', 'square' => 'width:170px;height:170px;border-radius:14px;'][$cfg['image_shape']];
    $pageUrl = siteUrl('/' . $slug . '/' . $cfg['list_url_segment'] . '/' . (int) $item['id']);
    $ogDescription = $note !== '' ? $note : ($apiDetails['biography'] ?? $apiDetails['overview'] ?? ($artist['display_name'] . ' ama ' . $name . ' — scoprilo su ' . siteName()));
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'] ?? null, $artist['custom_feed_guid_since'] ?? null, $item['created_at']); ?>
<title><?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?><meta property="og:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<?php if ($imageUrl): ?><meta name="twitter:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Scheda', ['Che Amo' => '/' . $slug . '/che-amo', $cfg['label'] => '/' . $slug . '/' . $cfg['list_url_segment']]) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Scheda') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($isOwner && (!(int) $item['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo elemento non è visibile al pubblico al momento (Solo io, o programmato per il futuro) — lo vedi solo tu, come proprietario del profilo.</div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($imageUrl): ?><img src="<?= e($imageUrl) ?>" alt="<?= e($name) ?>" class="mb-3" style="<?= $shapeStyle ?>object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);"><?php endif; ?>
                <h1 class="h4 mb-1"><?= e($name) ?></h1>
                <p class="text-secondary">
                  <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>
                  <?php if (!empty($apiDetails['authors'])): ?> · <?= e($apiDetails['authors']) ?><?php endif; ?>
                  <?php if ($kind === 'album' && !empty($item['album_artist_name'])): ?> · <?= e($item['album_artist_name']) ?><?php endif; ?>
                  <?php if ($kind === 'playlist' && !empty($apiDetails['owner'])): ?> · di <?= e($apiDetails['owner']) ?> su Spotify<?php endif; ?>
                  <?php if (!empty($apiDetails['tracks_total'])): ?> · <?= (int) $apiDetails['tracks_total'] ?> brani<?php endif; ?>
                  <?php if (!empty($apiDetails['release_date'])): ?> · <?= e(substr($apiDetails['release_date'], 0, 4)) ?><?php endif; ?>
                  <?php if (!empty($apiDetails['known_for_department'])): ?> · <?= e($apiDetails['known_for_department']) ?><?php endif; ?>
                  <?php if ($kind === 'recipe' && !empty($apiDetails['ready_in_minutes'])): ?> · <?= (int) $apiDetails['ready_in_minutes'] ?> min<?php endif; ?>
                  <?php if ($kind === 'recipe' && !empty($apiDetails['servings'])): ?> · <?= (int) $apiDetails['servings'] ?> porzioni<?php endif; ?>
                  <?php if ($kind === 'team' && !empty($apiDetails['league'])): ?> · <?= e($apiDetails['league']) ?><?php endif; ?>
                  <?php if ($kind === 'team' && !empty($apiDetails['country'])): ?> · <?= e($apiDetails['country']) ?><?php endif; ?>
                  <?php if ($kind === 'team' && !empty($apiDetails['founded_year'])): ?> · dal <?= e($apiDetails['founded_year']) ?><?php endif; ?>
                  <?php if ($kind === 'footballer' && !empty($apiDetails['team'])): ?> · <?= e($apiDetails['team']) ?><?php endif; ?>
                  <?php if ($kind === 'footballer' && !empty($apiDetails['position'])): ?> · <?= e($apiDetails['position']) ?><?php endif; ?>
                  <?php if ($kind === 'footballer' && !empty($apiDetails['nationality'])): ?> · <?= e($apiDetails['nationality']) ?><?php endif; ?>
                  <?php if ($kind === 'match' && !empty($apiDetails['league'])): ?> · <?= e($apiDetails['league']) ?><?php endif; ?>
                  <?php if ($kind === 'match' && !empty($apiDetails['venue'])): ?> · <?= e($apiDetails['venue']) ?><?php endif; ?>
                </p>
                <small class="text-secondary"><?= e(publishedAtLabel($item['publish_at'], $item['created_at'], $artist)) ?></small>
                <?php if ($kind === 'album' && !empty($apiDetails['genres'])): ?><p class="mt-1 fst-italic"><?= e(implode(', ', $apiDetails['genres'])) ?></p><?php endif; ?>
                <?php if ($kind === 'playlist' && !empty($apiDetails['description'])): ?><p class="text-start mt-2"><?= nl2br(e(strip_tags($apiDetails['description']))) ?></p><?php endif; ?>
                <?php if ($note !== ''): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Perché <?= e($artist['display_name']) ?> lo ama</strong><p class="mb-0 mt-1"><?= nl2br(e($note)) ?></p></div></div>
                <?php endif; ?>
                <?php if ($kind !== 'album' && !empty($apiDetails['genres'])): ?><p class="mt-3 fst-italic"><?= e(implode(', ', $apiDetails['genres'])) ?></p><?php endif; ?>
                <?php if (!empty($apiDetails['overview'])): ?>
                  <p class="text-start mt-2"><?= nl2br(e($apiDetails['overview'])) ?></p>
                <?php elseif (!empty($apiDetails['biography'])): ?>
                  <p class="text-start mt-2"><?= nl2br(e(textExcerpt($apiDetails['biography'], 500))) ?></p>
                <?php endif; ?>
                <?php if ($kind === 'recipe' && !empty($apiDetails['ingredients'])): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Ingredienti</strong>
                    <ul class="mb-0 mt-1">
                      <?php foreach ($apiDetails['ingredients'] as $ing): ?><li><?= e($ing) ?></li><?php endforeach; ?>
                    </ul>
                  </div></div>
                <?php endif; ?>
                <?php if ($kind === 'team' && !empty($apiDetails['stadium'])): ?>
                  <p class="text-secondary small mt-2 mb-0"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= e($apiDetails['stadium']) ?></p>
                <?php endif; ?>
                <?php if ($kind === 'team' && !empty($apiDetails['next_event'])): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Prossima partita</strong><p class="mb-0 mt-1"><?= e($apiDetails['next_event']) ?></p></div></div>
                <?php endif; ?>
                <?php if ($kind === 'footballer' && !empty($apiDetails['born'])): ?>
                  <?php // Data di nascita pura (senza orario): niente conversione di fuso orario (formatLocalDateTime()) qui, sposterebbe il giorno per i profili con fuso molto indietro rispetto al server. ?>
                  <p class="text-secondary small mt-2 mb-0"><i class="bi bi-balloon-heart me-1" aria-hidden="true"></i>Nato il <?= e(date('d/m/Y', strtotime($apiDetails['born']))) ?></p>
                <?php endif; ?>
                <?php if ($kind === 'match'): ?>
                  <div class="card text-center mt-3"><div class="card-body">
                    <?php if (!empty($apiDetails['result'])): ?>
                      <div class="h4 mb-1"><?= e($apiDetails['home_team']) ?> <?= e($apiDetails['result']) ?> <?= e($apiDetails['away_team']) ?></div>
                    <?php else: ?>
                      <div class="text-secondary">Partita in programma</div>
                    <?php endif; ?>
                    <?php if (!empty($apiDetails['match_date'])): ?><small class="text-secondary"><?= e(trim($apiDetails['match_date'])) ?></small><?php endif; ?>
                  </div></div>
                <?php endif; ?>
                <p class="mt-3"><a href="<?= e($externalUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><?= e($cfg['external_label']) ?></a></p>
              </div>
            </div>

            <?php if ($sameDayItems): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title h6 mb-0">Altri di questa giornata (<?= count($sameDayItems) ?>)</h3></div>
              </div>
              <?php foreach ($sameDayItems as $s):
                $sName = $s[$cfg['name_col']];
                $sImage = $s['image_path'] ?: ($s[$cfg['image_col']] ?? null);
                $sImageUrl = $sImage ? (str_starts_with($sImage, 'http') ? $sImage : siteUrl($sImage)) : null;
                $sNote = trim($s['note'] ?? '');
                $sExternalUrl = $cfg['external_url'] . $s[$cfg['external_id_col']];
              ?>
              <div class="card mb-3">
                <div class="card-body text-center">
                  <a href="/<?= e($slug) ?>/<?= e($cfg['list_url_segment']) ?>/<?= (int) $s['id'] ?>" class="text-decoration-none text-body">
                    <?php if ($sImageUrl): ?><img src="<?= e($sImageUrl) ?>" alt="<?= e($sName) ?>" class="mb-2" style="<?= $shapeStyle ?>object-fit:cover;"><?php endif; ?>
                    <h4 class="h6 mb-1"><?= e($sName) ?></h4>
                    <small class="text-secondary"><?= e(publishedAtLabel($s['publish_at'], $s['created_at'], $artist)) ?></small>
                  </a>
                  <?php if ($sNote !== ''): ?><p class="text-start mt-2 mb-0 text-secondary"><?= nl2br(e($sNote)) ?></p><?php endif; ?>
                  <p class="mt-2 mb-0"><a href="<?= e($sExternalUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><?= e($cfg['external_label']) ?></a></p>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Elenco pubblico "Viaggi" a tema AdminLTE, raggruppato per mese come viaggi.php del tema Colorful.
function renderAdminLteViaggiListPage(array $artist, string $slug, array $monthGroups, int $totalCount): string {
    $pageUrl = siteUrl('/' . $slug . '/viaggi');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Viaggi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Viaggi di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Viaggi (' . $totalCount . ')', ['Che Amo' => '/' . $slug . '/che-amo']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Viaggi (' . $totalCount . ')') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($monthGroups): ?>
              <?php foreach ($monthGroups as $group): ?>
                <div class="card mt-3 mb-2">
                  <div class="card-header"><h3 class="card-title h6 mb-0"><?= e($group['label']) ?></h3></div>
                </div>
                <div class="row g-3 text-center mb-2">
                  <?php foreach ($group['items'] as $f): $thumb = $f['image_path'] ?: $f['map_image_path']; ?>
                    <div class="col-6 col-sm-4 col-lg-3">
                      <a href="/<?= e($slug) ?>/viaggi/<?= (int) $f['id'] ?>" class="card text-decoration-none text-body p-2 h-100">
                        <?php if ($thumb): ?><img src="/<?= e($thumb) ?>" alt="" loading="lazy" class="mb-2 rounded" style="width:100%;height:88px;object-fit:cover;"><?php endif; ?>
                        <div class="fw-semibold small"><?= e($f['place_name']) ?></div>
                        <small class="text-secondary" style="font-size:11px;"><?= e(publishedAtLabel($f['publish_at'], $f['created_at'], $artist)) ?></small>
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Nessun viaggio aggiunto ancora.</div></div>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Dettaglio pubblico di un singolo viaggio a tema AdminLTE — carosello foto + mappa OpenStreetMap
// (renderPhotoCarousel()/renderOsmEmbed() sono componenti a sé, invariati rispetto al Colorful).
function renderAdminLteViaggioDetailPage(array $artist, string $slug, array $trip, array $photos, array $sameDayItems, bool $isOwner = false, bool $isScheduledFuture = false): string {
    $note = trim($trip['note'] ?? '');
    $image = (count($photos) > 1) ? getFeedShareImage($trip['image_path']) : ($trip['image_path'] ?: $trip['map_image_path']);
    $imageUrl = $image ? siteUrl($image) : null;
    $pageUrl = siteUrl('/' . $slug . '/viaggi/' . (int) $trip['id']);
    $ogDescription = $note !== '' ? $note : ($artist['display_name'] . ' è stato a ' . $trip['place_name'] . ' — scoprilo su ' . siteName());
    $anyMultiPhoto = count($photos) > 1;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'] ?? null, $artist['custom_feed_guid_since'] ?? null, $trip['created_at']); ?>
<title><?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?><meta property="og:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($trip['place_name']) ?> — Viaggi di <?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<?php if ($imageUrl): ?><meta name="twitter:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if ($anyMultiPhoto): ?><link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>"><?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Viaggio', ['Che Amo' => '/' . $slug . '/che-amo', 'Viaggi' => '/' . $slug . '/viaggi']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Viaggio') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($isOwner && (!(int) $trip['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo viaggio non è visibile al pubblico al momento (Solo io, o programmato per il futuro) — lo vedi solo tu, come proprietario del profilo.</div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?= renderPhotoCarousel($photos, (int) $trip['id']) ?>
                <h1 class="h4 mb-1"><?= e($trip['place_name']) ?></h1>
                <p class="text-secondary">
                  Viaggio di <?= e($artist['display_name']) ?>
                  <?php if (!empty($trip['address']) && $trip['address'] !== $trip['place_name']): ?> · <?= e($trip['address']) ?><?php endif; ?>
                </p>
                <small class="text-secondary"><?= e(publishedAtLabel($trip['publish_at'], $trip['created_at'], $artist)) ?></small>
                <?php if ($note !== ''): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Il racconto di <?= e($artist['display_name']) ?></strong><p class="mb-0 mt-1"><?= nl2br(e($note)) ?></p></div></div>
                <?php endif; ?>
                <div class="mt-3"><?= renderOsmEmbed((float) $trip['lat'], (float) $trip['lng']) ?></div>
              </div>
            </div>

            <?php if ($sameDayItems): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title h6 mb-0">Altri di questa giornata (<?= count($sameDayItems) ?>)</h3></div>
              </div>
              <?php foreach ($sameDayItems as $s):
                $sNote = trim($s['note'] ?? '');
                $sPhotos = $s['image_path'] ? array_values(array_filter(array_merge([$s['image_path']], getTripPhotos((int) $s['id'])))) : [];
                if (count($sPhotos) > 1) { $anyMultiPhoto = true; }
              ?>
              <div class="card mb-3">
                <div class="card-body text-center">
                  <?= renderPhotoCarousel($sPhotos, (int) $s['id']) ?>
                  <a href="/<?= e($slug) ?>/viaggi/<?= (int) $s['id'] ?>" class="text-decoration-none text-body">
                    <h4 class="h6 mb-1"><?= e($s['place_name']) ?></h4>
                    <p class="text-secondary mb-1">
                      Viaggio di <?= e($artist['display_name']) ?>
                      <?php if (!empty($s['address']) && $s['address'] !== $s['place_name']): ?> · <?= e($s['address']) ?><?php endif; ?>
                    </p>
                    <small class="text-secondary"><?= e(publishedAtLabel($s['publish_at'], $s['created_at'], $artist)) ?></small>
                  </a>
                  <?php if ($sNote !== ''): ?><p class="text-start mt-2 mb-0 text-secondary"><?= nl2br(e($sNote)) ?></p><?php endif; ?>
                  <div class="mt-2"><?= renderOsmEmbed((float) $s['lat'], (float) $s['lng']) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if ($anyMultiPhoto): ?><script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Elenco pubblico "Brani che amo" a tema AdminLTE — lista, non griglia, come brani.php del tema
// Colorful (ogni riga ha anche i link a testo/voto).
function renderAdminLteBraniListPage(array $artist, string $slug, array $tracks): string {
    $pageUrl = siteUrl('/' . $slug . '/brani');
    $pageSize = 20;
    $finished = count($tracks) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Brani che amo di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Brani che amo di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Brani che amo', ['Che Amo' => '/' . $slug . '/che-amo']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Brani che amo') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (!$tracks): ?>
              <div class="card"><div class="card-body text-secondary">Nessun brano aggiunto ancora.</div></div>
            <?php else: ?>
              <div id="adminlte-list-feed"><?= renderAdminLteBraniRows($tracks, $slug, $artist) ?></div>
              <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
              <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
              <div id="adminlte-list-sentinel" style="height:1px;"></div>
              <?= adminLteInfiniteScrollScript('brani', $slug, count($tracks), $pageSize, $finished) ?>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Dettaglio pubblico ("scheda") di un singolo brano che amo a tema AdminLTE — favorite_track_item.php.
function renderAdminLteFavoriteTrackDetailPage(array $artist, string $slug, array $track, array $sameDayItems, bool $isOwner = false, bool $isScheduledFuture = false): string {
    $note = trim($track['note'] ?? '');
    $image = $track['image_path'] ?: $track['track_image'];
    $imageUrl = $image ? (str_starts_with($image, 'http') ? $image : siteUrl($image)) : null;
    $pageUrl = siteUrl('/' . $slug . '/brani/' . (int) $track['id'] . '/scheda');
    $ogDescription = $note !== '' ? $note : ($artist['display_name'] . ' ama "' . $track['track_name'] . '" — scoprilo su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'] ?? null, $artist['custom_feed_guid_since'] ?? null, $track['created_at']); ?>
<title><?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:type" content="music.song">
<meta property="og:title" content="<?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?><meta property="og:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($track['track_name']) ?> — Brani che amo di <?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<?php if ($imageUrl): ?><meta name="twitter:image" content="<?= e($imageUrl) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Scheda', ['Che Amo' => '/' . $slug . '/che-amo', 'Brani che amo' => '/' . $slug . '/brani']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Scheda') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($isOwner && (!(int) $track['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo brano non è visibile al pubblico al momento (Solo io, o programmato per il futuro) — lo vedi solo tu, come proprietario del profilo.</div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($imageUrl): ?><img src="<?= e($imageUrl) ?>" alt="<?= e($track['track_name']) ?>" class="mb-3 rounded-4" style="width:220px;height:220px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);"><?php endif; ?>
                <h1 class="h4 mb-1"><?= e($track['track_name']) ?></h1>
                <p class="text-secondary">Brano che amo di <?= e($artist['display_name']) ?><?php if ($track['artist_name']): ?> · <?= e($track['artist_name']) ?><?php endif; ?></p>
                <small class="text-secondary"><?= e(publishedAtLabel($track['publish_at'], $track['created_at'], $artist)) ?></small>
                <?php if ($note !== ''): ?>
                  <div class="card text-start mt-3"><div class="card-body"><strong>Perché <?= e($artist['display_name']) ?> lo ama</strong><p class="mb-0 mt-1"><?= nl2br(e($note)) ?></p></div></div>
                <?php endif; ?>
                <div class="d-flex gap-2 justify-content-center flex-wrap mt-3">
                  <?php if ($track['spotify_url']): ?><a href="<?= e($track['spotify_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary">Ascolta su Spotify</a><?php endif; ?>
                  <?php if (!empty($track['lyrics'])): ?><a href="/<?= e($slug) ?>/brani/<?= (int) $track['id'] ?>/testo" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-text me-1"></i>Testo</a><?php endif; ?>
                  <a href="/<?= e($slug) ?>/brani/<?= (int) $track['id'] ?>/votazioni" class="btn btn-sm btn-outline-primary"><i class="bi bi-star-fill me-1"></i>Vota</a>
                </div>
              </div>
            </div>

            <?php if ($sameDayItems): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title h6 mb-0">Altri di questa giornata (<?= count($sameDayItems) ?>)</h3></div>
              </div>
              <?php foreach ($sameDayItems as $s):
                $sImage = $s['image_path'] ?: $s['track_image'];
                $sImageUrl = $sImage ? (str_starts_with($sImage, 'http') ? $sImage : siteUrl($sImage)) : null;
                $sNote = trim($s['note'] ?? '');
              ?>
              <div class="card mb-3">
                <div class="card-body text-center">
                  <a href="/<?= e($slug) ?>/brani/<?= (int) $s['id'] ?>/scheda" class="text-decoration-none text-body">
                    <?php if ($sImageUrl): ?><img src="<?= e($sImageUrl) ?>" alt="" class="mb-2 rounded-4" style="width:220px;height:220px;object-fit:cover;"><?php endif; ?>
                    <h4 class="h6 mb-1"><?= e($s['track_name']) ?></h4>
                    <p class="text-secondary mb-1">Brano che amo di <?= e($artist['display_name']) ?><?php if ($s['artist_name']): ?> · <?= e($s['artist_name']) ?><?php endif; ?></p>
                    <small class="text-secondary"><?= e(publishedAtLabel($s['publish_at'], $s['created_at'], $artist)) ?></small>
                  </a>
                  <?php if ($sNote !== ''): ?><p class="text-start mt-2 mb-0 text-secondary"><?= nl2br(e($sNote)) ?></p><?php endif; ?>
                  <?php if ($s['spotify_url']): ?><p class="mt-2 mb-0"><a href="<?= e($s['spotify_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Ascolta su Spotify</a></p><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Testo e ascolto" di un brano che amo a tema AdminLTE — track_lyrics.php.
function renderAdminLteTrackLyricsPage(array $artist, string $slug, array $track, array $stats): string {
    $pageUrl = siteUrl('/' . $slug . '/brani/' . (int) $track['id'] . '/testo');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($track['track_name']) ?> — Testo e ascolto — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($track['track_name']) ?> — Testo e ascolto">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if ($track['track_image']): ?><meta property="og:image" content="<?= e($track['track_image']) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Testo e ascolto', ['Che Amo' => '/' . $slug . '/che-amo', 'Brani che amo' => '/' . $slug . '/brani']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Testo e ascolto') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($track['track_image']): ?><img src="<?= e($track['track_image']) ?>" class="mb-2 rounded-3" style="width:96px;height:96px;object-fit:cover;"><?php endif; ?>
                <h1 class="h5 mb-0"><?= e($track['track_name']) ?></h1>
                <p class="text-secondary mb-1"><?= e($track['artist_name']) ?></p>
                <?php if ($stats['count'] > 0): ?><p class="mb-0"><?= renderCromeRating($stats['avg']) ?> <span class="text-secondary small">(<?= $stats['count'] ?>)</span></p><?php endif; ?>
              </div>
            </div>

            <?php if ($track['spotify_track_id']): ?>
            <div class="card mb-3 p-0" style="overflow:hidden;">
              <iframe style="border-radius:12px;" src="https://open.spotify.com/embed/track/<?= e($track['spotify_track_id']) ?>?utm_source=generator"
                width="100%" height="152" frameborder="0" allowfullscreen loading="lazy"
                allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"></iframe>
            </div>
            <?php endif; ?>

            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title"><i class="bi bi-file-text me-1"></i>Testo</h3></div>
              <div class="card-body" style="white-space:pre-line;line-height:1.7;"><?= e($track['lyrics']) ?></div>
            </div>

            <p class="text-center"><a href="/<?= e($slug) ?>/brani/<?= (int) $track['id'] ?>/votazioni"><i class="bi bi-star-fill me-1"></i>Vota questo brano →</a></p>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Vota questo brano" a tema AdminLTE — track_review.php. Riusa renderRatingForm()
// (form di voto reale, invariato rispetto al Colorful) dentro la card.
function renderAdminLteTrackReviewPage(array $artist, string $slug, array $track, array $stats, ?int $viewerId, ?int $myRating, array $reviewers): string {
    $pageUrl = siteUrl('/' . $slug . '/brani/' . (int) $track['id'] . '/votazioni');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vota: <?= e($track['track_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Vota: <?= e($track['track_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'cheamo') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Vota il brano', ['Che Amo' => '/' . $slug . '/che-amo', 'Brani che amo' => '/' . $slug . '/brani']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Vota il brano') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <div class="card mb-3">
              <div class="card-body text-center">
                <?php if ($track['track_image']): ?><img src="<?= e($track['track_image']) ?>" class="mb-2 rounded-3" style="width:96px;height:96px;object-fit:cover;"><?php endif; ?>
                <h1 class="h5 mb-0"><?= e($track['track_name']) ?></h1>
                <p class="text-secondary mb-2"><?= e($track['artist_name']) ?></p>
                <?php if ($track['spotify_url']): ?><a href="<?= e($track['spotify_url']) ?>" target="_blank" rel="noopener" class="small"><i class="bi bi-spotify me-1"></i>Ascolta su Spotify</a><?php endif; ?>
              </div>
            </div>

            <div class="card mb-3 border-primary">
              <div class="card-header"><h3 class="card-title"><i class="bi bi-star-fill me-1"></i>Vota questo brano</h3></div>
              <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <?= renderCromeRating($stats['avg']) ?>
                  <?php if ($stats['count'] > 0): ?><span class="text-secondary small"><?= $stats['avg'] ?> · <?= $stats['count'] ?> <?= $stats['count'] === 1 ? 'voto' : 'voti' ?></span><?php endif; ?>
                </div>
                <?= renderRatingForm('rate_track', (int) $track['id'], $viewerId, (int) $track['user_id'], $myRating) ?>
                <?php if ($reviewers): ?>
                <div class="mt-3 d-flex flex-wrap gap-2">
                  <?php foreach ($reviewers as $r): ?>
                    <span class="badge text-bg-light">@<?= e($r['slug']) ?> <?= renderCromeRating((float) $r['rating']) ?></span>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!empty($track['lyrics'])): ?>
              <p class="text-center"><a href="/<?= e($slug) ?>/brani/<?= (int) $track['id'] ?>/testo"><i class="bi bi-file-text me-1"></i>Testo e ascolto →</a></p>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Spotify" (artista) a tema AdminLTE — artist_spotify.php.
function renderAdminLteSpotifyPage(array $artist, string $slug, array $albums, array $topTracks, ?array $artistDetails): string {
    $pageUrl = siteUrl('/' . $slug . '/spotify');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> su Spotify — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['display_name']) ?> su Spotify">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if (!empty($artistDetails['image'])): ?><meta property="og:image" content="<?= e($artistDetails['image']) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'spotify') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Spotify') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Spotify') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

            <?php if (!empty($artistDetails['genres'])): ?>
            <div class="card mb-3">
              <div class="card-body">
                <?php foreach ($artistDetails['genres'] as $genre): ?>
                  <span class="badge text-bg-light text-capitalize me-1"><?= e($genre) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($topTracks): ?>
              <div class="card mb-2">
                <div class="card-header"><h3 class="card-title h6 mb-0">Brani più ascoltati</h3></div>
              </div>
              <?php foreach ($topTracks as $t): ?>
                <a class="card mb-2 text-decoration-none text-body" href="<?= e($t['spotify_url']) ?>" target="_blank" rel="noopener">
                  <div class="card-body d-flex align-items-center gap-3">
                    <?php if ($t['image']): ?><img src="<?= e($t['image']) ?>" alt="" style="width:56px;height:56px;border-radius:8px;flex-shrink:0;"><?php endif; ?>
                    <div class="flex-grow-1" style="min-width:0;">
                      <strong class="d-block text-truncate"><?= e($t['name']) ?></strong>
                      <small class="text-secondary"><?= e($t['album_name']) ?></small>
                    </div>
                    <i class="bi bi-spotify text-success fs-4 flex-shrink-0"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($albums): ?>
              <div class="card mt-4 mb-2">
                <div class="card-header"><h3 class="card-title h6 mb-0">Album e singoli</h3></div>
              </div>
              <div class="row g-3 text-center">
                <?php foreach ($albums as $a): ?>
                  <div class="col-6 col-sm-4 col-lg-3">
                    <a href="<?= e($a['spotify_url']) ?>" target="_blank" rel="noopener" class="card text-decoration-none text-body p-2 h-100">
                      <?php if ($a['image']): ?><img src="<?= e($a['image']) ?>" alt="" class="rounded-3 mb-1" style="width:100%;"><?php endif; ?>
                      <div class="small fw-semibold"><?= e($a['name']) ?></div>
                      <div class="text-secondary" style="font-size:11.5px;">
                        <?= e($a['release_date'] ? substr($a['release_date'], 0, 4) : '') ?> · <?= $a['type'] === 'single' ? 'Singolo' : 'Album' ?>
                      </div>
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!$topTracks && !$albums): ?>
              <div class="card"><div class="card-body text-secondary">Nessun contenuto trovato su Spotify per questo artista al momento.</div></div>
            <?php endif; ?>

            <div class="card mt-4">
              <div class="card-body text-center">
                <a class="btn btn-success" href="https://open.spotify.com/artist/<?= e($artist['spotify_artist_id']) ?>" target="_blank" rel="noopener">
                  <i class="bi bi-spotify me-1"></i>Apri il profilo completo su Spotify
                </a>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Podcast" a tema AdminLTE — podcast.php.
function renderAdminLtePodcastPage(array $artist, string $slug, array $episodes, ?array $showDetails): string {
    $pageUrl = siteUrl('/' . $slug . '/podcast');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['spotify_show_name'] ?: 'Podcast') ?> — <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['spotify_show_name'] ?: 'Podcast') ?> — <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if (!empty($showDetails['image'])): ?><meta property="og:image" content="<?= e($showDetails['image']) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'podcast') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $artist['spotify_show_name'] ?: 'Podcast') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e($artist['spotify_show_name'] ?: 'Podcast') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($episodes): ?>
              <?php foreach ($episodes as $ep): ?>
                <a class="card mb-2 text-decoration-none text-body" href="<?= e($ep['spotify_url']) ?>" target="_blank" rel="noopener">
                  <div class="card-body d-flex align-items-center gap-3">
                    <?php if ($ep['image']): ?><img src="<?= e($ep['image']) ?>" alt="" style="width:64px;height:64px;border-radius:8px;flex-shrink:0;"><?php endif; ?>
                    <div class="flex-grow-1" style="min-width:0;">
                      <strong class="d-block"><?= e($ep['name']) ?></strong>
                      <small class="text-secondary">
                        <?= $ep['release_date'] ? date('d/m/Y', strtotime($ep['release_date'])) : '' ?>
                        <?= $ep['duration_ms'] ? ' · ' . gmdate('i:s', (int) ($ep['duration_ms'] / 1000)) . ' min' : '' ?>
                      </small>
                      <?php if ($ep['description']): ?><p class="text-secondary small mb-0 mt-1"><?= e($ep['description']) ?></p><?php endif; ?>
                    </div>
                    <i class="bi bi-spotify text-success fs-4 flex-shrink-0"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Nessun episodio trovato al momento.</div></div>
            <?php endif; ?>

            <div class="card mt-4">
              <div class="card-body text-center">
                <a class="btn btn-success" href="https://open.spotify.com/show/<?= e($artist['spotify_show_id']) ?>" target="_blank" rel="noopener">
                  <i class="bi bi-spotify me-1"></i>Ascolta tutti gli episodi su Spotify
                </a>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Video" (YouTube) a tema AdminLTE — video.php.
function renderAdminLteVideoPage(array $artist, string $slug, array $videos): string {
    $pageUrl = siteUrl('/' . $slug . '/video');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> su YouTube — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($artist['display_name']) ?> su YouTube">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'video') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Video') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Video') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($videos): ?>
              <?php foreach ($videos as $v): ?>
                <div class="card mb-3 p-0" style="overflow:hidden;">
                  <div class="ratio ratio-16x9">
                    <iframe src="https://www.youtube.com/embed/<?= e($v['video_id']) ?>" title="<?= e($v['title']) ?>"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                  </div>
                  <div class="p-3"><strong><?= e($v['title']) ?></strong></div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Nessun video trovato su YouTube per questo canale al momento.</div></div>
            <?php endif; ?>

            <div class="card mt-3">
              <div class="card-body text-center">
                <a class="btn btn-danger" href="https://www.youtube.com/channel/<?= e($artist['youtube_channel_id']) ?>" target="_blank" rel="noopener">
                  <i class="bi bi-youtube me-1"></i>Vai al canale completo su YouTube
                </a>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Menù" a tema AdminLTE — menu.php. Tab delle categorie con piccolo JS vanilla
// (stesso principio del filtro Album/Timeline già usato nel tab Foto della Home): niente Bootstrap
// JS da caricare in più solo per questa pagina.
function renderAdminLteMenuPage(array $artist, string $slug, array $categories, array $itemsByCategory): string {
    $pageUrl = siteUrl('/' . $slug . '/menu');
    $hasAllergens = false;
    foreach ($itemsByCategory as $items) {
        foreach ($items as $it) {
            if (parseMenuAllergens($it['allergens'] ?? null)) { $hasAllergens = true; break 2; }
        }
    }
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Menù di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Menù di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'menu') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Menù') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Menù') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($categories): ?>
            <div class="card">
              <div class="card-header p-0 border-bottom-0">
                <ul class="nav nav-pills p-2 gap-1" id="menu-tabs">
                  <?php foreach ($categories as $index => $cat): ?>
                    <li class="nav-item"><button type="button" class="nav-link<?= $index === 0 ? ' active' : '' ?>" data-menu-cat="<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></button></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <div class="card-body">
                <?php foreach ($categories as $index => $cat): ?>
                <div class="menu-cat-panel<?= $index === 0 ? '' : ' d-none' ?>" data-menu-panel="<?= (int) $cat['id'] ?>">
                  <h3 class="h6 mb-3"><?= e($cat['name']) ?></h3>
                  <?php foreach ($itemsByCategory[(int) $cat['id']] as $it): $allergens = parseMenuAllergens($it['allergens'] ?? null); ?>
                    <div class="d-flex justify-content-between align-items-start border-bottom py-2">
                      <div>
                        <?= e($it['name']) ?><?php foreach ($allergens as $aId): ?><sup title="<?= e(MENU_ALLERGENS[$aId]) ?>">&nbsp;<?= $aId ?></sup><?php endforeach; ?>
                        <?php if ($it['description']): ?><div class="text-secondary small"><?= e($it['description']) ?></div><?php endif; ?>
                      </div>
                      <?php if ($it['price'] !== null): ?><div class="fw-semibold text-nowrap ms-3">€ <?= e(number_format((float) $it['price'], 2, ',', '.')) ?></div><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php else: ?>
              <div class="card"><div class="card-body text-secondary">Il menù non è ancora disponibile.</div></div>
            <?php endif; ?>

            <?php if ($hasAllergens): ?>
              <div class="card mt-3">
                <div class="card-body text-secondary small">
                  Allergeni:
                  <?php foreach (MENU_ALLERGENS as $aId => $aLabel): ?><?= $aId ?>. <?= e($aLabel) ?><?= $aId < count(MENU_ALLERGENS) ? ' · ' : '' ?><?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<script>
(function () {
  var tabs = document.getElementById('menu-tabs');
  if (!tabs) return;
  tabs.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-menu-cat]');
    if (!btn) return;
    var catId = btn.getAttribute('data-menu-cat');
    tabs.querySelectorAll('.nav-link').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('[data-menu-panel]').forEach(function (p) {
      p.classList.toggle('d-none', p.getAttribute('data-menu-panel') !== catId);
    });
  });
})();
</script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Elenco pubblico "Offerte speciali" a tema AdminLTE — offerte.php.
function renderAdminLteOfferteListPage(array $artist, string $slug, array $offers): string {
    $pageUrl = siteUrl('/' . $slug . '/offerte');
    $pageSize = 20;
    $finished = count($offers) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offerte speciali di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Offerte speciali di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'offerte') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Offerte') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Offerte') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (!$offers): ?>
              <div class="card"><div class="card-body text-secondary">Nessuna offerta attiva al momento.</div></div>
            <?php else: ?>
              <div id="adminlte-list-feed"><?= renderAdminLteOfferteRows($offers, $slug, $artist) ?></div>
              <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
              <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
              <div id="adminlte-list-sentinel" style="height:1px;"></div>
              <?= adminLteInfiniteScrollScript('offerte', $slug, count($offers), $pageSize, $finished) ?>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Dettaglio pubblico di una singola offerta speciale a tema AdminLTE — offerta.php.
function renderAdminLteOffertaDetailPage(array $artist, string $slug, array $offer, bool $isOwner, bool $isCurrentlyValid): string {
    $pageUrl = siteUrl('/' . $slug . '/offerte/' . (int) $offer['id']);
    $ogImage = $offer['cover_path'] ? siteUrl($offer['cover_path']) : ($offer['avatar_path'] ? siteUrl($offer['avatar_path']) : null);
    $ogDescriptionParts = array_filter([$offer['price_label'], $offer['description'] ? textExcerpt($offer['description'], 160) : null]);
    $ogDescription = $ogDescriptionParts ? implode(' — ', $ogDescriptionParts) : ($offer['display_name'] . ' — scopri l\'offerta su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($offer['custom_feed_guid'] ?? null, $offer['custom_feed_guid_since'] ?? null, $offer['created_at']); ?>
<title><?= e($offer['title']) ?> — <?= e($offer['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($offer['title']) ?> — <?= e($offer['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($offer['title']) ?> — <?= e($offer['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'offerte') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Offerta', ['Offerte' => '/' . $slug . '/offerte']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Offerta') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($isOwner && (!(int) $offer['is_active'] || !$isCurrentlyValid)): ?>
              <div class="alert alert-warning">Questa offerta non è visibile al pubblico al momento (disattivata o fuori dal periodo di validità) — la vedi solo tu, come proprietario del profilo.</div>
            <?php endif; ?>
            <div class="card mb-3">
              <?php if ($offer['cover_path']): ?><img src="/<?= e($offer['cover_path']) ?>" alt="<?= e($offer['title']) ?>" class="card-img-top" style="max-height:400px;object-fit:cover;"><?php endif; ?>
              <div class="card-body text-center">
                <h1 class="h4 mb-1"><?= e($offer['title']) ?></h1>
                <?php if ($offer['price_label']): ?><p class="text-primary fw-bold fs-5"><?= e($offer['price_label']) ?></p><?php endif; ?>
                <?php if ($offer['valid_from'] || $offer['valid_until']): ?>
                  <p class="text-secondary">
                    <?= $offer['valid_from'] ? 'Dal ' . e(formatLocalDateTime($offer['valid_from'], $artist)) : '' ?>
                    <?= $offer['valid_from'] && $offer['valid_until'] ? ' — ' : '' ?>
                    <?= $offer['valid_until'] ? 'Fino al ' . e(formatLocalDateTime($offer['valid_until'], $artist)) : '' ?>
                  </p>
                <?php endif; ?>
                <?php if (!empty($offer['description'])): ?><p class="text-start mt-3"><?= nl2br(e($offer['description'])) ?></p><?php endif; ?>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Foto" (album + galleria Timeline) a tema AdminLTE — foto.php. Riusa la stessa
// griglia .ig-grid-item/.ig-lightbox già presente nel tab Foto della Home.
function renderAdminLteFotoPage(array $artist, string $slug, array $albums, array $timelinePhotos): string {
    $pageUrl = siteUrl('/' . $slug . '/foto');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Foto di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Foto di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if ($timelinePhotos): ?><link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>"><?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'foto') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Foto') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Foto') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">

            <?php if ($albums): ?>
              <div class="card mb-2">
                <div class="card-header"><h3 class="card-title h6 mb-0">Album (<?= count($albums) ?>)</h3></div>
              </div>
              <div class="row g-3 text-center mb-3">
                <?php foreach ($albums as $al): ?>
                  <div class="col-6 col-sm-4 col-lg-3">
                    <a href="/<?= e($slug) ?>/album/<?= (int) $al['id'] ?>" class="card text-decoration-none text-body p-2 h-100">
                      <?php if ($al['cover_path']): ?><img src="/<?= e($al['cover_path']) ?>" alt="" loading="lazy" class="rounded mb-2" style="width:100%;aspect-ratio:1;object-fit:cover;"><?php endif; ?>
                      <div class="small fw-semibold"><i class="bi bi-images me-1"></i><?= e($al['title']) ?></div>
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($timelinePhotos): ?>
              <div class="card mb-2">
                <div class="card-header"><h3 class="card-title h6 mb-0">Foto (<?= count($timelinePhotos) ?>)</h3></div>
              </div>
              <div class="row g-2">
                <?php foreach ($timelinePhotos as $i => $ph): ?>
                  <div class="col-4 col-sm-3 col-lg-2">
                    <a href="/<?= e($slug) ?>/timeline/<?= (int) $ph['post_id'] ?>" class="ig-grid-item d-block" data-lightbox="foto-grid" data-index="<?= $i ?>">
                      <img src="/<?= e($ph['photo']) ?>" alt="" loading="lazy" class="rounded" style="width:100%;aspect-ratio:1;object-fit:cover;">
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="ig-lightbox" data-post="foto-grid">
                <button type="button" class="ig-lightbox-close" aria-label="Chiudi">✕</button>
                <div class="ig-lightbox-track">
                  <?php foreach ($timelinePhotos as $ph): ?><img src="/<?= e($ph['photo']) ?>" alt="" loading="lazy"><?php endforeach; ?>
                </div>
                <button type="button" class="ig-arrow ig-arrow-prev" aria-label="Foto precedente">‹</button>
                <button type="button" class="ig-arrow ig-arrow-next" aria-label="Foto successiva">›</button>
                <div class="ig-lightbox-counter"></div>
              </div>
            <?php endif; ?>

            <?php if (!$albums && !$timelinePhotos): ?>
              <div class="card"><div class="card-body text-secondary">Nessuna foto ancora.</div></div>
            <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if ($timelinePhotos): ?><script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Dettaglio pubblico di un album fotografico a tema AdminLTE — album_item.php.
function renderAdminLteAlbumDetailPage(array $artist, string $slug, array $album, array $photos, bool $isOwner, bool $isScheduledFuture): string {
    $pageUrl = siteUrl('/' . $slug . '/album/' . (int) $album['id']);
    $ogImage = $album['cover_path']
        ? siteUrl(count($photos) > 1 ? getFeedShareImage($album['cover_path']) : $album['cover_path'])
        : ($album['avatar_path'] ? siteUrl($album['avatar_path']) : null);
    $ogDescription = $album['description'] ? textExcerpt($album['description'], 160) : ($album['display_name'] . ' — scopri l\'album su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($album['custom_feed_guid'] ?? null, $album['custom_feed_guid_since'] ?? null, $album['created_at']); ?>
<title><?= e($album['title']) ?> — <?= e($album['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($album['title']) ?> — <?= e($album['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($album['title']) ?> — <?= e($album['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if (count($photos) > 1): ?><link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>"><?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'foto') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Album', ['Foto' => '/' . $slug . '/foto']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Album') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($isOwner && (!(int) $album['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo album non è visibile al pubblico al momento (privato o programmato per il futuro) — lo vedi solo tu, come proprietario del profilo.</div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?= renderPhotoCarousel($photos, (int) $album['id']) ?>
                <h1 class="h4 mb-1"><?= e($album['title']) ?></h1>
                <p class="text-secondary">Album di <?= e($album['display_name']) ?> · <?= count($photos) ?> foto</p>
                <?php if (!empty($album['description'])): ?><p class="text-start mt-2"><?= nl2br(e($album['description'])) ?></p><?php endif; ?>
              </div>
            </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if (count($photos) > 1): ?><script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Elenco pubblico "Servizi" a tema AdminLTE — servizi.php.
function renderAdminLteServiziListPage(array $artist, string $slug, array $services): string {
    $pageUrl = siteUrl('/' . $slug . '/servizi');
    $pageSize = 20;
    $finished = count($services) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Servizi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Servizi di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'servizi') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Servizi') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Servizi') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (!$services): ?>
              <div class="card"><div class="card-body text-secondary">Nessun servizio pubblicato al momento.</div></div>
            <?php else: ?>
              <div id="adminlte-list-feed"><?= renderAdminLteServiziRows($services, $slug) ?></div>
              <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
              <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
              <div id="adminlte-list-sentinel" style="height:1px;"></div>
              <?= adminLteInfiniteScrollScript('servizi', $slug, count($services), $pageSize, $finished) ?>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Dettaglio pubblico di un singolo servizio a tema AdminLTE — servizio_item.php (con eventuale
// modulo "Richiedi informazioni", stessa logica di invio/notifica del Colorful, solo grafica diversa).
function renderAdminLteServizioDetailPage(array $artist, string $slug, array $service, array $photos, bool $isOwner, bool $isScheduledFuture, bool $formSent, ?string $formError, ?string $conversionEventId): string {
    $pageUrl = siteUrl('/' . $slug . '/servizi/' . (int) $service['id']);
    $ogImage = $service['cover_path'] ? siteUrl($service['cover_path']) : ($service['avatar_path'] ? siteUrl($service['avatar_path']) : null);
    $ogDescription = $service['description'] ? textExcerpt($service['description'], 160) : ($service['display_name'] . ' — scopri il servizio su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($service['custom_feed_guid'] ?? null, $service['custom_feed_guid_since'] ?? null, $service['created_at']); ?>
<title><?= e($service['title']) ?> — <?= e($service['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($service['title']) ?> — <?= e($service['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($service['title']) ?> — <?= e($service['display_name']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?php if (count($photos) > 1): ?><link rel="stylesheet" href="<?= assetUrl('/assets/css/ig-carousel.css') ?>"><?php endif; ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'servizi') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Servizio', ['Servizi' => '/' . $slug . '/servizi']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Servizio') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($isOwner && (!(int) $service['is_public'] || $isScheduledFuture)): ?>
              <div class="alert alert-warning">Questo servizio non è visibile al pubblico al momento (privato o programmato per il futuro) — lo vedi solo tu, come proprietario del profilo.</div>
            <?php endif; ?>
            <div class="card mb-3">
              <div class="card-body text-center">
                <?= renderPhotoCarousel($photos, (int) $service['id']) ?>
                <h1 class="h4 mb-1"><?= e($service['title']) ?></h1>
                <?php if (!empty($service['description'])): ?><p class="text-start mt-2"><?= nl2br(e($service['description'])) ?></p><?php endif; ?>
              </div>
            </div>

            <?php if ((int) $service['accepts_inquiries'] === 1): ?>
            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title">Richiedi informazioni</h3></div>
              <div class="card-body">
                <?php if ($formSent): ?>
                  <div class="alert alert-success mb-0">Richiesta inviata! Verrai ricontattato al più presto.</div>
                  <?= embedClientSideConversionEvent('Lead', $conversionEventId, $artist) ?>
                <?php else: ?>
                  <?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>
                  <form method="post">
                    <?= csrfField() ?>
                    <div class="mb-2"><label class="form-label">Nome</label><input type="text" name="guest_name" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Email</label><input type="email" name="guest_email" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Telefono (opzionale)</label><input type="tel" name="guest_phone" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Messaggio (opzionale)</label><textarea name="message" rows="4" class="form-control"></textarea></div>
                    <button type="submit" class="btn btn-primary">Invia richiesta</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
<?php if (count($photos) > 1): ?><script src="<?= assetUrl('/assets/js/ig-carousel.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Elenco pubblico "Eventi" a tema AdminLTE — eventi.php.
function renderAdminLteEventiListPage(array $artist, string $slug, array $events): string {
    $pageUrl = siteUrl('/' . $slug . '/eventi');
    $pageSize = 20;
    $finished = count($events) < $pageSize;
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eventi di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Eventi di <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'eventi') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Eventi') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Eventi') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if (!$events): ?>
              <div class="card"><div class="card-body text-secondary">Nessun evento in programma al momento.</div></div>
            <?php else: ?>
              <div id="adminlte-list-feed"><?= renderAdminLteEventiRows($events, $slug, $artist) ?></div>
              <p id="adminlte-list-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
              <p id="adminlte-list-end" class="text-secondary text-center small" style="display:<?= $finished ? 'block' : 'none' ?>;">Hai visto tutto.</p>
              <div id="adminlte-list-sentinel" style="height:1px;"></div>
              <?= adminLteInfiniteScrollScript('eventi', $slug, count($events), $pageSize, $finished) ?>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Dettaglio pubblico di un singolo evento a tema AdminLTE — evento.php (con eventuale modulo
// "Prenota", stesso form/azione del Colorful — invariato, solo grafica diversa).
function renderAdminLteEventoDetailPage(array $artist, string $slug, array $event, ?string $scheduleLabel, string $resMsg, bool $resErr): string {
    $pageUrl = siteUrl('/' . $slug . '/eventi/' . (int) $event['id']);
    $ogImage = $event['cover_path'] ? siteUrl($event['cover_path']) : ($event['avatar_path'] ? siteUrl($event['avatar_path']) : null);
    $locationLine = trim(($event['venue'] ?: '') . ($event['venue'] && $event['city'] ? ', ' : '') . ($event['city'] ?: ''));
    $ogDescription = trim($event['display_name'] . ' — ' . formatLocalDateTime($event['event_date'], $artist) . ($locationLine ? ' · ' . $locationLine : ''));
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($event['custom_feed_guid'] ?? null, $event['custom_feed_guid_since'] ?? null, $event['created_at']); ?>
<title><?= e($event['title']) ?> — <?= e($event['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($event['title']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($event['title']) ?>">
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php if ($ogImage): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'eventi') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Evento', ['Eventi' => '/' . $slug . '/eventi']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Evento') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <div class="card mb-3">
              <?php if ($event['cover_path']): ?>
                <div class="position-relative">
                  <img src="/<?= e($event['cover_path']) ?>" alt="<?= e($event['title']) ?>" class="card-img-top" style="max-height:400px;object-fit:cover;">
                  <?php if ($scheduleLabel): ?><span class="badge text-bg-primary position-absolute top-0 start-0 m-2"><i class="bi bi-arrow-repeat me-1"></i><?= e($scheduleLabel) ?></span><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="card-body text-center">
                <h1 class="h4 mb-1"><?= e($event['title']) ?></h1>
                <p class="text-secondary mb-1"><?= e(formatLocalDateTime($event['event_date'], $artist)) ?></p>
                <?php if ($locationLine): ?><p class="text-secondary"><?= e($locationLine) ?></p><?php endif; ?>
                <?php if ($scheduleLabel && !$event['cover_path']): ?><p><span class="badge text-bg-primary"><i class="bi bi-arrow-repeat me-1"></i><?= e($scheduleLabel) ?></span></p><?php endif; ?>
                <?php if (!empty($event['description'])): ?><p class="text-start mt-2"><?= nl2br(e($event['description'])) ?></p><?php endif; ?>
                <?php if ($event['ticket_url']): ?><a class="btn btn-primary mt-2" href="<?= e($event['ticket_url']) ?>" target="_blank" rel="noopener">Biglietti →</a><?php endif; ?>
              </div>
            </div>

            <?php if ($resMsg): ?><div class="alert <?= $resErr ? 'alert-danger' : 'alert-success' ?>"><?= e($resMsg) ?></div><?php endif; ?>

            <?php if ((int) $event['accepts_reservations'] === 1): ?>
            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title">Prenota</h3></div>
              <div class="card-body">
                <form method="post" action="/reserve_table.php">
                  <?= csrfField() ?>
                  <input type="hidden" name="slug" value="<?= e($slug) ?>">
                  <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                  <div class="mb-2"><label class="form-label">Nome e cognome</label><input type="text" name="guest_name" class="form-control" required></div>
                  <div class="mb-2"><label class="form-label">Email</label><input type="email" name="guest_email" class="form-control" required></div>
                  <div class="mb-2"><label class="form-label">Telefono (facoltativo)</label><input type="tel" name="guest_phone" class="form-control"></div>
                  <div class="mb-2"><label class="form-label">Numero di persone</label><input type="number" name="party_size" min="1" max="50" value="2" class="form-control" required></div>
                  <div class="mb-2"><label class="form-label">Note (facoltative)</label><input type="text" name="notes" class="form-control" placeholder="es. seggiolone, allergie, ..."></div>
                  <div class="form-check mb-3">
                    <input type="checkbox" name="marketing_opt_in" value="1" class="form-check-input" id="mktOptIn">
                    <label class="form-check-label" for="mktOptIn">Voglio ricevere aggiornamenti su nuovi eventi e offerte da <?= e($event['display_name']) ?></label>
                  </div>
                  <button type="submit" class="btn btn-primary">Prenota</button>
                </form>
              </div>
            </div>
            <?php endif; ?>

            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

// Pagina pubblica "Contatti" a tema AdminLTE — contatti.php (stesso form/azione del Colorful,
// invariato, solo grafica diversa).
function renderAdminLteContattiPage(array $artist, string $slug, bool $formSent, ?string $formError, ?string $conversionEventId): string {
    $pageUrl = siteUrl('/' . $slug . '/contatti');
    ob_start();
    ?>
<!doctype html>
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contatti — <?= e($artist['display_name']) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Contatta <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'contatti') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], 'Contatti') ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-md-6 order-1 order-md-2 adminlte-main-col">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><?= e('Contatti') ?></h3>
                <div class="card-tools">
                  <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                    <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                    <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
            <?php if ($formSent): ?>
              <div class="alert alert-success">Messaggio inviato! Grazie, verrai ricontattato al più presto.</div>
              <?= embedClientSideConversionEvent('Contact', $conversionEventId, $artist) ?>
            <?php else: ?>
              <?php if ($formError): ?><div class="alert alert-danger"><?= e($formError) ?></div><?php endif; ?>
              <div class="card">
                <div class="card-body">
                  <form method="post">
                    <?= csrfField() ?>
                    <div class="mb-2"><label class="form-label">Nome</label><input type="text" name="sender_name" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Email</label><input type="email" name="sender_email" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Messaggio</label><textarea name="message" rows="4" class="form-control" required></textarea></div>
                    <button type="submit" class="btn btn-primary">Invia messaggio</button>
                  </form>
                </div>
              </div>
            <?php endif; ?>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

function getPageThemeClass(?string $theme): string {
    return PAGE_THEMES[$theme]['body_class'] ?? PAGE_THEMES['colorful']['body_class'];
}

const COLORFUL_PALETTE = ['#FFD6A5', '#FDFFB6', '#CAFFBF', '#9BF6FF', '#A0C4FF', '#BDB2FF', '#FFC6FF', '#FFADAD'];

// I 14 allergeni ad etichettatura obbligatoria nell'UE (Regolamento 1169/2011, Allegato II),
// numerati come da convenzione comune sui menu dei ristoranti italiani.
const MENU_ALLERGENS = [
    1 => 'Cereali contenenti glutine',
    2 => 'Crostacei',
    3 => 'Uova',
    4 => 'Pesce',
    5 => 'Arachidi',
    6 => 'Soia',
    7 => 'Latte (incluso lattosio)',
    8 => 'Frutta a guscio',
    9 => 'Sedano',
    10 => 'Senape',
    11 => 'Semi di sesamo',
    12 => 'Anidride solforosa e solfiti',
    13 => 'Lupini',
    14 => 'Molluschi',
];

// Converte la stringa "1,4,7" salvata nel database in un elenco di numeri validi (1-14),
// scartando eventuali valori corrotti o fuori range.
function parseMenuAllergens(?string $csv): array {
    if (!$csv) return [];
    $ids = array_filter(array_map('intval', explode(',', $csv)));
    return array_values(array_intersect($ids, array_keys(MENU_ALLERGENS)));
}

// Usata dall'header pubblico per decidere se mostrare il tab "Menù" — un solo COUNT leggero,
// non richiede di modificare le query di ogni singola pagina pubblica per portarsi dietro il dato.
function menuHasItems(int $userId): bool {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM menu_items WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

// Vero se il profilo ha almeno un'offerta attualmente valida (attiva E, se ha una validità
// impostata, dentro l'intervallo) — usata per decidere se mostrare il tab "Offerte" nel menu
// pubblico, stesso criterio di menuHasItems() per il Menù.
function hasActiveOffers(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM special_offers
        WHERE user_id = ? AND is_active = 1
          AND (valid_from IS NULL OR valid_from <= NOW())
          AND (valid_until IS NULL OR valid_until >= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

// Tutte queste hasFanFavoriteX() decidono se il relativo tab/modulo "che amo" compare nel menu
// pubblico e nella vetrina — devono quindi contare solo gli elementi davvero visibili a un
// visitatore (Pubblico e non ancora programmati per il futuro), altrimenti un profilo con solo
// elementi "Solo io" mostrerebbe comunque il tab, portando a una vetrina vuota.
function hasFanFavoriteBands(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_bands WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoriteActors(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_actors WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoriteMovies(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_movies WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoriteBooks(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_books WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoriteTrips(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_trips WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoritePlaylists(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_playlists WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoriteAlbums(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_albums WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoriteRecipes(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_recipes WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoriteTeams(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_teams WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoritePlayers(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_players WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

function hasFanFavoriteMatches(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_matches WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

// Elenco dei moduli "che amo" raccolti nella vetrina unica (che_amo.php / dashboard_che_amo.php)
// — chiave interna (usata anche in profile_navigation_menu tramite PUBLIC_NAV_ITEM_KEYS) => nome
// visualizzato, funzione che dice se il profilo ha contenuto, segmento URL pubblico. "Brani che
// amo" non ha una funzione di controllo contenuto: non l'ha mai avuta (era il modulo originale,
// sempre mostrato), si mantiene lo stesso comportamento anche da dentro la vetrina.
const CHE_AMO_MODULES = [
    'bandcheamo' => ['label' => 'Band che amo', 'icon' => 'fas fa-heart-circle-check', 'check' => 'hasFanFavoriteBands', 'segment' => 'band-che-amo', 'table' => 'fan_favorite_bands'],
    'attorichamo' => ['label' => 'Attori che amo', 'icon' => 'fas fa-clapperboard', 'check' => 'hasFanFavoriteActors', 'segment' => 'attori-che-amo', 'table' => 'fan_favorite_actors'],
    'filmcheamo' => ['label' => 'Film che amo', 'icon' => 'fas fa-film', 'check' => 'hasFanFavoriteMovies', 'segment' => 'film-che-amo', 'table' => 'fan_favorite_movies'],
    'libricheamo' => ['label' => 'Libri che amo', 'icon' => 'fas fa-book', 'check' => 'hasFanFavoriteBooks', 'segment' => 'libri-che-amo', 'table' => 'fan_favorite_books'],
    'viaggi' => ['label' => 'Viaggi', 'icon' => 'fas fa-plane', 'check' => 'hasFanFavoriteTrips', 'segment' => 'viaggi', 'table' => 'fan_favorite_trips'],
    'brani' => ['label' => 'Brani che amo', 'icon' => 'fas fa-music', 'check' => null, 'segment' => 'brani', 'table' => 'favorite_tracks'],
    'playlistcheamo' => ['label' => 'Playlist che amo', 'icon' => 'fas fa-list-ul', 'check' => 'hasFanFavoritePlaylists', 'segment' => 'playlist-che-amo', 'table' => 'fan_favorite_playlists'],
    'albumcheamo' => ['label' => 'Album che amo', 'icon' => 'fas fa-compact-disc', 'check' => 'hasFanFavoriteAlbums', 'segment' => 'album-che-amo', 'table' => 'fan_favorite_albums'],
    'ricettecheamo' => ['label' => 'Ricette che amo', 'icon' => 'fas fa-bowl-food', 'check' => 'hasFanFavoriteRecipes', 'segment' => 'ricette-che-amo', 'table' => 'fan_favorite_recipes'],
    'squadrecheamo' => ['label' => 'Squadre che amo', 'icon' => 'fas fa-futbol', 'check' => 'hasFanFavoriteTeams', 'segment' => 'squadre-che-amo', 'table' => 'fan_favorite_teams'],
    'calciatoricheamo' => ['label' => 'Calciatori che amo', 'icon' => 'fas fa-shirt', 'check' => 'hasFanFavoritePlayers', 'segment' => 'calciatori-che-amo', 'table' => 'fan_favorite_players'],
    'partitecheamo' => ['label' => 'Partite che amo', 'icon' => 'fas fa-calendar-check', 'check' => 'hasFanFavoriteMatches', 'segment' => 'partite-che-amo', 'table' => 'fan_favorite_matches'],
];

// True se almeno un modulo "che amo" non nascosto ($hiddenKeys, da getHiddenNavKeys()) ha
// contenuto — decide se mostrare il tab riepilogativo "Che Amo" nel menu pubblico. "cheamo"
// stesso tra le chiavi nascoste (es. disattivato per tutta l'installazione da Area Admin →
// Funzioni del sito) spegne l'intera vetrina a prescindere dai singoli moduli.
function hasAnyVisibleCheAmo(int $userId, array $hiddenKeys = []): bool {
    if (in_array('cheamo', $hiddenKeys, true)) {
        return false;
    }
    foreach (CHE_AMO_MODULES as $key => $m) {
        if (in_array($key, $hiddenKeys, true)) {
            continue;
        }
        if ($m['check'] === null || $m['check']($userId)) {
            return true;
        }
    }
    return false;
}

// Menu di navigazione condiviso tra tutte le pagine pubbliche di un artista (Home | Blog | Brani | Eventi | Contatti)
// Il tab "Spotify" compare solo se l'artista ha collegato un profilo Spotify dalla dashboard.
function publicNav(string $slug, string $active, bool $hasSpotify = false, bool $hasYoutube = false, bool $hasPodcast = false, string $accountType = 'band', ?int $ownerId = null, bool $hasMenu = false, bool $hasOffers = false, bool $hasPhotos = false, bool $hasServices = false): string {
    $isBandOrLabel = in_array($accountType, ['band', 'label'], true);
    // Tab che il profilo ha esplicitamente nascosto da "Menu di Navigazione" in dashboard —
    // copre anche le integrazioni e Segui, non solo i tab "di contenuto".
    $hiddenKeys = $ownerId ? getHiddenNavKeys($ownerId) : [];

    $viewerId = $_SESSION['user_id'] ?? null;
    $seguiLabel = '✨ Segui';
    if ($viewerId && $ownerId && (int) $viewerId !== (int) $ownerId) {
        $seguiLabel = isFollowingAccount((int) $viewerId, (int) $ownerId) ? '✓ Segui già' : '✨ Segui';
    }
    $canFollow = !$viewerId || !$ownerId || (int) $viewerId !== (int) $ownerId;

    // Stesso ordine di PUBLIC_NAV_ITEM_KEYS/createDefaultProfileNavMenu(), allineato a quello
    // della barra di gestione in dashboard (_dash_header.php), per restare coerenti anche tra
    // parte pubblica e dashboard, non solo con la checklist di dashboard_nav_menu.php.
    $tabs = [];
    $tabs['home'] = ['label' => 'Home', 'url' => '/' . $slug, 'icon' => 'fas fa-house'];
    $tabs['timeline'] = ['label' => 'Timeline', 'url' => '/' . $slug . '/timeline', 'icon' => 'fas fa-stream'];
    // Un solo tab per tutti i moduli "che amo" (Band/Attori/Film/Libri, Viaggi, Brani che amo, e
    // i futuri Playlist/Album che amo) — porta a una vetrina con una card per modulo, invece di
    // un tab a testa: la lista dei moduli sarebbe altrimenti cresciuta indefinitamente. Ogni
    // modulo resta gestito e mostrato esattamente come prima, cambia solo come ci si arriva.
    if ($ownerId && hasAnyVisibleCheAmo($ownerId, $hiddenKeys)) {
        $tabs['cheamo'] = ['label' => 'Che Amo', 'url' => '/' . $slug . '/che-amo', 'icon' => 'fas fa-heart'];
    }
    if ($hasSpotify && $isBandOrLabel) {
        $tabs['spotify'] = ['label' => 'Spotify', 'url' => '/' . $slug . '/spotify', 'icon' => 'fa-brands fa-spotify'];
    }
    if ($hasPodcast && $isBandOrLabel) {
        $tabs['podcast'] = ['label' => 'Podcast', 'url' => '/' . $slug . '/podcast', 'icon' => 'fas fa-microphone'];
    }
    if ($hasYoutube && $isBandOrLabel) {
        $tabs['video'] = ['label' => 'Video', 'url' => '/' . $slug . '/video', 'icon' => 'fa-brands fa-youtube'];
    }
    $tabs['blog'] = ['label' => 'Blog', 'url' => '/' . $slug . '/blog', 'icon' => 'fas fa-newspaper'];
    if ($hasMenu) {
        $tabs['menu'] = ['label' => 'Menù', 'url' => '/' . $slug . '/menu', 'icon' => 'fas fa-utensils'];
    }
    if ($hasOffers) {
        $tabs['offerte'] = ['label' => 'Offerte', 'url' => '/' . $slug . '/offerte', 'icon' => 'fas fa-tags'];
    }
    if ($hasPhotos) {
        $tabs['foto'] = ['label' => 'Foto', 'url' => '/' . $slug . '/foto', 'icon' => 'fas fa-images'];
    }
    if ($hasServices) {
        $tabs['servizi'] = ['label' => 'Servizi', 'url' => '/' . $slug . '/servizi', 'icon' => 'fas fa-briefcase'];
    }
    if ($isBandOrLabel) {
        $tabs['eventi'] = ['label' => 'Eventi', 'url' => '/' . $slug . '/eventi', 'icon' => 'fas fa-calendar'];
    }
    if ($canFollow) {
        $tabs['segui'] = ['label' => $seguiLabel, 'url' => '/' . $slug . '#segui-widget', 'class' => 'nav-segui-tab'];
    }
    $tabs['contatti'] = ['label' => 'Contatti', 'url' => '/' . $slug . '/contatti', 'icon' => 'fas fa-envelope'];

    // "Nascosto" vale sempre, anche per la pagina su cui ci si trova in quel momento: se il
    // profilo ha disattivato una voce, non deve comparire nel menu neppure arrivandoci tramite
    // link diretto.
    foreach ($hiddenKeys as $hk) {
        unset($tabs[$hk]);
    }

    // Ordine personalizzato dal profilo (trascinamento in dashboard_nav_menu.php) — se non
    // ancora impostato per una voce, resta nell'ordine con cui è stata costruita sopra.
    if ($ownerId) {
        $order = getNavItemOrder($ownerId);
        $keysInOrder = array_keys($tabs);
        uksort($tabs, function ($a, $b) use ($order, $keysInOrder) {
            $posA = $order[$a] ?? (1000 + array_search($a, $keysInOrder, true));
            $posB = $order[$b] ?? (1000 + array_search($b, $keysInOrder, true));
            return $posA <=> $posB;
        });
    }

    // "Home" resta sempre per prima e "Segui" sempre per ultima, qualunque sia l'ordine
    // personalizzato scelto dal profilo o lo spostamento della voce attiva qui sotto — le uniche
    // due il cui posto non deve mai cambiare (Home è il punto fisso da cui ripartire, Segui il
    // pulsante di richiamo che deve restare sempre raggiungibile in fondo a destra).
    $pinHomeAndSegui = function (array $t): array {
        $first = [];
        $last = [];
        if (isset($t['home'])) {
            $first['home'] = $t['home'];
            unset($t['home']);
        }
        if (isset($t['segui'])) {
            $last['segui'] = $t['segui'];
            unset($t['segui']);
        }
        return $first + $t + $last;
    };

    // Ordine "naturale" (quello scelto dal profilo in Menu di Navigazione, con Home/Segui
    // pinnati) prima dello spostamento della voce attiva qui sotto — usato solo su desktop (vedi
    // @media (min-width:761px) su ".colorful-nav a" in style.css) per tenere ferma ogni pillola al
    // proprio posto: lì il menu si vede quasi sempre per intero senza scorrere, e lo spostamento
    // risulterebbe solo un salto innaturale invece che un aiuto.
    $naturalOrder = array_flip(array_keys($pinHomeAndSegui($tabs)));

    // La voce attiva passa al secondo posto (subito dopo la prima) SOLO nella resa di questa
    // pagina, e solo su mobile (l'ordine "naturale" qui sopra vince su desktop via CSS) — l'ordine
    // salvato in Menu di Navigazione resta quello scelto dal proprietario, invariato. Aiuta chi
    // naviga fra tante voci su una barra che scorre in orizzontale a ritrovare sempre subito in
    // vista la sezione in cui si trova, senza dover scorrere il menu ogni volta.
    if (isset($tabs[$active])) {
        $activeEntry = [$active => $tabs[$active]];
        unset($tabs[$active]);
        $tabs = array_slice($tabs, 0, 1, true) + $activeEntry + array_slice($tabs, 1, null, true);
    }
    $tabs = $pinHomeAndSegui($tabs);

    $parts = [];
    foreach ($tabs as $key => $t) {
        $classes = trim(($t['class'] ?? '') . ($key === $active ? ' nav-active-tab' : ''));
        $classAttr = $classes !== '' ? ' class="' . e($classes) . '"' : '';
        $icon = !empty($t['icon']) ? '<i class="' . e($t['icon']) . '"></i> ' : '';
        $orderStyle = ' style="--nav-order:' . (int) ($naturalOrder[$key] ?? 0) . ';"';
        $parts[] = '<a href="' . e($t['url']) . '"' . $classAttr . $orderStyle . '>' . $icon . e($t['label']) . '</a>';
    }
    return '<div class="colorful-nav-wrap">'
        . '<nav class="colorful-nav">' . implode('', $parts) . '</nav>'
        . '<button type="button" class="colorful-nav-arrow" aria-label="Scorri il menu">' . '<i class="fa-solid fa-chevron-right"></i></button>'
        . '</div>'
        . '<script src="' . assetUrl('/assets/js/nav-scroll-hint.js') . '" defer></script>';
}

// Blocco identità condiviso (avatar + nome + eventuale bio + menu) stampato in cima ad ogni
// pagina pubblica dell'artista (home, blog, brani, eventi, contatti, spotify), per un aspetto
// coerente. La bio, quando presente, è mostrata come vignetta al passaggio del mouse
// sull'avatar (non più come testo sempre visibile), per un profilo più compatto.
function publicProfileHeader(array $artist, string $active, bool $showBio = false): string {
    $isElectric = ($artist['page_theme'] ?? 'colorful') === 'electric';
    $electricClass = $isElectric ? ' electric-border' : '';
    $electricStyle = $isElectric ? ' style="--electric-border-color:' . e($artist['theme_color'] ?: '#6C5CE7') . ';"' : '';
    $ownerId = isset($artist['id']) ? (int) $artist['id'] : null;
    // Il "+" accanto al nome utente è una scorciatoia al pulsante "Segui" del menu — stesso link
    // (l'ancora #segui-widget della Home, come già fa la voce "Segui" del menu pubblico), nessuna
    // logica nuova da mantenere. Non ha senso mostrarlo sul proprio stesso profilo.
    $viewerIdForFollow = $_SESSION['user_id'] ?? null;
    $canShowFollowPlus = $ownerId && (!$viewerIdForFollow || (int) $viewerIdForFollow !== $ownerId);
    $html = '<div class="profile-header' . $electricClass . '"' . $electricStyle . '>';
    if (!empty($artist['avatar_path'])) {
        $html .= '<div class="avatar-wrap">';
        $html .= '<img class="avatar" src="/' . e($artist['avatar_path']) . '" alt="' . e($artist['display_name']) . '">';
        if ($showBio && !empty($artist['bio'])) {
            $html .= '<div class="avatar-bio-tooltip">' . nl2br(e($artist['bio'])) . '</div>';
        }
        $html .= '</div>';
    } elseif ($showBio && !empty($artist['bio'])) {
        // Senza avatar non c'è nulla su cui fare hover: la bio resta visibile come testo normale
        $html .= '<p>' . nl2br(e($artist['bio'])) . '</p>';
    }
    $html .= '<h1>' . e($artist['display_name']) . '</h1>';
    $html .= '<p class="profile-meta">@' . e($artist['slug']);
    if ($canShowFollowPlus) {
        $html .= ' <a href="/' . e($artist['slug']) . '#segui-widget" class="profile-follow-plus" aria-label="Segui ' . e($artist['display_name']) . '"><i class="fa-solid fa-plus"></i></a>';
    }
    if (!empty($artist['genere'])) {
        $html .= '<span> · </span>' . e($artist['genere']);
    }
    $html .= '</p>';
    $hasMenu = $ownerId ? menuHasItems($ownerId) : false;
    $hasOffers = $ownerId ? hasActiveOffers($ownerId) : false;
    $hasPhotos = $ownerId ? hasPublicPhotoContent($ownerId) : false;
    $hasServices = $ownerId ? hasVisibleServices($ownerId) : false;
    $html .= publicNav($artist['slug'], $active, !empty($artist['spotify_artist_id']), !empty($artist['youtube_channel_id']), !empty($artist['spotify_show_id']), $artist['account_type'] ?? 'band', $ownerId, $hasMenu, $hasOffers, $hasPhotos, $hasServices);
    $html .= '</div>';
    if ($isElectric) {
        $html .= '<script src="' . assetUrl('/assets/js/electric-border.js') . '" defer></script>';
    }
    return $html;
}

// Barra fissa in fondo alla pagina che invita alla registrazione, presente su tutte le pagine
// pubbliche del sito.
// Footer di tutte le pagine pubbliche: pulsante promozionale "CHI FA COSA/tu" (sopra) + link
// Cookie/Privacy/CHI FA COSA-o-Dashboard (sotto). È un blocco normale nel flusso della pagina (non
// più "fixed"), quindi non copre mai il contenuto — resta comunque sempre visibile in fondo
// alla pagina anche a contenuto vuoto, grazie al layout flessibile di body.colorful-page.
// Pulsanti flottanti condivisi su tutte le pagine pubbliche: "torna su" (compare scrollando
// molto verso il basso) e, se l'utente è loggato, un'iconcina che riporta alla dashboard.
function renderFloatingButtons(): string {
    $dashboardBtn = '';
    if (!empty($_SESSION['user_id'])) {
        $dashboardBtn = '<a href="/dashboard.php" id="to-dashboard-btn" class="floating-btn" title="Vai alla dashboard">
            <i class="fa-solid fa-gauge"></i>
        </a>';
    }

    return $dashboardBtn . '
    <button type="button" id="back-to-top-btn" class="floating-btn" title="Torna su" aria-label="Torna su">
        <i class="fa-solid fa-arrow-up"></i>
    </button>
    <script>
    (function () {
        var btn = document.getElementById("back-to-top-btn");
        if (!btn) return;
        window.addEventListener("scroll", function () {
            btn.style.display = window.scrollY > 400 ? "flex" : "none";
        });
        btn.addEventListener("click", function () {
            window.scrollTo({ top: 0, behavior: "smooth" });
        });
    })();
    </script>';
}

function renderSiteFooterBar(?array $profile = null): string {
    $privacyUrl = trim(getProfileTracking($profile)['privacy_policy_url'] ?? '');
    if ($privacyUrl === '') {
        $privacyUrl = getSiteSetting('privacy_policy_url') ?: '';
    }
    $parts = [];
    // CookieYes intercetta automaticamente qualsiasi elemento con questa classe per riaprire
    // il pannello delle preferenze cookie — non serve nessuna chiamata JavaScript esplicita.
    $parts[] = '<a href="#" class="cky-banner-element">Preferenze Cookie</a>';
    if ($privacyUrl !== '') {
        $parts[] = '<a href="' . e($privacyUrl) . '" target="_blank" rel="noopener">Privacy</a>';
    } else {
        $parts[] = '<a href="/">Privacy</a>';
    }
    // L'ultimo link cambia in base a chi sta navigando: un visitatore qualsiasi vede il nome
    // del sito (torna alla home), chi è già loggato vede "Dashboard" (va alla propria area privata).
    if (!empty($_SESSION['user_id'])) {
        $parts[] = '<a href="/dashboard_profile.php">Dashboard</a>';
    } else {
        $parts[] = '<a href="/">' . e(siteName()) . '</a>';
    }
    $parts[] = '<a href="/credits.php">Crediti</a>';
    $linksRow = '<div class="footer-links">' . implode('<span> · </span>', $parts) . '</div>';
    return '<div class="site-footer-fixed">' . $linksRow . '</div>';
}

// Legge la configurazione SMTP: priorità alle impostazioni salvate dall'admin nel database,
// con ripiego sulle variabili d'ambiente (per compatibilità con configurazioni precedenti).
function getSmtpConfig(): array {
    $host = getSiteSetting('smtp_host');
    $host = ($host !== null && $host !== '') ? $host : (getenv('SMTP_HOST') ?: '');

    $port = getSiteSetting('smtp_port');
    $port = ($port !== null && $port !== '') ? (int) $port : (int) (getenv('SMTP_PORT') ?: 587);

    $user = getSiteSetting('smtp_user');
    $user = ($user !== null && $user !== '') ? $user : (getenv('SMTP_USER') ?: '');

    $pass = getSiteSetting('smtp_pass');
    $pass = ($pass !== null && $pass !== '') ? $pass : (getenv('SMTP_PASS') ?: '');

    $secure = getSiteSetting('smtp_secure');
    $secure = ($secure !== null && $secure !== '') ? $secure : (getenv('SMTP_SECURE') ?: 'tls');

    $from = getSiteSetting('smtp_from');
    $from = ($from !== null && $from !== '') ? $from : (getenv('SMTP_FROM') ?: $user);

    $fromName = getSiteSetting('smtp_from_name');
    $fromName = ($fromName !== null && $fromName !== '') ? $fromName : (getenv('SMTP_FROM_NAME') ?: siteName());

    $verifyCertSetting = getSiteSetting('smtp_verify_cert');
    $verifyCert = ($verifyCertSetting === null || $verifyCertSetting === '') ? true : ($verifyCertSetting === '1');

    return compact('host', 'port', 'user', 'pass', 'secure', 'from', 'fromName', 'verifyCert');
}

// Invia una notifica email al musicista quando riceve un nuovo messaggio di contatto/booking.
// Se l'SMTP non è configurato (né da admin né da variabili d'ambiente), non fa nulla (nessun
// errore, la richiesta resta comunque salvata nel database e visibile in dashboard).
function notifyNewContact(string $toEmail, string $toName, string $senderName, string $senderEmail, string $message, string $publicUrl): void {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return;
    }

    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "Nuovo messaggio da {$senderName} su " . siteName();
    $body = "Hai ricevuto un nuovo messaggio dalla tua pagina {$publicUrl}:\n\n"
          . "Nome: {$senderName}\n"
          . "Email: {$senderEmail}\n\n"
          . "Messaggio:\n{$message}\n\n"
          . "---\nRispondi direttamente a questa email per contattare {$senderName},\n"
          . "oppure gestisci tutti i messaggi dalla tua dashboard su " . siteName() . ".";

    $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}

// Genera un token di verifica email (valido 24 ore)
function generateVerificationToken(): array {
    return [bin2hex(random_bytes(32)), date('Y-m-d H:i:s', strtotime('+24 hours'))];
}

// Notifica al titolare del profilo (o del brano) quando qualcuno lascia un voto.
function notifyNewVote(string $toEmail, string $toName, string $voterSlug, int $rating, string $itemLabel, string $itemUrl): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $stars = str_repeat('★', max(1, min(5, $rating)));
    $subject = "@{$voterSlug} ha votato {$itemLabel} su " . siteName();
    $link = siteUrl($itemUrl);
    $body = "Ciao {$toName},\n\n"
          . "@{$voterSlug} ha appena votato {$itemLabel}: {$stars} ({$rating}/5)\n\n"
          . "Vedi tutti i voti: {$link}";

    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}

// Notifica al gestore quando arriva una nuova prenotazione per un suo evento.
function notifyNewReservation(string $toEmail, string $toName, string $eventTitle, string $guestName, string $guestEmail, ?string $guestPhone, int $partySize, ?string $notes, string $reservationsUrl): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "Nuova prenotazione per \"{$eventTitle}\" — {$guestName} ({$partySize} persone)";
    $body = "Ciao {$toName},\n\n"
          . "Hai ricevuto una nuova prenotazione per \"{$eventTitle}\":\n\n"
          . "Nome: {$guestName}\n"
          . "Email: {$guestEmail}\n"
          . ($guestPhone ? "Telefono: {$guestPhone}\n" : '')
          . "Persone: {$partySize}\n"
          . ($notes ? "Note: {$notes}\n" : '')
          . "\nGestisci tutte le prenotazioni dalla tua dashboard: {$reservationsUrl}";

    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}

// Conferma via email all'ospite che ha appena prenotato.
function notifyReservationConfirmation(string $toEmail, string $guestName, string $venueName, string $eventTitle, string $eventDateFormatted, int $partySize): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "Prenotazione confermata per \"{$eventTitle}\"";
    $body = "Ciao {$guestName},\n\n"
          . "La tua prenotazione da {$venueName} è confermata:\n\n"
          . "Evento: {$eventTitle}\n"
          . "Data: {$eventDateFormatted}\n"
          . "Persone: {$partySize}\n\n"
          . "Se hai bisogno di modificare o annullare la prenotazione, contatta direttamente {$venueName}.\n\n"
          . "A presto!";

    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $guestName, $subject, $body);
}

// Notifica a tutti gli amministratori quando si registra un nuovo utente.
function notifyAdminsNewUser(string $newUserEmail, string $newUserName, string $newUserSlug): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    $stmt = getDB()->prepare('SELECT email FROM users WHERE is_admin = 1');
    $stmt->execute();
    $admins = $stmt->fetchAll();
    if (!$admins) {
        return false;
    }

    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "Nuova registrazione su " . siteName() . ": {$newUserName}";
    $body = "Si è appena registrato un nuovo utente su " . siteName() . ":\n\n"
          . "Nome: {$newUserName}\n"
          . "Email: {$newUserEmail}\n"
          . "Pagina: " . siteUrl('/' . $newUserSlug) . "\n";

    $sentAny = false;
    foreach ($admins as $admin) {
        if ($mailer->send($cfg['from'], $cfg['fromName'], $admin['email'], $admin['email'], $subject, $body)) {
            $sentAny = true;
        }
    }
    return $sentAny;
}

// Verifica se due account si seguono A VICENDA (condizione necessaria per potersi scrivere).
function areMutualFollowers(int $userIdA, int $userIdB): bool {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM account_follows
        WHERE (follower_user_id = ? AND followed_user_id = ?) OR (follower_user_id = ? AND followed_user_id = ?)');
    $stmt->execute([$userIdA, $userIdB, $userIdB, $userIdA]);
    return (int) $stmt->fetch()['c'] === 2;
}

// Notifica "hai un nuovo messaggio" — non rivela mai il contenuto, solo un link alla
// conversazione. Va chiamata al massimo una volta al giorno per coppia di utenti (il controllo
// se sia il primo messaggio della giornata lo fa chi chiama questa funzione, non lei stessa).
function notifyNewMessage(string $toEmail, string $toName, string $fromName, string $conversationUrl): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "{$fromName} ti ha scritto su " . siteName();
    $body = "Ciao {$toName},\n\n"
          . "{$fromName} ti ha mandato un messaggio su " . siteName() . ".\n\n"
          . "Leggilo qui: {$conversationUrl}";

    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}

// Notifica a un profilo quando un altro account inizia a seguirlo.
function notifyNewFollower(string $toEmail, string $toName, string $followerSlug, string $followerName): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "{$followerName} ha iniziato a seguirti su " . siteName();
    $link = siteUrl('/' . $followerSlug);
    $body = "Ciao {$toName},\n\n"
          . "{$followerName} (@{$followerSlug}) ha iniziato a seguirti su " . siteName() . ".\n\n"
          . "Vedi il suo profilo: {$link}";

    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}

// Invia l'email di conferma registrazione con il link di verifica. Come per le notifiche di
// contatto: se l'SMTP non è configurato non fa nulla (nessun errore).
function notifyEmailVerification(string $toEmail, string $toName, string $token): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }

    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $link = siteUrl('/verify.php?token=' . $token);
    $subject = "Conferma il tuo account su " . siteName();
    $body = "Ciao {$toName},\n\n"
          . "Grazie per esserti registrato su " . siteName() . "! Conferma il tuo account cliccando\n"
          . "questo link (valido per 24 ore):\n\n{$link}\n\n"
          . "Se non hai richiesto tu questa registrazione, ignora pure questa email.";

    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}

// Invia l'email con il link per reimpostare la password (valido 1 ora, più breve della verifica
// email perché un link di reset password è più sensibile). Come le altre notifiche: se l'SMTP
// non è configurato, non fa nulla (nessun errore).
function notifyPasswordReset(string $toEmail, string $toName, string $token): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }

    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $link = siteUrl('/reset_password.php?token=' . $token);
    $subject = "Reimposta la tua password su " . siteName();
    $body = "Ciao {$toName},\n\n"
          . "Hai richiesto di reimpostare la password del tuo account " . siteName() . ". Clicca questo\n"
          . "link per scegliere una nuova password (valido 1 ora):\n\n{$link}\n\n"
          . "Se non hai richiesto tu il reset, ignora pure questa email: la tua password attuale\n"
          . "resta invariata.";

    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}
function embedTrackingHead(?array $profile = null): string {
    $t = getProfileTracking($profile);
    $gtm = trim($t['gtm_head_script'] ?? '') !== '' ? $t['gtm_head_script'] : (getSiteSetting('gtm_head_script') ?: '');
    $pixel = trim($t['fb_pixel_script'] ?? '') !== '' ? $t['fb_pixel_script'] : (getSiteSetting('fb_pixel_script') ?: '');
    return $gtm . "\n" . $pixel;
}

function embedTrackingBodyStart(?array $profile = null): string {
    $own = trim(getProfileTracking($profile)['gtm_body_script'] ?? '');
    if ($own !== '') {
        return $own;
    }
    return getSiteSetting('gtm_body_script') ?: '';
}

function embedTrackingBodyEnd(): string {
    return ''; // Placeholder per eventuali script di fine body
}

// Genera un ID univoco per un evento — lo stesso valore va passato sia qui (server, Conversions
// API) sia al richiamo fbq() lato browser, così Meta riconosce che è lo stesso evento visto da
// due fonti diverse e non lo conta due volte.
function generateEventId(): string {
    return bin2hex(random_bytes(12));
}

// Invia un evento a Meta Conversions API lato server (registrazioni, richieste di accesso,
// ecc.) — in aggiunta al Pixel lato browser, per non perdere dati a causa di ad blocker o
// restrizioni Safari/iOS. Non fa nulla se Pixel ID o token non sono configurati, e non lancia
// mai errori: un fallimento qui non deve mai bloccare l'azione dell'utente (registrazione,
// voto, ecc.), è solo tracciamento accessorio.
function sendMetaConversionEvent(string $eventName, string $eventId, ?string $userEmail = null, ?array $profile = null): void {
    $t = getProfileTracking($profile);
    $pixelId = trim($t['fb_pixel_id'] ?? '') !== '' ? $t['fb_pixel_id'] : (getSiteSetting('fb_pixel_id') ?: '');
    $token = trim($t['fb_capi_token'] ?? '') !== '' ? $t['fb_capi_token'] : (getSiteSetting('fb_capi_token') ?: '');
    if ($pixelId === '' || $token === '') {
        return;
    }

    $userData = [
        'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
    if ($userEmail) {
        $userData['em'] = [hash('sha256', strtolower(trim($userEmail)))];
    }
    $fbc = $_COOKIE['_fbc'] ?? null;
    $fbp = $_COOKIE['_fbp'] ?? null;
    if ($fbc) $userData['fbc'] = $fbc;
    if ($fbp) $userData['fbp'] = $fbp;

    $payload = [
        'data' => [[
            'event_name' => $eventName,
            'event_time' => time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? ''),
            'user_data' => $userData,
        ]],
    ];

    $ch = curl_init("https://graph.facebook.com/v19.0/{$pixelId}/events?access_token=" . urlencode($token));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // non deve mai rallentare percepibilmente la richiesta dell'utente
    curl_exec($ch);
    curl_close($ch);
}

// Restituisce lo script <script> da stampare subito dopo un'azione (registrazione completata,
// richiesta di accesso inviata) per notificare lo stesso evento anche al Pixel lato browser,
// con lo stesso event_id passato al server — necessario per la deduplicazione.
function embedClientSideConversionEvent(string $eventName, string $eventId, ?array $profile = null): string {
    $ownId = trim(getProfileTracking($profile)['fb_pixel_id'] ?? '');
    if ($ownId === '' && (getSiteSetting('fb_pixel_id') ?: '') === '') {
        return '';
    }
    return "<script>if (typeof fbq === 'function') { fbq('track', '" . addslashes($eventName) . "', {}, {eventID: '" . addslashes($eventId) . "'}); }</script>";
}

// Ricomprime qualsiasi immagine (jpg/png/webp/gif...) in un JPEG che non superi $maxBytes,
// restituendo i byte già pronti da salvare (o null se $data non è un'immagine valida). Unico
// punto del sito che usa GD: prima le foto venivano salvate così come arrivavano (fino a
// qualche MB a testa); ora ogni copertina/foto caricata — moduli "che amo", blog, eventi, link,
// timeline, avatar — passa sempre da qui, così il disco non cresce senza controllo e ogni pagina
// pubblica carica più veloce. La trasparenza (PNG/WebP) viene appiattita su sfondo bianco: il
// JPEG non la supporta. Se la qualità più bassa non basta a stare sotto il limite, si riduce
// anche la dimensione e si riprova, fino a un minimo di 80px di lato.
function compressImageToJpeg(string $data, int $maxBytes = 256000, int $maxDimension = 1600): ?string {
    // Foto ad alta risoluzione (es. scattate con smartphone recenti, anche 20+ megapixel) possono
    // richiedere più degli attuali 128MB di default di PHP per essere decodificate ed elaborate —
    // alzato solo per la richiesta in corso, non è una modifica permanente al sito. Riscontrato
    // in produzione ("Allowed memory size of 134217728 bytes exhausted") sulla pagina che
    // ricomprime le foto già caricate, alcune delle quali molto pesanti.
    @ini_set('memory_limit', '512M');

    $info = @getimagesizefromstring($data);
    $img = @imagecreatefromstring($data);
    if ($img === false) {
        return null;
    }

    $width = imagesx($img);
    $height = imagesy($img);

    // Ridimensiona SUBITO se l'originale supera il necessario, prima di qualunque altra copia in
    // memoria: dimezza il picco di RAM dei passaggi successivi per le foto più pesanti (prima il
    // ridimensionamento avveniva dopo aver già duplicato l'immagine a piena risoluzione).
    $longSide = max($width, $height);
    if ($longSide > $maxDimension) {
        $scale = $maxDimension / $longSide;
        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($img);
        $img = $resized;
        $width = $newWidth;
        $height = $newHeight;
    }

    // La trasparenza (PNG/WebP) va appiattita su sfondo bianco: il JPEG non la supporta. Un JPEG
    // in ingresso non può averne, quindi si salta il passaggio — altro risparmio di memoria (e di
    // tempo) per il caso più comune, una foto già in JPEG.
    $isJpegSource = $info && $info[2] === IMAGETYPE_JPEG;
    if (!$isJpegSource) {
        $flat = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $img, 0, 0, 0, 0, $width, $height);
        imagedestroy($img);
        $img = $flat;
    }

    $qualities = [82, 74, 66, 58, 50, 42, 35, 28];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        foreach ($qualities as $q) {
            ob_start();
            imagejpeg($img, null, $q);
            $bytes = ob_get_clean();
            if (strlen($bytes) <= $maxBytes) {
                imagedestroy($img);
                return $bytes;
            }
        }
        $width = (int) round($width * 0.75);
        $height = (int) round($height * 0.75);
        if ($width < 80 || $height < 80) {
            break;
        }
        $resized = imagecreatetruecolor($width, $height);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $width, $height, imagesx($img), imagesy($img));
        imagedestroy($img);
        $img = $resized;
    }
    // Anche al minimo di qualità/dimensione resta sopra il limite (foto estremamente
    // "rumorosa"): si restituisce comunque il risultato migliore ottenuto, meglio di niente.
    ob_start();
    imagejpeg($img, null, 25);
    $bytes = ob_get_clean();
    imagedestroy($img);
    return $bytes;
}

// Gestisce l'upload di un'immagine di copertina (moduli "che amo", link, articoli blog, eventi,
// timeline). Restituisce il percorso relativo salvato (sempre .jpg, vedi compressImageToJpeg()),
// o null se non è stato caricato nessun file valido. Non lancia mai errori: un file mancante o
// non valido significa semplicemente "nessuna copertina".
function handleCoverUpload(string $slug, string $fileInputName = 'cover'): ?string {
    if (empty($_FILES[$fileInputName]['name']) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $raw = file_get_contents($_FILES[$fileInputName]['tmp_name']);
    if ($raw === false) {
        return null;
    }
    $jpeg = compressImageToJpeg($raw);
    if ($jpeg === null) {
        return null;
    }
    $fname = bin2hex(random_bytes(6)) . '.jpg';
    $dir = '/var/www/html/uploads/images/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $dest = $dir . '/' . $fname;
    if (file_put_contents($dest, $jpeg) !== false) {
        return 'uploads/images/' . $slug . '/' . $fname;
    }
    return null;
}

// Copia "per la condivisione social" della foto di copertina di un post con più foto — usata SOLO
// per og:image/Twitter card (Metricool e strumenti simili, per l'immagine del post automatico,
// leggono l'og:image della pagina permalink e non l'allegato RSS: vedi commento in feed.php), MAI
// per il carosello mostrato sul sito, che continua a mostrare le foto originali intatte. Ha senso
// solo quando ci sono più foto: sui social arriva sempre e solo questa singola immagine (mai un
// carosello), quindi la scritta segnala che ce ne sono altre da vedere nel link in descrizione.
// Generata una sola volta e tenuta in cache su disco accanto all'originale (rigenerata solo se
// l'originale cambia) — non un'elaborazione ad ogni visita/condivisione. Ritorna il percorso
// dell'originale, invariato, se la generazione fallisce per qualunque motivo (GD assente, font non
// trovato, file non leggibile...): un problema di stile non deve mai rompere la condivisione.
function getFeedShareImage(string $imagePath): string {
    $srcFile = '/var/www/html/' . $imagePath;
    if (!is_file($srcFile)) {
        return $imagePath;
    }

    $dir = dirname($imagePath);
    $base = pathinfo($imagePath, PATHINFO_FILENAME);
    $shareRelPath = ($dir !== '.' ? $dir . '/' : '') . $base . '__share.jpg';
    $shareFile = '/var/www/html/' . $shareRelPath;

    if (is_file($shareFile) && filemtime($shareFile) >= filemtime($srcFile)) {
        return $shareRelPath;
    }

    // Percorso assoluto fisso, NON relativo a __DIR__: in produzione (Dockerfile) src/ e public/
    // finiscono in due cartelle indipendenti, /var/www/src/ e /var/www/html/ — non sorelle come
    // nel checkout di sviluppo (entrambe dentro app/), dove "risali di uno da src/ ed entra in
    // public/" combacia per coincidenza. Bug reale riscontrato in produzione: il font veniva
    // cercato in /var/www/public/... (inesistente), mai trovato, testo mai disegnato — nessun
    // errore visibile, la funzione ricadeva in silenzio sulla foto originale.
    // Il percorso resta dentro "garden-anomaly" anche dopo la rimozione di quel tema: il file
    // del font è rimasto lì apposta (unico asset di quella cartella sopravvissuto), per non
    // dover spostare il font e aggiornare questo percorso hardcoded in più punti.
    $font = '/var/www/html/assets/themes/garden-anomaly/fonts/SpaceGrotesk-SemiBold.ttf';

    // function_exists('imagettftext') NON basta a garantire che funzioni davvero: la funzione
    // resta definita anche quando GD è stato compilato senza FreeType, e in quel caso fallisce
    // silenziosamente (warning + false) invece di generare un errore bloccante — da qui il
    // controllo esplicito su gd_info(), l'unico modo affidabile per saperlo in anticipo.
    $gdInfo = function_exists('gd_info') ? gd_info() : [];
    $hasFreeType = !empty($gdInfo['FreeType Support']);
    if (!$hasFreeType) {
        error_log('[getFeedShareImage] GD senza supporto FreeType: impossibile scrivere il testo, uso la foto originale per ' . $imagePath);
        return $imagePath;
    }
    if (!is_file($font)) {
        error_log('[getFeedShareImage] Font non trovato (' . $font . '), uso la foto originale per ' . $imagePath);
        return $imagePath;
    }

    $img = @imagecreatefromstring((string) file_get_contents($srcFile));
    if ($img === false) {
        error_log('[getFeedShareImage] Impossibile decodificare l\'immagine sorgente ' . $imagePath);
        return $imagePath;
    }
    imagealphablending($img, true);

    $width = imagesx($img);
    $height = imagesy($img);
    $text = 'Link Album in Descrizione';

    // Dimensione del testo proporzionale alla larghezza della foto (min/max per non diventare
    // illeggibile o sproporzionata su foto molto piccole o molto grandi), fascia semitrasparente
    // sotto per restare leggibile su qualunque sfondo.
    $fontSize = max(14, min(34, (int) round($width / 22)));
    $barHeight = (int) round($fontSize * 2.8);
    $overlay = imagecolorallocatealpha($img, 0, 0, 0, 40);
    imagefilledrectangle($img, 0, $height - $barHeight, $width, $height, $overlay);

    $white = imagecolorallocate($img, 255, 255, 255);
    $box = @imagettfbbox($fontSize, 0, $font, $text);
    if ($box === false) {
        error_log('[getFeedShareImage] imagettfbbox() fallita (font/FreeType non utilizzabile), uso la foto originale per ' . $imagePath);
        imagedestroy($img);
        return $imagePath;
    }
    $textWidth = $box[2] - $box[0];
    $x = (int) round(($width - $textWidth) / 2);
    $y = $height - (int) round($barHeight / 2) + (int) round($fontSize / 3);
    $drawn = @imagettftext($img, $fontSize, 0, $x, $y, $white, $font, $text);
    if ($drawn === false) {
        error_log('[getFeedShareImage] imagettftext() fallita, uso la foto originale per ' . $imagePath);
        imagedestroy($img);
        return $imagePath;
    }

    if (!is_dir(dirname($shareFile))) {
        @mkdir(dirname($shareFile), 0775, true);
    }
    $ok = imagejpeg($img, $shareFile, 88);
    imagedestroy($img);

    if (!$ok) {
        error_log('[getFeedShareImage] imagejpeg() non è riuscita a scrivere ' . $shareFile . ' (permessi/spazio disco?), uso la foto originale per ' . $imagePath);
    }

    return $ok ? $shareRelPath : $imagePath;
}

// Stato di getFeedShareImage(), leggibile dall'Area Admin senza bisogno di accesso alla shell del
// server (docker exec) — utile per chi aggiorna solo tramite Portainer collegato al repository e
// non ha un terminale a disposizione. Include un test dal vivo (genera davvero un'immagine di
// prova con GD, bypassando la cache) invece di limitarsi a controllare che i pezzi siano
// presenti: è l'unico modo per essere certi che il risultato finale abbia davvero la scritta,
// dato che GD può "sembrare" funzionante (nessun errore fatale) pur non riuscendo a disegnare
// testo se manca il supporto FreeType.
function diagnoseFeedShareImage(): array {
    $gdInfo = function_exists('gd_info') ? gd_info() : [];
    $hasFreeType = !empty($gdInfo['FreeType Support']);
    // Percorso assoluto fisso, NON relativo a __DIR__: in produzione (Dockerfile) src/ e public/
    // finiscono in due cartelle indipendenti, /var/www/src/ e /var/www/html/ — non sorelle come
    // nel checkout di sviluppo (entrambe dentro app/), dove "risali di uno da src/ ed entra in
    // public/" combacia per coincidenza. Bug reale riscontrato in produzione: il font veniva
    // cercato in /var/www/public/... (inesistente), mai trovato, testo mai disegnato — nessun
    // errore visibile, la funzione ricadeva in silenzio sulla foto originale.
    // "garden-anomaly" nel percorso è solo il nome della cartella: quel tema è stato rimosso,
    // il font no (vedi lo stesso percorso in getFeedShareImage() qui sopra).
    $font = '/var/www/html/assets/themes/garden-anomaly/fonts/SpaceGrotesk-SemiBold.ttf';
    $fontExists = is_file($font);

    $testRelPath = 'uploads/images/_diagnostica/test.jpg';
    $testFile = '/var/www/html/' . $testRelPath;
    $testDir = dirname($testFile);
    $previewUrl = null;

    if ($hasFreeType && $fontExists) {
        if (!is_dir($testDir) && !@mkdir($testDir, 0775, true)) {
            return [
                'has_freetype' => $hasFreeType, 'font_exists' => $fontExists,
                'preview_url' => null, 'error' => 'Impossibile creare la cartella ' . $testDir . ' (permessi di scrittura?).',
            ];
        }
        $img = imagecreatetruecolor(600, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 70, 130, 180));
        imagejpeg($img, $testFile, 90);
        imagedestroy($img);
        // Bypassa la cache di getFeedShareImage() apposta: qui serve rigenerare SEMPRE, per
        // riflettere lo stato reale in questo momento, non un tentativo precedente (magari con
        // una versione più vecchia del codice, o con GD in uno stato diverso).
        @unlink(dirname($testFile) . '/test__share.jpg');
        $resultPath = getFeedShareImage($testRelPath);
        $previewUrl = assetUrl('/' . $resultPath);
    }

    return [
        'has_freetype' => $hasFreeType,
        'font_exists' => $fontExists,
        'preview_url' => $previewUrl,
        'error' => null,
    ];
}

// Ripulisce tutte le copie "per la condivisione social" già generate (vedi getFeedShareImage()):
// serve a forzare la rigenerazione di tutte quelle esistenti dopo una correzione al codice che le
// genera, dato che la cache su disco altrimenti le considera valide finché la FOTO ORIGINALE non
// cambia — non capisce da sola che è cambiata la logica di generazione. Raggiungibile dall'Area
// Admin, così non serve un accesso diretto al server (docker exec) per pulirle. Ritorna quante ne
// ha cancellate.
function clearFeedShareImageCache(): int {
    $count = 0;
    $files = glob('/var/www/html/uploads/images/*/*__share.jpg') ?: [];
    foreach ($files as $file) {
        if (@unlink($file)) {
            $count++;
        }
    }
    return $count;
}

// Upload multiplo (fino a $maxFiles foto) — usato SOLO dai post Timeline con più foto (carosello
// stile Instagram): la prima foto resta salvata come al solito su timeline_posts.image_path
// (quella che compare nel Feed), le eventuali altre finiscono in timeline_post_photos. Ogni file
// passa dalla stessa compressione di handleCoverUpload() (sempre .jpg, mai oltre 250KB); un file
// non valido viene scartato in silenzio senza bloccare gli altri. Restituisce l'elenco dei
// percorsi relativi salvati, nell'ordine di caricamento (array vuoto se nessun file valido).
function handleMultiCoverUpload(string $slug, string $fileInputName, int $maxFiles = 10): array {
    if (empty($_FILES[$fileInputName]['name']) || !is_array($_FILES[$fileInputName]['name'])) {
        return [];
    }
    $files = $_FILES[$fileInputName];
    $dir = '/var/www/html/uploads/images/' . $slug;
    $paths = [];
    for ($i = 0; $i < count($files['name']) && count($paths) < $maxFiles; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $raw = file_get_contents($files['tmp_name'][$i]);
        if ($raw === false) {
            continue;
        }
        $jpeg = compressImageToJpeg($raw);
        if ($jpeg === null) {
            continue;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fname = bin2hex(random_bytes(6)) . '.jpg';
        if (file_put_contents($dir . '/' . $fname, $jpeg) !== false) {
            $paths[] = 'uploads/images/' . $slug . '/' . $fname;
        }
    }
    return $paths;
}

// Foto aggiuntive (dalla 2 alla 10) di un post Timeline, nell'ordine di caricamento — vedi
// handleMultiCoverUpload(). La prima foto non è qui: resta su timeline_posts.image_path.
function getTimelinePostPhotos(int $postId): array {
    $stmt = getDB()->prepare('SELECT image_path FROM timeline_post_photos WHERE post_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$postId]);
    return array_column($stmt->fetchAll(), 'image_path');
}

// Come getTimelinePostPhotos(), ma per il modulo "Viaggi che amo" (fan_favorite_trips): foto
// aggiuntive (dalla 2 alla 10) di un viaggio, nell'ordine di caricamento. La prima foto non è
// qui: resta su fan_favorite_trips.image_path, come sempre.
function getTripPhotos(int $tripId): array {
    $stmt = getDB()->prepare('SELECT image_path FROM fan_favorite_trip_photos WHERE trip_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$tripId]);
    return array_column($stmt->fetchAll(), 'image_path');
}

// Come getTimelinePostPhotos()/getTripPhotos(), per il modulo "Album" (photo_albums): foto
// aggiuntive (dalla 2 alla 50) di un album, nell'ordine di caricamento. La prima foto non è qui:
// resta su photo_albums.cover_path, come sempre.
function getAlbumPhotos(int $albumId): array {
    $stmt = getDB()->prepare('SELECT image_path FROM photo_album_photos WHERE album_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$albumId]);
    return array_column($stmt->fetchAll(), 'image_path');
}

// Tutte le foto dei post Timeline pubblici e già pubblicati di un profilo (la copertina di
// ciascun post più le eventuali foto extra del carosello), più recenti prima — usata dalla
// sezione pubblica "Foto" (foto.php), che è una semplice VETRINA aggregata: nessuna tabella
// propria, nessun dato nuovo da gestire, solo le foto già presenti nei post Timeline. Ogni foto
// resta collegata al post di provenienza (post_id), per il link di apertura.
function getPublicTimelinePhotos(int $userId, int $limit = 200): array {
    $stmt = getDB()->prepare("
        (SELECT tp.id AS post_id, tp.image_path AS photo, COALESCE(tp.publish_at, tp.created_at) AS data
         FROM timeline_posts tp
         WHERE tp.user_id = ? AND tp.visibility = 'public' AND (tp.publish_at IS NULL OR tp.publish_at <= NOW())
           AND tp.image_path IS NOT NULL)
        UNION ALL
        (SELECT tpp.post_id, tpp.image_path AS photo, COALESCE(tp.publish_at, tp.created_at) AS data
         FROM timeline_post_photos tpp
         JOIN timeline_posts tp ON tp.id = tpp.post_id
         WHERE tp.user_id = ? AND tp.visibility = 'public' AND (tp.publish_at IS NULL OR tp.publish_at <= NOW()))
        ORDER BY data DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Come getAlbumPhotos()/getTimelinePostPhotos(), per il modulo "Servizi" (galleria fotografica
// del singolo servizio). La prima foto resta su services.cover_path, come sempre.
function getServicePhotos(int $serviceId): array {
    $stmt = getDB()->prepare('SELECT image_path FROM service_photos WHERE service_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$serviceId]);
    return array_column($stmt->fetchAll(), 'image_path');
}

// Vero se il profilo ha almeno un servizio pubblico e valido — usata per decidere se mostrare il
// tab "Servizi" nel menu pubblico, stesso criterio di menuHasItems()/hasActiveOffers().
function hasVisibleServices(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM services
        WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

// Notifica al titolare del profilo quando qualcuno richiede informazioni su un servizio — stessa
// struttura di notifyNewContact(), con in più quale servizio e il telefono (se lasciato).
function notifyServiceInquiry(string $toEmail, string $toName, string $serviceTitle, string $guestName, string $guestEmail, ?string $guestPhone, ?string $message, string $publicUrl): void {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "Richiesta informazioni su \"{$serviceTitle}\" da {$guestName} su " . siteName();
    $body = "Hai ricevuto una richiesta di informazioni sul servizio \"{$serviceTitle}\" dalla tua pagina {$publicUrl}:\n\n"
          . "Nome: {$guestName}\n"
          . "Email: {$guestEmail}\n"
          . ($guestPhone ? "Telefono: {$guestPhone}\n" : '')
          . ($message ? "\nMessaggio:\n{$message}\n" : '')
          . "\n---\nRispondi direttamente a questa email per contattare {$guestName},\n"
          . "oppure gestisci tutte le richieste dalla tua dashboard su " . siteName() . ".";

    $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}

// Vero se il profilo ha almeno una foto pubblica da mostrare nella sezione "Foto" — un post
// Timeline pubblico con almeno una foto, o un album pubblico con almeno una foto — usata per
// decidere se mostrare il tab "Foto" nel menu pubblico, stesso criterio di menuHasItems()/
// hasActiveOffers() per gli altri moduli.
function hasPublicPhotoContent(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM timeline_posts
        WHERE user_id = ? AND visibility = 'public' AND (publish_at IS NULL OR publish_at <= NOW()) AND image_path IS NOT NULL");
    $stmt->execute([$userId]);
    if ((int) $stmt->fetch()['c'] > 0) {
        return true;
    }
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM photo_albums
        WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW()) AND cover_path IS NOT NULL");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}

// Elimina il file di copertina dal disco, se presente (usato quando si elimina un link/post/evento)
function deleteCoverFile(?string $coverPath): void {
    if ($coverPath) {
        @unlink('/var/www/html/' . $coverPath);
    }
}

// Ripulisce l'eventuale copia "per la condivisione social" (vedi getFeedShareImage()) generata per
// questa foto, se ne esiste una — va richiamata insieme a deleteCoverFile() ogni volta che
// l'originale viene eliminato, altrimenti resterebbe un file orfano sul disco.
function deleteFeedShareImage(?string $coverPath): void {
    if (!$coverPath) {
        return;
    }
    $dir = dirname($coverPath);
    $base = pathinfo($coverPath, PATHINFO_FILENAME);
    @unlink('/var/www/html/' . ($dir !== '.' ? $dir . '/' : '') . $base . '__share.jpg');
}

// Elimina UNA foto sola di un contenuto con più foto (post Timeline, Viaggio, Album, Servizio),
// senza toccare le altre né dover ricaricare l'intero set come nell'unica modalità finora
// disponibile in modifica. $photoId vale 0 per la copertina, altrimenti l'id della riga nella
// tabella delle foto extra ($childTable). Eliminare proprio la copertina promuove la prima foto
// extra rimasta al suo posto (un contenuto ha sempre bisogno di almeno una foto per essere
// mostrato in Home/Feed/RSS) — se non ce n'è nessuna, l'eliminazione viene rifiutata.
function deleteSingleGalleryPhoto(string $parentTable, string $coverColumn, string $childTable, string $parentIdColumn, int $parentId, int $userId, int $photoId, ?string $coverThumbColumn = null): array {
    $db = getDB();
    $selectCols = $coverThumbColumn ? "{$coverColumn} AS cover, {$coverThumbColumn} AS cover_thumb" : "{$coverColumn} AS cover";
    $stmt = $db->prepare("SELECT {$selectCols} FROM {$parentTable} WHERE id=? AND user_id=?");
    $stmt->execute([$parentId, $userId]);
    $parent = $stmt->fetch();
    if (!$parent) {
        return ['ok' => false, 'error' => 'Non trovato.'];
    }

    if ($photoId === 0) {
        $stmt = $db->prepare("SELECT id, image_path FROM {$childTable} WHERE {$parentIdColumn}=? ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$parentId]);
        $nextPhoto = $stmt->fetch();
        if (!$nextPhoto) {
            return ['ok' => false, 'error' => 'Non puoi eliminare l\'unica foto rimasta — carica una nuova foto per sostituirla.'];
        }
        deleteCoverFile($parent['cover']);
        deleteFeedShareImage($parent['cover']);
        if ($coverThumbColumn) {
            deleteCoverFile($parent['cover_thumb'] ?? null);
            $db->prepare("UPDATE {$parentTable} SET {$coverColumn}=?, {$coverThumbColumn}=NULL WHERE id=?")->execute([$nextPhoto['image_path'], $parentId]);
        } else {
            $db->prepare("UPDATE {$parentTable} SET {$coverColumn}=? WHERE id=?")->execute([$nextPhoto['image_path'], $parentId]);
        }
        $db->prepare("DELETE FROM {$childTable} WHERE id=?")->execute([$nextPhoto['id']]);
        return ['ok' => true];
    }

    $stmt = $db->prepare("SELECT image_path FROM {$childTable} WHERE id=? AND {$parentIdColumn}=?");
    $stmt->execute([$photoId, $parentId]);
    $photo = $stmt->fetch();
    if (!$photo) {
        return ['ok' => false, 'error' => 'Foto non trovata.'];
    }
    deleteCoverFile($photo['image_path']);
    $db->prepare("DELETE FROM {$childTable} WHERE id=?")->execute([$photoId]);
    return ['ok' => true];
}

// ===== Segui tra account (diverso da "Segui via email") =====

function isFollowingAccount(int $followerId, int $followedId): bool {
    $stmt = getDB()->prepare('SELECT id FROM account_follows WHERE follower_user_id=? AND followed_user_id=?');
    $stmt->execute([$followerId, $followedId]);
    return (bool) $stmt->fetch();
}

function getFollowedUserIds(int $userId): array {
    $stmt = getDB()->prepare('SELECT followed_user_id FROM account_follows WHERE follower_user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'followed_user_id'));
}

function getAccountFollowerCount(int $userId): int {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM account_follows WHERE followed_user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'];
}

// Fusi orari selezionabili dal profilo (Dashboard → Profilo e anagrafica) per correggere la
// visualizzazione di date/orari quando il server non è nello stesso fuso dei visitatori — elenco
// curato (non tutti i ~596 nomi IANA) apposta: la chiave va nella colonna profiles.dashboard_theme
// (VARCHAR(10)), un campo rimasto inutilizzato da quando la scelta del tema dashboard chiaro/scuro
// è stata abbandonata (vedi nota in _dash_header.php) — riusarlo evita una migrazione del database
// per una nuova colonna. Il valore è il vero nome IANA, usato con DateTimeZone: gestisce
// correttamente anche l'ora legale, a differenza di un semplice offset fisso.
const TIMEZONE_OPTIONS = [
    'rome' => ['tz' => 'Europe/Rome', 'label' => 'Italia (Roma)'],
    'london' => ['tz' => 'Europe/London', 'label' => 'Regno Unito (Londra)'],
    'paris' => ['tz' => 'Europe/Paris', 'label' => 'Francia (Parigi)'],
    'madrid' => ['tz' => 'Europe/Madrid', 'label' => 'Spagna (Madrid)'],
    'berlin' => ['tz' => 'Europe/Berlin', 'label' => 'Germania (Berlino)'],
    'lisbon' => ['tz' => 'Europe/Lisbon', 'label' => 'Portogallo (Lisbona)'],
    'athens' => ['tz' => 'Europe/Athens', 'label' => 'Grecia (Atene)'],
    'moscow' => ['tz' => 'Europe/Moscow', 'label' => 'Russia (Mosca)'],
    'newyork' => ['tz' => 'America/New_York', 'label' => 'USA Est (New York)'],
    'chicago' => ['tz' => 'America/Chicago', 'label' => 'USA Centrale (Chicago)'],
    'denver' => ['tz' => 'America/Denver', 'label' => 'USA Montagne (Denver)'],
    'la' => ['tz' => 'America/Los_Angeles', 'label' => 'USA Pacifico (Los Angeles)'],
    'saopaulo' => ['tz' => 'America/Sao_Paulo', 'label' => 'Brasile (San Paolo)'],
    'dubai' => ['tz' => 'Asia/Dubai', 'label' => 'Emirati (Dubai)'],
    'mumbai' => ['tz' => 'Asia/Kolkata', 'label' => 'India (Mumbai)'],
    'tokyo' => ['tz' => 'Asia/Tokyo', 'label' => 'Giappone (Tokyo)'],
    'shanghai' => ['tz' => 'Asia/Shanghai', 'label' => 'Cina (Shanghai)'],
    'sydney' => ['tz' => 'Australia/Sydney', 'label' => 'Australia (Sydney)'],
    'utc' => ['tz' => 'UTC', 'label' => 'UTC'],
];

// Chiave del fuso orario scelto dal profilo, con fallback a "rome": quasi tutti i profili di
// questa piattaforma sono italiani, ed è il fuso più probabile a "correggere" subito l'orario per
// chi non ha ancora impostato nulla (valore di default della colonna, prima 'dark', non è una
// chiave valida di TIMEZONE_OPTIONS e ricade quindi qui).
function profileTimezoneKey(?array $profile): string {
    $key = is_array($profile) ? ($profile['dashboard_theme'] ?? '') : '';
    return isset(TIMEZONE_OPTIONS[$key]) ? $key : 'rome';
}

function profileTimezoneName(?array $profile): string {
    return TIMEZONE_OPTIONS[profileTimezoneKey($profile)]['tz'];
}

// Converte una data/ora salvata nel database (interpretata nel fuso orario con cui il server PHP
// l'ha scritta — quello restituito da date_default_timezone_get(), lo stesso usato da NOW()/
// CURRENT_TIMESTAMP/date() ovunque nel codice) nel fuso orario scelto dal profilo, SOLO per la
// visualizzazione: non tocca mai il valore salvato. Nata da una segnalazione utente ("l'orario è
// sballato, forse perché il server non è in Italia") — prima ogni pagina ristampava la data così
// com'è, nel fuso del server, chiunque fosse il proprietario del profilo.
function formatLocalDateTime(?string $datetime, ?array $profile, string $format = 'd/m/Y H:i'): string {
    if (!$datetime) {
        return '';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
        return $dt->format($format);
    } catch (Exception $e) {
        return date($format, strtotime($datetime));
    }
}

// Nomi dei mesi in italiano: il sito non ha mai avuto bisogno finora di stampare un mese per
// esteso (solo date numeriche, d/m/Y), quindi non c'è un formattatore locale già pronto — niente
// setlocale()/intl (dipenderebbero da cosa ha installato il container), un semplice array basta.
const ITALIAN_MONTHS = [1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

// Etichetta "Mese Anno" (es. "Settembre 2026") di un datetime nel fuso del profilo — usata per
// raggruppare contenuti per mese su una pagina pubblica (es. /slug/viaggi). Va di pari passo con
// monthYearGroupKey() qui sotto: stessa conversione di fuso orario, la chiave serve a raggruppare
// senza rifare il calcolo, l'etichetta è solo per la stampa.
function monthYearLabel(?string $datetime, ?array $profile): string {
    if (!$datetime) {
        return '';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
    } catch (Exception $e) {
        $dt = new DateTime(date('Y-m-d H:i:s', strtotime($datetime) ?: time()));
    }
    return ITALIAN_MONTHS[(int) $dt->format('n')] . ' ' . $dt->format('Y');
}

// Chiave ordinabile "AAAA-MM" per raggruppare per mese (vedi monthYearLabel()) — stesso fuso
// orario del profilo, così un contenuto salvato a cavallo di mezzanotte finisce nel mese giusto
// dal punto di vista di chi lo guarda, non del server.
function monthYearGroupKey(?string $datetime, ?array $profile): string {
    if (!$datetime) {
        return '';
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
        $dt->setTimezone(new DateTimeZone(profileTimezoneName($profile)));
    } catch (Exception $e) {
        $dt = new DateTime(date('Y-m-d H:i:s', strtotime($datetime) ?: time()));
    }
    return $dt->format('Y-m');
}

// Inversa di formatLocalDateTime(): un valore digitato in un campo datetime-local del browser
// (es. "programma la pubblicazione", validità di un'offerta, data di un evento) è l'orario "a
// muro" di CHI lo sta scrivendo in quel momento — MAI il fuso del server. Va quindi convertito
// nella direzione opposta prima di salvarlo, altrimenti un confronto con NOW() (scritto dal
// server nel SUO fuso) è sfalsato dello scarto tra i due fusi.
//
// $browserOffsetMinutes, quando disponibile, ha SEMPRE la precedenza sul fuso del profilo:
// riflette l'orologio reale del dispositivo di chi sta scrivendo in questo istante (da
// JavaScript, new Date().getTimezoneOffset()) — il fuso salvato sul profilo descrive invece come
// va MOSTRATO il contenuto pubblicato, che non è necessariamente dove si trova chi lo gestisce in
// un momento specifico (es. in viaggio all'estero: il profilo di un'attività italiana resta in
// orario italiano per il pubblico, ma chi digita una data in quel momento pensa al proprio
// orologio locale, non a quello del profilo). Il fuso del profilo resta solo come fallback per
// gli inserimenti senza JavaScript.
//
// Ritorna null se l'input è vuoto o non interpretabile.
function parseLocalDateTime(?string $input, ?array $profile, ?int $browserOffsetMinutes = null): ?string {
    $input = trim((string) $input);
    if ($input === '') {
        return null;
    }
    try {
        if ($browserOffsetMinutes !== null) {
            // Convenzione JavaScript: getTimezoneOffset() = minuti (UTC - locale), positivo per i
            // fusi indietro rispetto a UTC — quindi UTC = digitato + offset.
            $dt = new DateTime($input, new DateTimeZone('UTC'));
            $dt->modify(sprintf('%+d minutes', $browserOffsetMinutes));
        } else {
            $dt = new DateTime($input, new DateTimeZone(profileTimezoneName($profile)));
        }
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $ts = strtotime($input);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}

// Legge l'offset di fuso orario inviato da JavaScript (vedi commento sopra) da $_POST, se
// presente e numerico — helper condiviso per non ripetere il parsing in ogni pagina che gestisce
// un campo datetime-local.
function browserTzOffsetFromRequest(): ?int {
    $raw = $_POST['tz_offset_minutes'] ?? null;
    return ($raw !== null && $raw !== '' && is_numeric($raw)) ? (int) $raw : null;
}

// Data/ora di pubblicazione mostrata pubblicamente per qualunque tipo di post/elemento
// (Timeline, Che Amo, Viaggi, Brani...): publish_at se impostato, altrimenti created_at — stesso
// criterio già usato per raggruppare "la stessa giornata" (getSameDayFavorites/
// getSameDayTimelinePosts), qui invece per mostrare la data e l'ora per intero, nel fuso orario
// del profilo (vedi formatLocalDateTime()).
function publishedAtLabel(?string $publishAt, string $createdAt, ?array $profile = null): string {
    return formatLocalDateTime($publishAt ?: $createdAt, $profile);
}

// Altri elementi dello stesso modulo "che amo" pubblicati/aggiunti nello stesso giorno di un
// elemento — usata nelle pagine di dettaglio condivisibili (fan_favorite_item.php,
// favorite_track_item.php, viaggio_item.php): chi arriva da un link personale a UNA foto/
// elemento specifico vede subito sotto anche tutti gli altri dello stesso giorno, senza dover
// andare a sfogliare la Timeline. Il "giorno" è quello effettivo di comparsa nel feed
// (publish_at se impostato, altrimenti created_at) — stesso criterio di visibilità già usato
// da getTimelineFeedForUsers() (pubblicato E non nascosto), per non far trapelare tramite un
// link altrui elementi che il proprietario ha scelto di non mostrare nel feed.
function getSameDayFavorites(string $table, int $userId, ?string $referencePublishAt, string $referenceCreatedAt, int $excludeId): array {
    $refDate = substr($referencePublishAt ?: $referenceCreatedAt, 0, 10);
    $stmt = getDB()->prepare("SELECT * FROM {$table}
        WHERE user_id = ? AND id != ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())
          AND DATE(COALESCE(publish_at, created_at)) = ?
        ORDER BY COALESCE(publish_at, created_at) ASC, id ASC");
    $stmt->execute([$userId, $excludeId, $refDate]);
    return $stmt->fetchAll();
}

// Come getSameDayFavorites(), ma per i post "Timeline"/Aggiornamenti (timeline_posts) — usata in
// timeline_post.php: chi apre un post specifico vede subito sotto, come una sequenza di post
// uguali, anche tutti gli altri pubblicati la stessa giornata. Schema diverso da quello dei
// moduli "che amo" (visibility ENUM invece di is_public), da qui una funzione a parte invece
// di riusare quella.
function getSameDayTimelinePosts(int $userId, ?string $referencePublishAt, string $referenceCreatedAt, int $excludeId): array {
    $refDate = substr($referencePublishAt ?: $referenceCreatedAt, 0, 10);
    $stmt = getDB()->prepare('SELECT * FROM timeline_posts
        WHERE user_id = ? AND id != ? AND visibility = "public" AND (publish_at IS NULL OR publish_at <= NOW())
          AND DATE(COALESCE(publish_at, created_at)) = ?
        ORDER BY COALESCE(publish_at, created_at) ASC, id ASC');
    $stmt->execute([$userId, $excludeId, $refDate]);
    return $stmt->fetchAll();
}

// Blocco foto di un elemento condivisibile (post Timeline, viaggio...): una foto singola (come
// sempre) oppure, se ce n'è più di una, un carosello scorrevole con un pulsante per aprirlo a
// tutto schermo. Usata sia per l'elemento principale di una pagina sia per ciascun "altro della
// stessa giornata" mostrato sotto, quindi ogni istanza porta il proprio $itemId nell'attributo
// data-post: sulla stessa pagina possono comparirne più di una, servono id univoci perché lo
// script condiviso (assets/js/ig-carousel.js) le trovi una per una senza confondersi.
function renderPhotoCarousel(array $photos, int $itemId): string {
    if (!$photos) {
        return '';
    }
    if (count($photos) === 1) {
        return '<img src="/' . e($photos[0]) . '" alt=""'
             . ' style="width:100%;max-width:400px;display:block;margin:0 auto 16px;border-radius:14px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.15);">';
    }

    $slides = '';
    foreach ($photos as $ph) {
        $slides .= '<img src="/' . e($ph) . '" alt="" loading="lazy">';
    }
    $dots = '';
    foreach ($photos as $i => $ph) {
        $dots .= '<span class="ig-dot' . ($i === 0 ? ' active' : '') . '" data-index="' . $i . '"></span>';
    }

    $html = '<div class="ig-carousel" data-post="' . $itemId . '">'
          . '<button type="button" class="ig-expand-btn" aria-label="Vedi a tutto schermo"><i class="fa-solid fa-expand"></i></button>'
          . '<div class="ig-carousel-track">' . $slides . '</div>'
          . '<button type="button" class="ig-arrow ig-arrow-prev" aria-label="Foto precedente">‹</button>'
          . '<button type="button" class="ig-arrow ig-arrow-next" aria-label="Foto successiva">›</button>'
          . '<div class="ig-carousel-dots">' . $dots . '</div>'
          . '</div>';

    $html .= '<div class="ig-lightbox" data-post="' . $itemId . '">'
          . '<button type="button" class="ig-lightbox-close" aria-label="Chiudi">✕</button>'
          . '<div class="ig-lightbox-track">' . $slides . '</div>'
          . '<button type="button" class="ig-arrow ig-arrow-prev" aria-label="Foto precedente">‹</button>'
          . '<button type="button" class="ig-arrow ig-arrow-next" aria-label="Foto successiva">›</button>'
          . '<div class="ig-carousel-dots ig-lightbox-dots">' . $dots . '</div>'
          . '</div>';

    return $html;
}

// Feed aggregato "Timeline": unisce blog, brani, eventi e aggiornamenti brevi pubblicati dai
// profili indicati, ordinati dal più recente. Query separate per tipo di contenuto invece di
// una UNION, più semplice da leggere e mantenere con colonne diverse per ciascuna.
function getTimelineFeedForUsers(array $userIds, int $limit = 50, int $offset = 0): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $db = getDB();
    $items = [];

    $stmt = $db->prepare("SELECT b.title, b.cover_path, b.slug, b.published_at, b.published_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM blog_posts b JOIN users u ON u.id = b.user_id JOIN profiles p ON p.user_id = u.id
        WHERE b.user_id IN ($placeholders) AND b.published_at <= NOW() ORDER BY b.published_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'tipo' => 'blog', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => blogPostUrl($r['user_slug'], $r),
        ];
    }

    $stmt = $db->prepare("SELECT tr.id, tr.track_name, tr.track_image, tr.artist_name, tr.note, tr.image_path, tr.image_thumb_path, tr.in_feed, tr.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM favorite_tracks tr JOIN users u ON u.id = tr.user_id JOIN profiles p ON p.user_id = u.id
        WHERE tr.user_id IN ($placeholders) AND tr.is_public = 1 AND (tr.publish_at IS NULL OR tr.publish_at <= NOW()) ORDER BY tr.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $brTitolo = $r['track_name'] . ' — ' . $r['artist_name'];
        if (trim($r['note'] ?? '') !== '') {
            $brTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'brano', 'titolo' => $brTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['track_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/brani/' . $r['id'] . '/scheda',
        ];
    }

    $stmt = $db->prepare("SELECT e.id, e.title, e.cover_path, e.created_at AS data, e.event_date, e.is_perpetual, e.recurrence, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM events e JOIN users u ON u.id = e.user_id JOIN profiles p ON p.user_id = u.id
        WHERE e.user_id IN ($placeholders) ORDER BY e.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'tipo' => 'evento', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'evento_quando' => $r['event_date'], 'evento_is_perpetual' => $r['is_perpetual'], 'evento_recurrence' => $r['recurrence'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/eventi/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT tp.id, tp.testo, tp.image_path, tp.image_thumb_path, tp.in_feed, tp.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM timeline_posts tp JOIN users u ON u.id = tp.user_id JOIN profiles p ON p.user_id = u.id
        WHERE tp.user_id IN ($placeholders) AND tp.visibility = 'public' AND (tp.publish_at IS NULL OR tp.publish_at <= NOW())
        ORDER BY tp.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    $pensieroRows = $stmt->fetchAll();
    // Quanti post hanno più di una foto (carosello) — serve solo a feed.php, per sapere quali
    // devono esporre nel feed RSS/Metricool la versione con "Link Album in Descrizione" invece
    // della foto nuda (vedi getFeedShareImage()). Una sola query qui invece che una per post; il
    // calcolo VERO e proprio della versione con scritta resta rimandato a feed.php, e solo per i
    // pochi elementi davvero mostrati (raw_image_path/has_multi_photo sono solo dati, non
    // generano nulla su disco) — non ha senso rigenerarla ad ogni caricamento di Feed/Timeline
    // per elementi che nessuno vedrà.
    $pensieroPhotoCounts = [];
    $pensieroIds = array_column($pensieroRows, 'id');
    if ($pensieroIds) {
        $ph = implode(',', array_fill(0, count($pensieroIds), '?'));
        $cs = $db->prepare("SELECT post_id, COUNT(*) c FROM timeline_post_photos WHERE post_id IN ($ph) GROUP BY post_id");
        $cs->execute($pensieroIds);
        foreach ($cs->fetchAll() as $c) {
            $pensieroPhotoCounts[(int) $c['post_id']] = (int) $c['c'];
        }
    }
    foreach ($pensieroRows as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'pensiero', 'titolo' => $r['testo'] ? textExcerpt($r['testo'], 100) : '📷 Foto', 'cover' => $r['image_path'],
            'cover_thumb' => $r['image_thumb_path'] ?: $r['image_path'], 'data' => $r['data'],
            'raw_image_path' => $r['image_path'], 'has_multi_photo' => !empty($pensieroPhotoCounts[(int) $r['id']]),
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/timeline/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fb.id, fb.spotify_artist_name, fb.artist_image, fb.note, fb.image_path, fb.image_thumb_path, fb.in_feed, fb.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_bands fb JOIN users u ON u.id = fb.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fb.user_id IN ($placeholders) AND fb.is_public = 1 AND (fb.publish_at IS NULL OR fb.publish_at <= NOW()) ORDER BY fb.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fbTitolo = $r['spotify_artist_name'];
        if (trim($r['note'] ?? '') !== '') {
            $fbTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'band_favorita', 'titolo' => $fbTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['artist_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/band-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fa.id, fa.actor_name, fa.actor_image, fa.note, fa.image_path, fa.image_thumb_path, fa.in_feed, fa.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_actors fa JOIN users u ON u.id = fa.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fa.user_id IN ($placeholders) AND fa.is_public = 1 AND (fa.publish_at IS NULL OR fa.publish_at <= NOW()) ORDER BY fa.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $faTitolo = $r['actor_name'];
        if (trim($r['note'] ?? '') !== '') {
            $faTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'attore_favorito', 'titolo' => $faTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['actor_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/attori-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fm.id, fm.movie_title, fm.movie_image, fm.note, fm.image_path, fm.image_thumb_path, fm.in_feed, fm.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_movies fm JOIN users u ON u.id = fm.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fm.user_id IN ($placeholders) AND fm.is_public = 1 AND (fm.publish_at IS NULL OR fm.publish_at <= NOW()) ORDER BY fm.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fmTitolo = $r['movie_title'];
        if (trim($r['note'] ?? '') !== '') {
            $fmTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'film_favorito', 'titolo' => $fmTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['movie_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/film-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fk.id, fk.book_title, fk.book_image, fk.note, fk.image_path, fk.image_thumb_path, fk.in_feed, fk.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_books fk JOIN users u ON u.id = fk.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fk.user_id IN ($placeholders) AND fk.is_public = 1 AND (fk.publish_at IS NULL OR fk.publish_at <= NOW()) ORDER BY fk.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fkTitolo = $r['book_title'];
        if (trim($r['note'] ?? '') !== '') {
            $fkTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'libro_favorito', 'titolo' => $fkTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['book_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/libri-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT ft.id, ft.place_name, ft.map_image_path, ft.note, ft.image_path, ft.image_thumb_path, ft.in_feed, ft.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_trips ft JOIN users u ON u.id = ft.user_id JOIN profiles p ON p.user_id = u.id
        WHERE ft.user_id IN ($placeholders) AND ft.is_public = 1 AND (ft.publish_at IS NULL OR ft.publish_at <= NOW()) ORDER BY ft.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    $tripRows = $stmt->fetchAll();
    // Vedi commento sopra (blocco "pensiero"): stesso calcolo, per i viaggi con più foto.
    $tripPhotoCounts = [];
    $tripIds = array_column($tripRows, 'id');
    if ($tripIds) {
        $ph = implode(',', array_fill(0, count($tripIds), '?'));
        $cs = $db->prepare("SELECT trip_id, COUNT(*) c FROM fan_favorite_trip_photos WHERE trip_id IN ($ph) GROUP BY trip_id");
        $cs->execute($tripIds);
        foreach ($cs->fetchAll() as $c) {
            $tripPhotoCounts[(int) $c['trip_id']] = (int) $c['c'];
        }
    }
    foreach ($tripRows as $r) {
        $ftTitolo = $r['place_name'];
        if (trim($r['note'] ?? '') !== '') {
            $ftTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'viaggio_favorito', 'titolo' => $ftTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['map_image_path']), 'data' => $r['data'],
            'raw_image_path' => $r['image_path'], 'has_multi_photo' => !empty($tripPhotoCounts[(int) $r['id']]),
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/viaggi/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fp.id, fp.playlist_name, fp.playlist_image, fp.note, fp.image_path, fp.image_thumb_path, fp.in_feed, fp.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_playlists fp JOIN users u ON u.id = fp.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fp.user_id IN ($placeholders) AND fp.is_public = 1 AND (fp.publish_at IS NULL OR fp.publish_at <= NOW()) ORDER BY fp.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fpTitolo = $r['playlist_name'];
        if (trim($r['note'] ?? '') !== '') {
            $fpTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'playlist_favorita', 'titolo' => $fpTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['playlist_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/playlist-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fal.id, fal.album_name, fal.album_artist_name, fal.album_image, fal.note, fal.image_path, fal.image_thumb_path, fal.in_feed, fal.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_albums fal JOIN users u ON u.id = fal.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fal.user_id IN ($placeholders) AND fal.is_public = 1 AND (fal.publish_at IS NULL OR fal.publish_at <= NOW()) ORDER BY fal.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $falTitolo = $r['album_name'] . ($r['album_artist_name'] ? ' — ' . $r['album_artist_name'] : '');
        if (trim($r['note'] ?? '') !== '') {
            $falTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'album_favorito', 'titolo' => $falTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['album_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/album-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fr.id, fr.recipe_title, fr.recipe_image, fr.note, fr.image_path, fr.image_thumb_path, fr.in_feed, fr.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_recipes fr JOIN users u ON u.id = fr.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fr.user_id IN ($placeholders) AND fr.is_public = 1 AND (fr.publish_at IS NULL OR fr.publish_at <= NOW()) ORDER BY fr.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $frTitolo = $r['recipe_title'];
        if (trim($r['note'] ?? '') !== '') {
            $frTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'ricetta_favorita', 'titolo' => $frTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['recipe_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/ricette-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT ft.id, ft.team_name, ft.team_badge, ft.note, ft.image_path, ft.image_thumb_path, ft.in_feed, ft.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_teams ft JOIN users u ON u.id = ft.user_id JOIN profiles p ON p.user_id = u.id
        WHERE ft.user_id IN ($placeholders) AND ft.is_public = 1 AND (ft.publish_at IS NULL OR ft.publish_at <= NOW()) ORDER BY ft.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $ftTitolo = $r['team_name'];
        if (trim($r['note'] ?? '') !== '') {
            $ftTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'squadra_favorita', 'titolo' => $ftTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['team_badge']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/squadre-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fp.id, fp.player_name, fp.player_photo, fp.note, fp.image_path, fp.image_thumb_path, fp.in_feed, fp.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_players fp JOIN users u ON u.id = fp.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fp.user_id IN ($placeholders) AND fp.is_public = 1 AND (fp.publish_at IS NULL OR fp.publish_at <= NOW()) ORDER BY fp.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fpTitolo2 = $r['player_name'];
        if (trim($r['note'] ?? '') !== '') {
            $fpTitolo2 .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'calciatore_favorito', 'titolo' => $fpTitolo2, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['player_photo']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/calciatori-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fm.id, fm.match_title, fm.match_image, fm.note, fm.image_path, fm.image_thumb_path, fm.in_feed, fm.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_matches fm JOIN users u ON u.id = fm.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fm.user_id IN ($placeholders) AND fm.is_public = 1 AND (fm.publish_at IS NULL OR fm.publish_at <= NOW()) ORDER BY fm.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fmTitolo = $r['match_title'];
        if (trim($r['note'] ?? '') !== '') {
            $fmTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'partita_favorita', 'titolo' => $fmTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['match_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/partite-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT so.id, so.title, so.price_label, so.cover_path, so.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM special_offers so JOIN users u ON u.id = so.user_id JOIN profiles p ON p.user_id = u.id
        WHERE so.user_id IN ($placeholders) AND so.is_active = 1
          AND (so.valid_from IS NULL OR so.valid_from <= NOW()) AND (so.valid_until IS NULL OR so.valid_until >= NOW())
        ORDER BY so.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $soTitolo = $r['title'] . ($r['price_label'] ? ' — ' . $r['price_label'] : '');
        $items[] = [
            'tipo' => 'offerta', 'titolo' => $soTitolo, 'cover' => $r['cover_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/offerte/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT pa.id, pa.title, pa.cover_path, pa.in_feed, pa.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM photo_albums pa JOIN users u ON u.id = pa.user_id JOIN profiles p ON p.user_id = u.id
        WHERE pa.user_id IN ($placeholders) AND pa.is_public = 1 AND (pa.publish_at IS NULL OR pa.publish_at <= NOW()) ORDER BY pa.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    $albumRows = $stmt->fetchAll();
    // Vedi commento sopra (blocco "pensiero"): stesso calcolo, per gli album con più foto oltre
    // alla copertina — a differenza di Offerte/Servizi, un album è tutto foto: vale la pena
    // incuriosire chi lo vede sui social con "Link Album in Descrizione", proprio come per
    // Timeline/Viaggi.
    $albumPhotoCounts = [];
    $albumIds = array_column($albumRows, 'id');
    if ($albumIds) {
        $ph = implode(',', array_fill(0, count($albumIds), '?'));
        $cs = $db->prepare("SELECT album_id, COUNT(*) c FROM photo_album_photos WHERE album_id IN ($ph) GROUP BY album_id");
        $cs->execute($albumIds);
        foreach ($cs->fetchAll() as $c) {
            $albumPhotoCounts[(int) $c['album_id']] = (int) $c['c'];
        }
    }
    foreach ($albumRows as $r) {
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'album_foto', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'raw_image_path' => $r['cover_path'], 'has_multi_photo' => !empty($albumPhotoCounts[(int) $r['id']]),
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/album/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT sv.id, sv.title, sv.cover_path, sv.in_feed, sv.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM services sv JOIN users u ON u.id = sv.user_id JOIN profiles p ON p.user_id = u.id
        WHERE sv.user_id IN ($placeholders) AND sv.is_public = 1 AND (sv.publish_at IS NULL OR sv.publish_at <= NOW()) ORDER BY sv.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'servizio', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/servizi/' . $r['id'],
        ];
    }

    usort($items, fn($a, $b) => strtotime($b['data']) <=> strtotime($a['data']));
    return array_slice($items, $offset, $limit);
}

// Rendering HTML condiviso di un singolo elemento della Timeline, riusato sia dal primo
// caricamento della pagina sia dalle richieste "carica altri" dello scrolling infinito.
// ===== Sistema di recensioni (solo voto a stelle, nessun commento) =====

// Media e conteggio voti per una band o un brano
function getBandRatingStats(int $bandUserId): array {
    $stmt = getDB()->prepare('SELECT AVG(rating) avg_r, COUNT(*) n FROM band_reviews WHERE band_user_id = ?');
    $stmt->execute([$bandUserId]);
    $r = $stmt->fetch();
    return ['avg' => $r['avg_r'] ? round((float) $r['avg_r'], 1) : null, 'count' => (int) $r['n']];
}

function getTrackRatingStats(int $trackId): array {
    $stmt = getDB()->prepare('SELECT AVG(rating) avg_r, COUNT(*) n FROM track_reviews WHERE track_id = ?');
    $stmt->execute([$trackId]);
    $r = $stmt->fetch();
    return ['avg' => $r['avg_r'] ? round((float) $r['avg_r'], 1) : null, 'count' => (int) $r['n']];
}

// Resa grafica a stelle piene (★), arrotondate al valore intero più vicino — usata sia per il
// voto di una singola persona sia per la media arrotondata di un gruppo di voti
function renderCromeRating(?float $rating, int $max = 5): string {
    if ($rating === null) {
        return '<span style="color:rgba(var(--text-rgb),0.4);font-size:13px;">Nessun voto ancora</span>';
    }
    $filled = (int) round($rating);
    $html = '<span style="letter-spacing:2px;">';
    for ($i = 1; $i <= $max; $i++) {
        $html .= $i <= $filled
            ? '<span style="color:rgb(108,92,231);">★</span>'
            : '<span style="color:rgba(var(--text-rgb),0.25);">★</span>';
    }
    $html .= '</span>';
    return $html;
}

// Form di voto a 5 stelle cliccabili (ognuna è un pulsante che invia quel valore) — mostra un
// messaggio diverso se l'utente ha già votato, senza permettere una seconda recensione
function renderRatingForm(string $action, int $targetId, ?int $viewerId, int $ownerUserId, ?int $existingRating): string {
    if (!$viewerId) {
        $currentUrl = ($_SERVER['REQUEST_URI'] ?? '/');
        $loginUrl = '/login.php?redirect=' . urlencode($currentUrl);
        return '<a href="' . e($loginUrl) . '" class="segui-pill" style="display:inline-block;">✨ Vota</a>'
             . '<p style="color:rgba(var(--text-rgb),0.55);font-size:12.5px;margin-top:6px;">Accedi o registrati per lasciare un voto.</p>';
    }
    if ($viewerId === $ownerUserId) {
        return '<p style="color:rgba(var(--text-rgb),0.6);font-size:13px;">Non puoi votare te stesso.</p>';
    }
    $html = '<div style="margin-top:10px;">';
    if ($existingRating !== null) {
        $html .= '<p style="font-size:13px;color:rgba(var(--text-rgb),0.6);margin-bottom:6px;">Il tuo voto: ' . renderCromeRating((float) $existingRating) . ' — clicca per modificarlo</p>';
    } else {
        $html .= '<p style="font-size:13px;color:rgba(var(--text-rgb),0.6);margin-bottom:6px;">Lascia il tuo voto:</p>';
    }
    $html .= '<div style="display:flex;gap:6px;">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<form method="post" style="display:inline;">' . csrfField()
            . '<input type="hidden" name="action" value="' . e($action) . '">'
            . '<input type="hidden" name="target_id" value="' . $targetId . '">'
            . '<input type="hidden" name="rating" value="' . $i . '">'
            . '<button type="submit" style="background:none;border:none;font-size:22px;cursor:pointer;color:' . ($existingRating !== null && $i <= $existingRating ? 'rgb(108,92,231)' : 'rgba(var(--text-rgb),0.3)') . ';">★</button>'
            . '</form>';
    }
    $html .= '</div></div>';
    return $html;
}

// Etichetta breve per un evento perpetuo/ricorrente (nessuna data di fine specifica) — usata
// ovunque un evento compare: pagina pubblica, elenco pubblico, dashboard, Timeline. Null se
// l'evento ha semplicemente una data singola (comportamento di sempre, nessuna etichetta).
function eventScheduleLabel(string $recurrence, bool $isPerpetual): ?string {
    if ($recurrence === 'weekdays') {
        return 'Dal Lunedì al Venerdì';
    }
    if ($recurrence === 'weekend') {
        return 'Solo Weekend';
    }
    if ($isPerpetual) {
        return 'Sempre attivo';
    }
    return null;
}

function renderDashboardTimelineItem(array $item, ?string $viewerSlug = null): string {
    // Nel feed si mostra sempre la miniatura leggera quando disponibile (image_thumb_path):
    // l'originale a piena qualità resta comunque intatto ed è quello mostrato aprendo il link.
    $cover = $item['cover_thumb'] ?? $item['cover'];
    $coverSrc = $cover ? (str_starts_with($cover, 'http') ? $cover : '/' . $cover) : null;
    $labels = ['blog' => '📝 Articolo', 'brano' => '🎵 Brano che amo', 'evento' => '📅 Evento', 'pensiero' => '💬 Aggiornamento', 'band_favorita' => '❤️ Band che amo', 'attore_favorito' => '🎬 Attore che amo', 'film_favorito' => '🍿 Film che amo', 'libro_favorito' => '📚 Libro che amo', 'viaggio_favorito' => '✈️ Viaggio', 'playlist_favorita' => '🎧 Playlist che amo', 'album_favorito' => '💿 Album che amo', 'offerta' => '🏷️ Offerta speciale', 'album_foto' => '📸 Album fotografico', 'servizio' => '💼 Servizio'];
    $label = $labels[$item['tipo']] ?? '';
    $eventoInfo = '';
    if ($item['tipo'] === 'evento') {
        $scheduleLabel = eventScheduleLabel($item['evento_recurrence'] ?? 'none', (bool) ($item['evento_is_perpetual'] ?? false));
        if ($scheduleLabel) {
            $eventoInfo = ' · ' . e($scheduleLabel);
        } elseif (!empty($item['evento_quando'])) {
            $eventoInfo = ' · si terrà il ' . e(formatLocalDateTime($item['evento_quando'], ['dashboard_theme' => $item['owner_tz'] ?? null]));
        }
    }
    // Sfondo grigio tenue per distinguere subito i propri contenuti dal resto del feed
    $isMine = $viewerSlug !== null && $item['user_slug'] === $viewerSlug;
    $bgStyle = $isMine ? 'background:#eef0f2;' : '';
    $html = '<a href="' . e($item['url']) . '" class="link-item" style="display:flex;gap:12px;align-items:center;text-decoration:none;color:inherit;' . $bgStyle . '">';
    if ($coverSrc) {
        $html .= '<img src="' . e($coverSrc) . '" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;">';
    } elseif (!empty($item['avatar'])) {
        $html .= '<img src="/' . e($item['avatar']) . '" style="width:56px;height:56px;border-radius:50%;object-fit:cover;flex-shrink:0;">';
    }
    $html .= '<div style="flex:1;min-width:0;">';
    $html .= '<small style="color:var(--text-muted);text-transform:uppercase;">' . e($label) . ' · ' . e($item['display_name']) . ($isMine ? ' <span style="color:var(--accent);font-weight:700;">(tu)</span>' : '') . '</small><br>';
    $html .= '<strong>' . e($item['titolo']) . '</strong><br>';
    $html .= '<small style="color:var(--text-muted)">' . e(formatLocalDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null])) . $eventoInfo . '</small>';
    $html .= '</div></a>';
    return $html;
}

function renderTimelineFeedItem(array $item): string {
    // Vedi commento in renderDashboardTimelineItem(): stessa logica, miniatura leggera in lista.
    $cover = $item['cover_thumb'] ?? $item['cover'];
    $coverSrc = $cover ? (str_starts_with($cover, 'http') ? $cover : '/' . $cover) : null;
    $labels = ['blog' => '📝 Articolo', 'brano' => '🎵 Brano che amo', 'evento' => '📅 Evento', 'pensiero' => '💬 Aggiornamento', 'band_favorita' => '❤️ Band che amo', 'attore_favorito' => '🎬 Attore che amo', 'film_favorito' => '🍿 Film che amo', 'libro_favorito' => '📚 Libro che amo', 'viaggio_favorito' => '✈️ Viaggio', 'playlist_favorita' => '🎧 Playlist che amo', 'album_favorito' => '💿 Album che amo', 'offerta' => '🏷️ Offerta speciale', 'album_foto' => '📸 Album fotografico', 'servizio' => '💼 Servizio'];
    $label = $labels[$item['tipo']] ?? '';
    $eventoInfo = '';
    if ($item['tipo'] === 'evento') {
        $scheduleLabel = eventScheduleLabel($item['evento_recurrence'] ?? 'none', (bool) ($item['evento_is_perpetual'] ?? false));
        if ($scheduleLabel) {
            $eventoInfo = ' · ' . e($scheduleLabel);
        } elseif (!empty($item['evento_quando'])) {
            $eventoInfo = ' · si terrà il ' . e(formatLocalDateTime($item['evento_quando'], ['dashboard_theme' => $item['owner_tz'] ?? null]));
        }
    }
    $html = '<a href="' . e($item['url']) . '" class="card" style="display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit;">';
    if ($coverSrc) {
        $html .= '<img src="' . e($coverSrc) . '" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex-shrink:0;">';
    }
    $html .= '<div style="flex:1;min-width:0;">';
    $html .= '<small style="color:rgba(var(--text-rgb),0.6);text-transform:uppercase;">' . e($label) . '</small><br>';
    $html .= '<strong>' . e($item['titolo']) . '</strong><br>';
    $html .= '<small style="color:rgba(var(--text-rgb),0.6);">' . e(formatLocalDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null])) . $eventoInfo . '</small>';
    $html .= '</div></a>';
    return $html;
}

// ===== Sistema "Segui via email" =====

function getFollowerCount(int $artistUserId): int {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM followers WHERE user_id = ? AND verified = 1');
    $stmt->execute([$artistUserId]);
    return (int) $stmt->fetch()['c'];
}

// Invia l'email di conferma iscrizione (doppio opt-in, anti-spam). Se l'SMTP non è
// configurato, non fa nulla (nessun errore).
function notifyFollowConfirmation(string $toEmail, string $artistName, string $token, string $confirmUrl): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "Conferma: segui {$artistName} su " . siteName();
    $body = "Ciao,\n\n"
          . "Hai chiesto di seguire {$artistName} su " . siteName() . ". Conferma cliccando questo link:\n\n"
          . "{$confirmUrl}\n\n"
          . "Da quel momento riceverai un'email quando {$artistName} pubblica un nuovo articolo\n"
          . "o annuncia un nuovo concerto.\n\n"
          . "Se non hai richiesto tu questa iscrizione, ignora pure questa email: non verrà\n"
          . "attivata alcuna iscrizione senza la tua conferma.";

    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toEmail, $subject, $body);
}

// Notifica tutti i follower verificati di un artista quando pubblica un nuovo contenuto
// (articolo blog o evento). "Best effort": eventuali errori di invio ai singoli indirizzi non
// bloccano gli altri né l'operazione che ha generato la notifica (pubblicare un post/evento
// resta valida anche se le email non partissero per qualche motivo).
function notifyFollowersNewContent(int $artistUserId, string $artistName, string $artistSlug, string $type, string $title, string $contentUrl): void {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return;
    }
    $stmt = getDB()->prepare('SELECT email, token FROM followers WHERE user_id = ? AND verified = 1');
    $stmt->execute([$artistUserId]);
    $followers = $stmt->fetchAll();
    if (!$followers) {
        return;
    }

    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $labels = ['evento' => 'un nuovo concerto', 'timeline' => 'un nuovo aggiornamento', 'offerta' => 'una nuova offerta speciale', 'album_foto' => 'un nuovo album fotografico', 'servizio' => 'un nuovo servizio'];
    $label = $labels[$type] ?? 'un nuovo articolo';
    $subject = "{$artistName} ha pubblicato {$label} su " . siteName();

    foreach ($followers as $f) {
        $unsubscribeUrl = siteUrl('/follow_unsubscribe.php?token=' . $f['token']);
        $body = "Ciao,\n\n"
              . "{$artistName} ha appena pubblicato {$label}:\n\n"
              . "\"{$title}\"\n\n"
              . "Vai a vederlo qui: {$contentUrl}\n\n"
              . "---\n"
              . "Ricevi questa email perché segui {$artistName} su " . siteName() . ".\n"
              . "Per non ricevere più queste notifiche: {$unsubscribeUrl}";

        $mailer->send($cfg['from'], $cfg['fromName'], $f['email'], $f['email'], $subject, $body);
    }
}

function slugExists(string $slug): bool {
    $stmt = getDB()->prepare('SELECT id FROM users WHERE slug = ?');
    $stmt->execute([$slug]);
    return (bool) $stmt->fetch();
}

// Whitelist di route dell'app: uno slug musicista non può collidere con queste pagine
const RESERVED_SLUGS = ['login','register','logout','dashboard','dashboard_profile',
    'dashboard_links','dashboard_audio','dashboard_events','dashboard_blog',
    'dashboard_contacts','u','index','assets','uploads','blog','contatti','link',
    'admin','admin_users','admin_user_detail','admin_privacy','brani','eventi',
    'verify','resend_verification','admin_dashboard','admin_user_edit','admin_contacts','admin_tracking','admin_smtp',
    'admin_spotify','dashboard_spotify','follow','follow_confirm','follow_unsubscribe','dashboard_followers',
    'admin_import_legacy','admin_profiles','track','evento','admin_youtube','dashboard_youtube','video',
    'forgot_password','reset_password','dashboard_podcast','podcast',
    'choose_account_type','dashboard_fan_bands','band_che_amo','admin_apply_percorso','admin_link_avatars',
    'follow_account','dashboard_timeline','timeline','dashboard_post','timeline_post','feed','admin_import_old_timeline','timeline_more','track_review','admin_reviews','dashboard_password','dashboard_timeline_more',
    'login_otp_request','login_otp_verify','request_access','admin_access_requests','dashboard_theme','credits',
    'dashboard_invite','dashboard_following','dashboard_team','dashboard_log','track_lyrics',
    'dashboard_messages','dashboard_chat','menu','dashboard_menu',
    'reserve_table','dashboard_reservations','dashboard_profiles','dashboard_feed','sitemap','robots',
    'dashboard_fan_actors','attori_che_amo','admin_gemini','dashboard_ai_caption','admin_tmdb',
    'dashboard_fan_movies','film_che_amo','fan_favorite_item',
    'dashboard_fan_books','libri_che_amo','admin_googlebooks',
    'dashboard_cinema','cron_cinema_sync','favorite_track_item',
    'dashboard_fan_trips','viaggi','viaggio_item','admin_geoapify',
    'auth_google_start','auth_google_callback','admin_google_login','onboarding_setup',
    'dashboard_che_amo','che_amo',
    'dashboard_fan_playlists','playlist_che_amo','dashboard_fan_albums','album_che_amo'];

// Genera uno slug univoco per un articolo di un dato utente (title -> slug, con suffisso -2, -3... se già esistente)
function generateUniquePostSlug(int $userId, string $title, ?int $excludePostId = null): string {
    $base = slugify($title) ?: 'articolo';
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM blog_posts WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];
        if ($excludePostId) {
            $sql .= ' AND id != ?';
            $params[] = $excludePostId;
        }
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

// Stesso principio di generateUniquePostSlug(), per le categorie del blog — usato per la pagina
// pubblica filtrata per categoria (/nomeutente/blog/categoria/slug-categoria).
function generateUniqueBlogCategorySlug(int $userId, string $name, ?int $excludeCategoryId = null): string {
    $base = slugify($name) ?: 'categoria';
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM blog_categories WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];
        if ($excludeCategoryId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeCategoryId;
        }
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

// Costruisce il permalink SEO di un articolo: /nomeutente/blog/anno.mese.giorno.slug-articolo
function blogPostUrl(string $userSlug, array $post): string {
    $datePart = date('Y.m.d', strtotime($post['published_at']));
    return '/' . $userSlug . '/blog/' . $datePart . '.' . $post['slug'];
}

// URL della pagina pubblica di una categoria del blog (elenco filtrato).
function blogCategoryUrl(string $userSlug, array $category): string {
    return '/' . $userSlug . '/blog/categoria/' . $category['slug'];
}

// JSON-LD BreadcrumbList — aiuta Google a mostrare il percorso (es. "Nome > Blog > Categoria")
// nei risultati di ricerca invece del solo URL. $items: elenco ordinato di ['name'=>.., 'url'=>..].
// Il default di json_encode (senza JSON_UNESCAPED_SLASHES) lascia "/" escapato apposta: evita che
// un titolo contenente "</script>" possa interrompere il blocco <script> che lo racchiude.
function breadcrumbJsonLd(array $items): string {
    $list = [];
    foreach ($items as $i => $item) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $item['name'], 'item' => $item['url']];
    }
    $data = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE) . "</script>\n";
}

// JSON-LD BlogPosting per la pagina di un articolo — headline/autore/immagine/data di
// pubblicazione: rende l'articolo idoneo ai rich result "Articolo" di Google, oltre agli
// Open Graph/Twitter Card già presenti (che servono solo per le anteprime sui social).
function blogPostingJsonLd(array $post, string $displayName, string $permalink, ?string $ogImage): string {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post['title'],
        'description' => $post['excerpt'] ?: textExcerpt($post['content']),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $permalink],
        'url' => $permalink,
        'datePublished' => date(DATE_ATOM, strtotime($post['published_at'])),
        'author' => ['@type' => 'Person', 'name' => $displayName],
        'publisher' => ['@type' => 'Organization', 'name' => siteName()],
    ];
    if ($ogImage) {
        $data['image'] = [$ogImage];
    }
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE) . "</script>\n";
}

// JSON-LD Blog — per l'indice del blog e per le pagine categoria: elenca gli articoli visibili
// come tipo Blog/blogPost, così Google riconosce la pagina come un indice di articoli correlati
// (non solo un elenco generico di link). Limitato ai primi 20 per non gonfiare inutilmente il
// markup su blog molto popolati.
function blogListJsonLd(string $pageUrl, string $name, string $description, array $posts, string $userSlug): string {
    $items = [];
    foreach (array_slice($posts, 0, 20) as $post) {
        $items[] = ['@type' => 'BlogPosting', 'headline' => $post['title'], 'url' => siteUrl(blogPostUrl($userSlug, $post))];
    }
    $data = ['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => $name, 'description' => $description, 'url' => $pageUrl];
    if ($items) {
        $data['blogPost'] = $items;
    }
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE) . "</script>\n";
}

// Categorie assegnate a un articolo, in ordine alfabetico — usata sia in dashboard (per
// precompilare le checkbox in modifica) sia lato pubblico (badge sotto il titolo).
function getBlogPostCategories(int $postId): array {
    $stmt = getDB()->prepare('SELECT c.* FROM blog_categories c JOIN blog_post_categories pc ON pc.category_id = c.id WHERE pc.post_id = ? ORDER BY c.name ASC');
    $stmt->execute([$postId]);
    return $stmt->fetchAll();
}

// Tag effettivamente in uso da questo profilo, con quante volte compare ciascuno — a differenza
// delle categorie, i tag sono un campo di testo libero per articolo (blog_posts.tags, separati
// da virgola), non una tabella a sé: qui li aggreghiamo per poterli gestire (rinominarli o
// eliminarli ovunque compaiano) come se lo fossero. Confronto case-sensitive: "Napoli" e "napoli"
// restano due tag distinti, esattamente come sono stati scritti negli articoli.
function getBlogTagCounts(int $userId): array {
    $stmt = getDB()->prepare("SELECT tags FROM blog_posts WHERE user_id=? AND tags IS NOT NULL AND tags <> ''");
    $stmt->execute([$userId]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        foreach (array_filter(array_map('trim', explode(',', $row['tags']))) as $tag) {
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }
    }
    ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
    return $counts;
}

// URL assoluta del sito (per meta tag Open Graph / condivisione social), usa SITE_URL se impostata
function siteUrl(string $path = ''): string {
    $base = rtrim(getenv('SITE_URL') ?: '', '/');
    if ($base === '') {
        $base = requestScheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/' . ltrim($path, '/');
}

// Schema (http/https) della richiesta corrente, tenendo conto del reverse proxy
// (Nginx/Nginx Proxy Manager/Traefik) che termina il TLS e inoltra in HTTP: in quel
// caso $_SERVER['HTTPS'] non risulta valorizzato, va letto X-Forwarded-Proto.
function requestScheme(): string {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if ($forwardedProto !== '') {
        $forwardedProto = trim(explode(',', $forwardedProto)[0]);
        if ($forwardedProto === 'https') return 'https';
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        return 'https';
    }
    return 'http';
}

// Estratto in testo semplice per meta description / anteprima social
function textExcerpt(string $text, int $length = 160): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length - 1) . '…';
}

// ============================================
// PROFILE NAVIGATION MENU FUNCTIONS
// ============================================
// Permette a ogni profilo di nascondere singoli tab del proprio menu di navigazione pubblico,
// incluse le integrazioni (Spotify/Podcast/Video) e il pulsante Segui — questi ultimi restano
// comunque governati anche dalla loro condizione di base (es. Spotify compare solo se
// effettivamente collegato): nasconderli qui li nasconde sempre, ma non basta da solo a farli
// comparire se quella condizione non è soddisfatta.

// Mappa tra il "name" salvato in profile_navigation_menu e la chiave interna usata da
// publicNav() per identificare ciascun tab — tenerle distinte evita di legare lo schema del
// database ai nomi visualizzati (che potrebbero cambiare) o a caratteri accentati nelle chiavi.
// L'ordine qui rispecchia l'ordine con cui i tab compaiono davvero nel menu pubblico (publicNav())
// e nella checklist di dashboard_nav_menu.php, allineato anche a quello della barra di gestione
// in dashboard (_dash_header.php: Feed, Timeline, Link, Band/Attori/Film/Libri che amo, Viaggi,
// Blog, Brani che amo, Menù, Eventi, Segui/Follower, Contatti) — Home apre l'elenco (non ha un
// equivalente in dashboard) e Spotify/Podcast/Video (integrazioni senza una voce nella barra di
// gestione) restano raggruppate subito dopo Viaggi, come già erano.
// "Che Amo" è il tab riepilogativo (vetrina) che sostituisce quelli che una volta erano tab
// individuali per Band/Attori/Film/Libri che amo, Viaggi e Brani che amo — quelle voci restano
// comunque qui sotto: controllano se la card del singolo modulo compare nella vetrina, non più
// se il modulo ha un proprio tab. Vedi CHE_AMO_MODULES più sopra per l'elenco completo.
const PUBLIC_NAV_ITEM_KEYS = [
    'Home' => 'home',
    'Timeline' => 'timeline',
    'Link' => 'link',
    'Che Amo' => 'cheamo',
    'Band che amo' => 'bandcheamo',
    'Attori che amo' => 'attorichamo',
    'Film che amo' => 'filmcheamo',
    'Libri che amo' => 'libricheamo',
    'Viaggi' => 'viaggi',
    'Spotify' => 'spotify',
    'Podcast' => 'podcast',
    'Video' => 'video',
    'Blog' => 'blog',
    'Brani che amo' => 'brani',
    'Playlist che amo' => 'playlistcheamo',
    'Album che amo' => 'albumcheamo',
    'Ricette che amo' => 'ricettecheamo',
    'Squadre che amo' => 'squadrecheamo',
    'Calciatori che amo' => 'calciatoricheamo',
    'Partite che amo' => 'partitecheamo',
    'Menù' => 'menu',
    'Offerte' => 'offerte',
    'Foto' => 'foto',
    'Servizi' => 'servizi',
    'Eventi' => 'eventi',
    'Segui' => 'segui',
    'Contatti' => 'contatti',
];

/**
 * Crea le voci di menu di default per un profilo, con URL basati sul suo slug reale
 * (idempotente: INSERT IGNORE, non duplica se già esistenti). "Link" resta una sezione dentro
 * Home, senza un tab proprio (l'URL indicato è solo quello della pagina su cui vive, per
 * riferimento nella checklist) — "Band che amo", "Attori che amo", "Film che amo" e "Libri che
 * amo" invece non hanno più un tab proprio nel menu pubblico (sono raccolti dentro "Che Amo"),
 * ma hanno comunque ciascuno una pagina elenco dedicata e raggiungibile direttamente
 * (/slug/band-che-amo ecc., vedi CHE_AMO_MODULES/.htaccess): l'URL qui sotto punta lì, non alla
 * home, così la checklist di dashboard_nav_menu.php mostra il link vero della card.
 */
function createDefaultProfileNavMenu(int $userId, string $slug): bool {
    $defaults = [
        ['Home', 'fas fa-home', '/' . $slug, 1],
        ['Timeline', 'fas fa-stream', '/' . $slug . '/timeline', 2],
        ['Link', 'fas fa-link', '/' . $slug, 3],
        ['Che Amo', 'fas fa-heart', '/' . $slug . '/che-amo', 4],
        ['Band che amo', 'fas fa-heart-circle-check', '/' . $slug . '/band-che-amo', 5],
        ['Attori che amo', 'fas fa-clapperboard', '/' . $slug . '/attori-che-amo', 6],
        ['Film che amo', 'fas fa-film', '/' . $slug . '/film-che-amo', 7],
        ['Libri che amo', 'fas fa-book', '/' . $slug . '/libri-che-amo', 8],
        ['Viaggi', 'fas fa-plane', '/' . $slug . '/viaggi', 9],
        ['Spotify', 'fa-brands fa-spotify', '/' . $slug . '/spotify', 10],
        ['Podcast', 'fas fa-microphone', '/' . $slug . '/podcast', 11],
        ['Video', 'fa-brands fa-youtube', '/' . $slug . '/video', 12],
        ['Blog', 'fas fa-newspaper', '/' . $slug . '/blog', 13],
        ['Brani che amo', 'fas fa-music', '/' . $slug . '/brani', 14],
        ['Playlist che amo', 'fas fa-list-ul', '/' . $slug . '/playlist-che-amo', 15],
        ['Album che amo', 'fas fa-compact-disc', '/' . $slug . '/album-che-amo', 16],
        ['Menù', 'fas fa-utensils', '/' . $slug . '/menu', 17],
        ['Eventi', 'fas fa-calendar', '/' . $slug . '/eventi', 18],
        ['Segui', 'fas fa-heart', '/' . $slug . '#segui-widget', 19],
        ['Contatti', 'fas fa-envelope', '/' . $slug . '/contatti', 20],
        ['Offerte', 'fas fa-tags', '/' . $slug . '/offerte', 21],
        ['Foto', 'fas fa-images', '/' . $slug . '/foto', 22],
        ['Servizi', 'fas fa-briefcase', '/' . $slug . '/servizi', 23],
        ['Ricette che amo', 'fas fa-bowl-food', '/' . $slug . '/ricette-che-amo', 24],
        ['Squadre che amo', 'fas fa-futbol', '/' . $slug . '/squadre-che-amo', 25],
        ['Calciatori che amo', 'fas fa-shirt', '/' . $slug . '/calciatori-che-amo', 26],
        ['Partite che amo', 'fas fa-calendar-check', '/' . $slug . '/partite-che-amo', 27],
    ];

    foreach ($defaults as [$name, $icon, $url, $order]) {
        $stmt = getDB()->prepare('
            INSERT IGNORE INTO profile_navigation_menu (user_id, name, icon, url, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ');
        if (!$stmt->execute([$userId, $name, $icon, $url, $order])) {
            return false;
        }
    }
    return true;
}

/**
 * Ottiene TUTTE le voci di menu per un profilo (incluse nascoste), creando i default mancanti al
 * primo accesso — sia per un profilo che non ne ha ancora nessuna, sia per un profilo creato
 * prima dell'introduzione di una voce più recente (es. Spotify/Podcast/Video/Segui, aggiunte
 * dopo Home/Timeline/Blog/Brani/Menù/Eventi/Contatti): in quel caso ne mancano solo alcune, non
 * tutte, ma vanno comunque completate.
 */
function getAllProfileNavigationMenu(int $userId, string $slug): array {
    $stmt = getDB()->prepare('
        SELECT id, name, icon, url, is_visible, sort_order
        FROM profile_navigation_menu
        WHERE user_id = ?
        ORDER BY sort_order ASC
    ');
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll() ?: [];
    if (count($items) < count(PUBLIC_NAV_ITEM_KEYS)) {
        createDefaultProfileNavMenu($userId, $slug);
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll() ?: [];
    }
    return $items;
}

/**
 * Aggiorna la visibilità di una voce di menu.
 */
function updateProfileNavMenuVisibility(int $userId, string $name, bool $isVisible): bool {
    $stmt = getDB()->prepare('
        UPDATE profile_navigation_menu
        SET is_visible = ?
        WHERE user_id = ? AND name = ?
    ');
    return $stmt->execute([$isVisible ? 1 : 0, $userId, $name]);
}

// Chiavi dei tab standard che questo profilo ha esplicitamente nascosto — usata da
// publicProfileHeader()/publicNav() per filtrare il menu pubblico. Un profilo che non ha mai
// aperto "Menu di Navigazione" in dashboard non ha righe in tabella: nessuna riga nascosta,
// nessun filtro, comportamento identico a prima di questa funzionalità (nessun bisogno di
// seedare i default solo per calcolare questo elenco).
// Voci di menu disattivate dall'amministratore per TUTTA l'installazione (Area Admin → Funzioni
// del sito), non da un singolo profilo — pensato per chi installa "Chi Fa Cosa" per un tipo di
// attività che non ha bisogno di certi moduli (es. un ristorante non ha bisogno di "Squadre che
// amo"): invece di doverlo disattivare profilo per profilo, un solo interruttore vale per tutti,
// anche quelli creati in futuro. Salvate come lista JSON di chiavi interne (le stesse di
// PUBLIC_NAV_ITEM_KEYS) in un'unica riga di site_settings, stesso principio già usato per le
// impostazioni di Privacy/Tracking di profilo.
function getSiteDisabledNavKeys(): array {
    $raw = getSiteSetting('disabled_nav_keys');
    if (!$raw) {
        return [];
    }
    $keys = json_decode($raw, true);
    return is_array($keys) ? $keys : [];
}

function setSiteDisabledNavKeys(array $keys): void {
    setSiteSetting('disabled_nav_keys', json_encode(array_values(array_unique($keys))));
}

// Voci nascoste per QUESTO profilo — unione di ciò che il profilo stesso ha disattivato (Menu di
// Navigazione) e ciò che l'amministratore ha disattivato per tutta l'installazione: chi consuma
// questa lista (publicNav(), adminLteTopNav(), che_amo.php, dashboard_che_amo.php, il carosello
// Home...) non deve sapere da dove viene un nascondimento, gli basta questo unico elenco.
function getHiddenNavKeys(int $userId): array {
    $stmt = getDB()->prepare('SELECT name FROM profile_navigation_menu WHERE user_id = ? AND is_visible = 0');
    $stmt->execute([$userId]);
    $hidden = getSiteDisabledNavKeys();
    foreach ($stmt->fetchAll() as $row) {
        if (isset(PUBLIC_NAV_ITEM_KEYS[$row['name']])) {
            $hidden[] = PUBLIC_NAV_ITEM_KEYS[$row['name']];
        }
    }
    return $hidden;
}

// Ordine personalizzato (trascinamento in dashboard_nav_menu.php) di questo profilo, come
// chiave interna => sort_order, usata da publicNav() per riordinare il menu pubblico. Stessa
// nota di getHiddenNavKeys(): nessuna riga in tabella significa semplicemente nessun ordine
// personalizzato, publicNav() ricade sull'ordine con cui costruisce i tab.
function getNavItemOrder(int $userId): array {
    $stmt = getDB()->prepare('SELECT name, sort_order FROM profile_navigation_menu WHERE user_id = ?');
    $stmt->execute([$userId]);
    $order = [];
    foreach ($stmt->fetchAll() as $row) {
        if (isset(PUBLIC_NAV_ITEM_KEYS[$row['name']])) {
            $order[PUBLIC_NAV_ITEM_KEYS[$row['name']]] = (int) $row['sort_order'];
        }
    }
    return $order;
}

// Riporta l'ordine delle voci di menu di un profilo a quello predefinito (PUBLIC_NAV_ITEM_KEYS),
// senza toccare quali voci sono nascoste/visibili — utile a chi ha trascinato l'ordine in passato
// e vuole tornare a quello standard (allineato alla barra di gestione in dashboard) con un click,
// invece di ritrascinare a mano ogni voce.
function resetProfileNavMenuOrder(int $userId): void {
    $stmt = getDB()->prepare('UPDATE profile_navigation_menu SET sort_order = ? WHERE user_id = ? AND name = ?');
    $order = 1;
    foreach (array_keys(PUBLIC_NAV_ITEM_KEYS) as $name) {
        $stmt->execute([$order, $userId, $name]);
        $order++;
    }
}

// ===== Cinema: sincronizzazione film in programmazione (modulo Link) =====
// Funzionalità dedicata ai profili "cinema": un JSON esterno (formato 18tickets,
// {"films": [{id, title, film_url, playbill_path, ...}]}) diventa una serie di pulsanti nel
// modulo Link (link_type='film'), uno per film, con titolo/immagine/link — vera
// sincronizzazione: aggiunge i film nuovi, aggiorna quelli già presenti, rimuove quelli non più
// in programmazione. Configurabile da Dashboard → menu hamburger → Cinema
// (dashboard_cinema.php), sincronizzabile a mano o via cron (cron_cinema_sync.php).

// Token segreto per autenticare le chiamate cron automatiche (generato una sola volta, salvato
// in site_settings come le altre chiavi API del sito).
function getCinemaSyncCronToken(): string {
    $token = getSiteSetting('cinema_sync_cron_token');
    if (!$token) {
        $token = bin2hex(random_bytes(24));
        setSiteSetting('cinema_sync_cron_token', $token);
    }
    return $token;
}

// Blocca richieste verso indirizzi non pubblici (rete Docker interna, localhost, metadata cloud,
// ecc.): cinema_films_json_url è un campo che QUALSIASI proprietario di profilo può impostare a
// piacere, e viene poi scaricato da questo server (sync manuale in dashboard_cinema.php o cron
// globale) — senza questo controllo sarebbe un SSRF, cioè un modo per far fare al server richieste
// verso servizi interni non raggiungibili altrimenti da fuori.
function isSafePublicUrl(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return false;
    }
    $host = $parts['host'];
    // Un host letteralmente un indirizzo IP si valida direttamente; un nome a dominio va risolto
    // prima — un dominio "innocuo" potrebbe comunque puntare a un indirizzo interno (DNS
    // rebinding), quindi si controllano TUTTI gli indirizzi a cui risolve, non solo il primo.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $records = dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $ips = array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
        if (!$ips) {
            return false;
        }
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

// Scarica un URL generico con timeout, senza dipendenze esterne (stesso approccio di
// spotify.php/mailer.php: file_get_contents con stream context). Restituisce null in caso di
// errore (URL non pubblico incluso), senza mai lanciare eccezioni. follow_location disattivato
// apposta: un redirect verso un indirizzo interno aggirerebbe altrimenti il controllo qui sopra,
// che vale solo per l'URL di partenza.
function cinemaHttpGet(string $url, int $timeout = 20): ?string {
    if (!isSafePublicUrl($url)) {
        return null;
    }
    $opts = [
        'http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0],
        'https' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0],
    ];
    $context = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);
    return $result === false ? null : $result;
}

// Scarica un'immagine da URL esterno e la salva in uploads/images/{slug}, come le altre cover
// caricate nel sito — evita di hotlinkare l'immagine del gestionale cinema esterno.
function cinemaDownloadPoster(string $imageUrl, string $slug): ?string {
    $data = cinemaHttpGet($imageUrl, 20);
    if ($data === null || strlen($data) < 100 || strlen($data) > 8 * 1024 * 1024) {
        return null;
    }
    $ext = strtolower(pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $ext = 'jpg';
    }
    $fname = bin2hex(random_bytes(6)) . '.' . $ext;
    $dir = '/var/www/html/uploads/images/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (file_put_contents($dir . '/' . $fname, $data) === false) {
        return null;
    }
    return 'uploads/images/' . $slug . '/' . $fname;
}

// Sincronizza i film in programmazione di UN profilo dal suo JSON configurato nel modulo Link
// (link_type='film', external_ref=film.id): aggiunge i nuovi, aggiorna label/url dei già
// presenti (senza riscaricare il poster, già in cache), rimuove chi non è più nel JSON. In caso
// di errore (URL irraggiungibile, JSON non valido) non tocca nulla — non svuota mai i link
// esistenti per un problema temporaneo del feed esterno.
function syncCinemaFilms(array $profile): array {
    $jsonUrl = trim($profile['cinema_films_json_url'] ?? '');
    if ($jsonUrl === '') {
        return ['ok' => false, 'error' => 'Nessun URL JSON configurato.'];
    }

    $raw = cinemaHttpGet($jsonUrl, 25);
    if ($raw === null) {
        return ['ok' => false, 'error' => 'Impossibile raggiungere l\'URL del JSON.'];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['films']) || !is_array($data['films'])) {
        return ['ok' => false, 'error' => 'Il JSON non ha il formato atteso (manca "films").'];
    }

    $db = getDB();
    $userId = (int) $profile['id'];
    $slug = $profile['slug'];

    $stmt = $db->prepare("SELECT id, external_ref, cover_path, label, url FROM links WHERE user_id=? AND link_type='film'");
    $stmt->execute([$userId]);
    $existing = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['external_ref']) {
            $existing[$row['external_ref']] = $row;
        }
    }

    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order),0) AS m FROM links WHERE user_id=?');
    $stmt->execute([$userId]);
    $nextSort = (int) $stmt->fetch()['m'] + 1;

    $seenRefs = [];
    $added = 0;
    $updated = 0;

    foreach ($data['films'] as $film) {
        $ref = trim((string) ($film['id'] ?? ''));
        $title = mb_substr(trim((string) ($film['title'] ?? '')), 0, 120);
        $url = trim((string) ($film['film_url'] ?? '')) ?: trim((string) ($film['film_url_for_cinema'] ?? ''));
        if ($ref === '' || $title === '' || $url === '') {
            continue;
        }
        $seenRefs[$ref] = true;

        if (isset($existing[$ref])) {
            $row = $existing[$ref];
            if ($row['label'] !== $title || $row['url'] !== $url) {
                $stmt = $db->prepare('UPDATE links SET label=?, url=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $url, $row['id'], $userId]);
                $updated++;
            }
        } else {
            $coverPath = null;
            $playbill = trim((string) ($film['playbill_path'] ?? ''));
            if ($playbill !== '') {
                $coverPath = cinemaDownloadPoster($playbill, $slug);
            }
            $stmt = $db->prepare("INSERT INTO links (user_id, label, url, cover_path, sort_order, link_type, external_ref) VALUES (?,?,?,?,?,'film',?)");
            $stmt->execute([$userId, $title, $url, $coverPath, $nextSort, $ref]);
            $nextSort++;
            $added++;
        }
    }

    $removed = 0;
    foreach ($existing as $ref => $row) {
        if (!isset($seenRefs[$ref])) {
            deleteCoverFile($row['cover_path']);
            $stmt = $db->prepare('DELETE FROM links WHERE id=? AND user_id=?');
            $stmt->execute([$row['id'], $userId]);
            $removed++;
        }
    }

    $stmt = $db->prepare('UPDATE profiles SET cinema_films_synced_at=NOW() WHERE user_id=?');
    $stmt->execute([$userId]);

    return ['ok' => true, 'added' => $added, 'updated' => $updated, 'removed' => $removed, 'total' => count($seenRefs)];
}
