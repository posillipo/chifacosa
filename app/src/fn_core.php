<?php
// Utilità di base: escape, CSRF, URL del sito, impostazioni, installazione, testo.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


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


// "500M" -> 524288000 (i valori di php.ini accettano i suffissi K, M, G).
function iniSizeToBytes(string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $n = (float) $v;
    switch (strtoupper(substr($v, -1))) {
        case 'G': $n *= 1024;   // no break
        case 'M': $n *= 1024;   // no break
        case 'K': $n *= 1024;
    }
    return (int) $n;
}


// Attributi da mettere su un <form enctype="multipart/form-data"> per avere la barra di avanzamento
// (assets/js/upload-progress.js) e il controllo, prima dell'invio, di dimensione e numero di file.
function uploadProgressAttrs(): string {
    return ' data-upload-progress data-max-bytes="' . iniSizeToBytes((string) ini_get('post_max_size'))
         . '" data-max-files="' . (int) ini_get('max_file_uploads') . '"';
}


function checkCsrf(): void {
    // Richiesta con un corpo ma NESSUN campo: succede quando i file inviati superano post_max_size
    // (PHP scarta in silenzio tutto, token incluso). Meglio dirlo chiaramente che mostrare "CSRF".
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        die('Il caricamento supera il limite del server (massimo ' . e((string) ini_get('post_max_size')) . ' per invio, '
            . e((string) ini_get('max_file_uploads')) . ' file alla volta). Riprova caricando meno foto per volta.');
    }
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


function siteIsMyBand(): bool {
    return stripos($_SERVER['HTTP_HOST'] ?? '', 'myband') !== false || stripos($_SERVER['HTTP_HOST'] ?? '', 'my-band') !== false;
}


// Logo testuale della barra in alto della dashboard: "CHI<FA>COSA" su chifacosa, "MY<BAND>" su myband.
function siteBrandHtml(): string {
    if (siteIsMyBand()) {
        return 'MY<strong>BAND</strong>';
    }
    return 'CHI<strong>FA</strong>COSA';
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


// Reindirizzamento fisso per UN SOLO post della Timeline (timeline_posts.redirect_link) —
// a differenza di emitCustomFeedLinkRedirect() (impostazione del profilo, con soglia temporale,
// valida per tutti i contenuti pubblicati da un certo momento in poi), questo vale sempre e solo
// per il post a cui è stato assegnato, indipendentemente da quando viene aperto o da cos'altro è
// stato pubblicato nel frattempo. Va richiamata nell'<head>, il prima possibile.
function emitPostRedirectLink(?string $redirectLink): void {
    if (!$redirectLink) {
        return;
    }
    echo '<script>location.replace(' . json_encode($redirectLink) . ');</script>' . "\n";
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
