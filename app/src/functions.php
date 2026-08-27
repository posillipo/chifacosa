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

function currentUser(): ?array {
    attemptRememberLogin();
    if (empty($_SESSION['user_id'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = getDB()->prepare('SELECT u.*, p.display_name, p.bio, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_artist_name, p.spotify_show_id, p.spotify_show_name, p.youtube_channel_id, p.youtube_channel_name, p.genere, p.citta, p.provincia, p.telefono, p.custom_feed_guid, p.custom_feed_guid_since
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
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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
    $stmt = getDB()->prepare('SELECT u.*, p.display_name, p.bio, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_artist_name, p.spotify_show_id, p.spotify_show_name, p.youtube_channel_id, p.youtube_channel_name, p.genere, p.citta, p.provincia, p.telefono
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

// Restituisce lo script privacy/cookie (es. Iubenda) impostato dall'admin, pronto per essere
// stampato nell'<head> di ogni pagina pubblica. Contenuto fidato: inserito solo dall'amministratore.
function embedPrivacyScript(): string {
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

function embedGoogleAnalytics(): string {
    $id = trim(getSiteSetting('ga_measurement_id') ?: '');
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

// Menu di navigazione condiviso tra tutte le pagine pubbliche di un artista (Home | Blog | Brani | Eventi | Contatti)
// Il tab "Spotify" compare solo se l'artista ha collegato un profilo Spotify dalla dashboard.
function publicNav(string $slug, string $active, bool $hasSpotify = false, bool $hasYoutube = false, bool $hasPodcast = false, string $accountType = 'band', ?int $ownerId = null, bool $hasMenu = false): string {
    $isBandOrLabel = in_array($accountType, ['band', 'label'], true);
    // Tab standard che il profilo ha esplicitamente nascosto da "Menu di Navigazione" in
    // dashboard (Home/Timeline/Blog/Brani/Menù/Eventi/Contatti) — i tab "integrazione"
    // (Spotify/Podcast/Video/Segui) non sono coperti, restano governati dalla loro logica.
    $hiddenKeys = $ownerId ? getHiddenNavKeys($ownerId) : [];

    $viewerId = $_SESSION['user_id'] ?? null;
    $seguiLabel = '✨ Segui';
    if ($viewerId && $ownerId && (int) $viewerId !== (int) $ownerId) {
        $seguiLabel = isFollowingAccount((int) $viewerId, (int) $ownerId) ? '✓ Segui già' : '✨ Segui';
    }
    $tabs = [];
    if (!$viewerId || !$ownerId || (int) $viewerId !== (int) $ownerId) {
        $tabs['segui'] = ['label' => $seguiLabel, 'url' => '/' . $slug . '#segui-widget', 'class' => 'nav-segui-tab'];
    }
    $tabs['home'] = ['label' => 'Home', 'url' => '/' . $slug, 'icon' => 'fas fa-house'];
    $tabs['timeline'] = ['label' => 'Timeline', 'url' => '/' . $slug . '/timeline', 'icon' => 'fas fa-stream'];
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
    $tabs['brani'] = ['label' => 'Brani', 'url' => '/' . $slug . '/brani', 'icon' => 'fas fa-music'];
    if ($hasMenu) {
        $tabs['menu'] = ['label' => 'Menù', 'url' => '/' . $slug . '/menu', 'icon' => 'fas fa-utensils'];
    }
    if ($isBandOrLabel) {
        $tabs['eventi'] = ['label' => 'Eventi', 'url' => '/' . $slug . '/eventi', 'icon' => 'fas fa-calendar'];
    }
    $tabs['contatti'] = ['label' => 'Contatti', 'url' => '/' . $slug . '/contatti', 'icon' => 'fas fa-envelope'];

    // Non nasconde mai il tab attualmente attivo, anche se marcato nascosto: chi ci arriva
    // comunque tramite link diretto deve continuare a vedersi orientato nel menu, non sparire.
    foreach ($hiddenKeys as $hk) {
        if ($hk !== $active) {
            unset($tabs[$hk]);
        }
    }

    $parts = [];
    foreach ($tabs as $key => $t) {
        $classes = trim(($t['class'] ?? '') . ($key === $active ? ' nav-active-tab' : ''));
        $classAttr = $classes !== '' ? ' class="' . e($classes) . '"' : '';
        $icon = !empty($t['icon']) ? '<i class="' . e($t['icon']) . '"></i> ' : '';
        $parts[] = '<a href="' . e($t['url']) . '"' . $classAttr . '>' . $icon . e($t['label']) . '</a>';
    }
    return '<div class="colorful-nav-wrap">'
        . '<nav class="colorful-nav">' . implode('', $parts) . '</nav>'
        . '<span class="colorful-nav-arrow" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>'
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
    if (!empty($artist['genere'])) {
        $html .= '<span> · </span>' . e($artist['genere']);
    }
    $html .= '</p>';
    $ownerId = isset($artist['id']) ? (int) $artist['id'] : null;
    $hasMenu = $ownerId ? menuHasItems($ownerId) : false;
    $html .= publicNav($artist['slug'], $active, !empty($artist['spotify_artist_id']), !empty($artist['youtube_channel_id']), !empty($artist['spotify_show_id']), $artist['account_type'] ?? 'band', $ownerId, $hasMenu);
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

function renderSiteFooterBar(): string {
    $privacyUrl = getSiteSetting('privacy_policy_url') ?: '';
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
    // Testo statico "[nome sito]/tu" (non lo slug del profilo che si sta visitando): è un invito
    // promozionale rivolto al visitatore, non un link di condivisione della pagina corrente.
    $badge = '<a href="/register.php" class="short-link-badge">' . e(siteName()) . '/tu</a>';
    return '<div class="site-footer-fixed">' . $badge . $linksRow . '</div>';
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
function embedTrackingHead(): string {
    $gtm = getSiteSetting('gtm_head_script') ?: '';
    $pixel = getSiteSetting('fb_pixel_script') ?: '';
    return $gtm . "\n" . $pixel;
}

function embedTrackingBodyStart(): string {
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
function sendMetaConversionEvent(string $eventName, string $eventId, ?string $userEmail = null): void {
    $pixelId = getSiteSetting('fb_pixel_id') ?: '';
    $token = getSiteSetting('fb_capi_token') ?: '';
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
function embedClientSideConversionEvent(string $eventName, string $eventId): string {
    if ((getSiteSetting('fb_pixel_id') ?: '') === '') {
        return '';
    }
    return "<script>if (typeof fbq === 'function') { fbq('track', '" . addslashes($eventName) . "', {}, {eventID: '" . addslashes($eventId) . "'}); }</script>";
}

// Gestisce l'upload di un'immagine di copertina (link, articoli blog, eventi). Restituisce il
// percorso relativo salvato, o null se non è stato caricato nessun file valido. Non lancia mai
// errori: un file mancante o non valido significa semplicemente "nessuna copertina".
function handleCoverUpload(string $slug, string $fileInputName = 'cover'): ?string {
    if (empty($_FILES[$fileInputName]['name'])) {
        return null;
    }
    $ext = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $fname = bin2hex(random_bytes(6)) . '.' . $ext;
    $dir = '/var/www/html/uploads/images/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $dest = $dir . '/' . $fname;
    if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $dest)) {
        return 'uploads/images/' . $slug . '/' . $fname;
    }
    return null;
}

// Elimina il file di copertina dal disco, se presente (usato quando si elimina un link/post/evento)
function deleteCoverFile(?string $coverPath): void {
    if ($coverPath) {
        @unlink('/var/www/html/' . $coverPath);
    }
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

    $stmt = $db->prepare("SELECT b.title, b.cover_path, b.slug, b.published_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path
        FROM blog_posts b JOIN users u ON u.id = b.user_id JOIN profiles p ON p.user_id = u.id
        WHERE b.user_id IN ($placeholders) ORDER BY b.published_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'tipo' => 'blog', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'],
            'url' => blogPostUrl($r['user_slug'], $r),
        ];
    }

    $stmt = $db->prepare("SELECT tr.id, tr.track_name, tr.track_image, tr.artist_name, tr.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path
        FROM favorite_tracks tr JOIN users u ON u.id = tr.user_id JOIN profiles p ON p.user_id = u.id
        WHERE tr.user_id IN ($placeholders) ORDER BY tr.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'tipo' => 'brano', 'titolo' => $r['track_name'] . ' — ' . $r['artist_name'], 'cover' => $r['track_image'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'],
            'url' => '/' . $r['user_slug'] . '/brani',
        ];
    }

    $stmt = $db->prepare("SELECT e.id, e.title, e.cover_path, e.created_at AS data, e.event_date, u.slug AS user_slug, p.display_name, p.avatar_path
        FROM events e JOIN users u ON u.id = e.user_id JOIN profiles p ON p.user_id = u.id
        WHERE e.user_id IN ($placeholders) ORDER BY e.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'tipo' => 'evento', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'evento_quando' => $r['event_date'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'],
            'url' => '/' . $r['user_slug'] . '/eventi/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT tp.id, tp.testo, tp.image_path, tp.created_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path
        FROM timeline_posts tp JOIN users u ON u.id = tp.user_id JOIN profiles p ON p.user_id = u.id
        WHERE tp.user_id IN ($placeholders) AND tp.visibility = 'public' AND (tp.publish_at IS NULL OR tp.publish_at <= NOW())
        ORDER BY tp.created_at DESC LIMIT 200");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'tipo' => 'pensiero', 'titolo' => $r['testo'] ? textExcerpt($r['testo'], 100) : '📷 Foto', 'cover' => $r['image_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'],
            'url' => '/' . $r['user_slug'] . '/timeline/' . $r['id'],
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

function renderDashboardTimelineItem(array $item, ?string $viewerSlug = null): string {
    $coverSrc = $item['cover'] ? (str_starts_with($item['cover'], 'http') ? $item['cover'] : '/' . $item['cover']) : null;
    $labels = ['blog' => '📝 Articolo', 'brano' => '🎵 Brano', 'evento' => '📅 Evento', 'pensiero' => '💬 Aggiornamento'];
    $label = $labels[$item['tipo']] ?? '';
    $eventoInfo = '';
    if ($item['tipo'] === 'evento' && !empty($item['evento_quando'])) {
        $eventoInfo = ' · si terrà il ' . e(date('d/m/Y', strtotime($item['evento_quando'])));
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
    $html .= '<small style="color:var(--text-muted)">' . e(date('d/m/Y', strtotime($item['data']))) . $eventoInfo . '</small>';
    $html .= '</div></a>';
    return $html;
}

function renderTimelineFeedItem(array $item): string {
    $coverSrc = $item['cover'] ? (str_starts_with($item['cover'], 'http') ? $item['cover'] : '/' . $item['cover']) : null;
    $labels = ['blog' => '📝 Articolo', 'brano' => '🎵 Brano', 'evento' => '📅 Evento', 'pensiero' => '💬 Aggiornamento'];
    $label = $labels[$item['tipo']] ?? '';
    $eventoInfo = '';
    if ($item['tipo'] === 'evento' && !empty($item['evento_quando'])) {
        $eventoInfo = ' · si terrà il ' . e(date('d/m/Y', strtotime($item['evento_quando'])));
    }
    $html = '<a href="' . e($item['url']) . '" class="card" style="display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit;">';
    if ($coverSrc) {
        $html .= '<img src="' . e($coverSrc) . '" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex-shrink:0;">';
    }
    $html .= '<div style="flex:1;min-width:0;">';
    $html .= '<small style="color:rgba(var(--text-rgb),0.6);text-transform:uppercase;">' . e($label) . '</small><br>';
    $html .= '<strong>' . e($item['titolo']) . '</strong><br>';
    $html .= '<small style="color:rgba(var(--text-rgb),0.6);">' . e(date('d/m/Y', strtotime($item['data']))) . $eventoInfo . '</small>';
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

    $labels = ['evento' => 'un nuovo concerto', 'timeline' => 'un nuovo aggiornamento'];
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
    'reserve_table','dashboard_reservations'];

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

// Costruisce il permalink SEO di un articolo: /nomeutente/blog/anno.mese.giorno.slug-articolo
function blogPostUrl(string $userSlug, array $post): string {
    $datePart = date('Y.m.d', strtotime($post['published_at']));
    return '/' . $userSlug . '/blog/' . $datePart . '.' . $post['slug'];
}

// URL assoluta del sito (per meta tag Open Graph / condivisione social), usa SITE_URL se impostata
function siteUrl(string $path = ''): string {
    $base = rtrim(getenv('SITE_URL') ?: '', '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/' . ltrim($path, '/');
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
// Permette a ogni profilo di nascondere singoli tab standard (Home, Timeline, Blog, Brani,
// Menù, Eventi, Contatti) dal proprio menu di navigazione pubblico. Non copre i tab
// "integrazione" (Spotify/Podcast/Video/Segui), che restano governati dalla loro logica
// esistente (compaiono solo se effettivamente collegati).

// Mappa tra il "name" salvato in profile_navigation_menu e la chiave interna usata da
// publicNav() per identificare ciascun tab — tenerle distinte evita di legare lo schema del
// database ai nomi visualizzati (che potrebbero cambiare) o a caratteri accentati nelle chiavi.
const PUBLIC_NAV_ITEM_KEYS = [
    'Home' => 'home',
    'Timeline' => 'timeline',
    'Blog' => 'blog',
    'Brani' => 'brani',
    'Menù' => 'menu',
    'Eventi' => 'eventi',
    'Contatti' => 'contatti',
];

/**
 * Crea le voci di menu di default per un profilo, con URL basati sul suo slug reale
 * (idempotente: INSERT IGNORE, non duplica se già esistenti).
 */
function createDefaultProfileNavMenu(int $userId, string $slug): bool {
    $defaults = [
        ['Home', 'fas fa-home', '/' . $slug, 1],
        ['Timeline', 'fas fa-stream', '/' . $slug . '/timeline', 2],
        ['Blog', 'fas fa-newspaper', '/' . $slug . '/blog', 3],
        ['Brani', 'fas fa-music', '/' . $slug . '/brani', 4],
        ['Menù', 'fas fa-utensils', '/' . $slug . '/menu', 5],
        ['Eventi', 'fas fa-calendar', '/' . $slug . '/eventi', 6],
        ['Contatti', 'fas fa-envelope', '/' . $slug . '/contatti', 7],
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
 * Ottiene TUTTE le voci di menu per un profilo (incluse nascoste), creando i default al primo
 * accesso se il profilo non le ha ancora (es. account creati prima di questa funzionalità).
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
    if (!$items) {
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
function getHiddenNavKeys(int $userId): array {
    $stmt = getDB()->prepare('SELECT name FROM profile_navigation_menu WHERE user_id = ? AND is_visible = 0');
    $stmt->execute([$userId]);
    $hidden = [];
    foreach ($stmt->fetchAll() as $row) {
        if (isset(PUBLIC_NAV_ITEM_KEYS[$row['name']])) {
            $hidden[] = PUBLIC_NAV_ITEM_KEYS[$row['name']];
        }
    }
    return $hidden;
}
