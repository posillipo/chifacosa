<?php
// Email di notifica, SMTP, script di tracciamento e conversioni.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


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


// Risposta a una richiesta di contatto: testo scritto dal proprietario + messaggio originale citato +
// invito a creare un profilo sul portale. Reply-To = email del proprietario, così il mittente
// può rispondere direttamente. Ritorna true se l'SMTP ha accettato il messaggio.
function notifyContactReply(array $request, array $owner, string $replyText): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $ownerName = (string) ($owner['display_name'] ?? '') ?: (string) ($owner['slug'] ?? '');
    $quoted = '> ' . str_replace("\n", "\n> ", trim(str_replace("\r", '', (string) $request['message'])));
    $subject = 'Risposta da ' . $ownerName . ' su ' . siteName();
    $body = "Ciao {$request['sender_name']},\n\n"
          . trim($replyText) . "\n\n"
          . $ownerName . "\n" . siteUrl('/' . ($owner['slug'] ?? '')) . "\n\n"
          . "----------------------------------------\n"
          . "Il tuo messaggio del " . date('d/m/Y H:i', strtotime((string) $request['created_at'])) . ":\n{$quoted}\n\n"
          . "----------------------------------------\n"
          . "Non perderti nessuna risposta: crea gratis il tuo profilo su " . siteName() . ".\n"
          . "Con un profilo puoi seguire gli artisti, ricevere i loro aggiornamenti e scrivere direttamente dalla piattaforma.\n"
          . "Registrati qui: " . siteUrl('/register.php');

    return $mailer->send($cfg['from'], $cfg['fromName'], (string) $request['sender_email'], (string) $request['sender_name'], $subject, $body, (string) ($owner['email'] ?? ''));
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


// Come notifyFollowConfirmation(), ma per chi verifica l'email per sbloccare il preconto sul
// Menù pubblico (dashboard_menu.php -> profiles.menu_preconto_enabled): stesso meccanismo di
// conferma (finisce comunque nella tabella followers, la persona diventa anche follower vero e
// proprio — vedi menu_preconto.php), solo il testo dell'email cambia per riflettere perché ha
// lasciato i suoi dati.
function notifyPrecontoConfirmation(string $toEmail, string $artistName, string $token, string $confirmUrl): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);

    $subject = "Conferma la tua email per il preconto — {$artistName}";
    $body = "Ciao,\n\n"
          . "Hai chiesto di usare il preconto sul menù di {$artistName} su " . siteName() . ". Conferma la tua\n"
          . "email cliccando questo link, poi torna sul menù per calcolare il totale:\n\n"
          . "{$confirmUrl}\n\n"
          . "Da questo momento segui anche {$artistName} su " . siteName() . ": riceverai un'email quando\n"
          . "pubblica un nuovo articolo o annuncia un nuovo evento.\n\n"
          . "Se non hai richiesto tu questa conferma, ignora pure questa email: non verrà attivato\n"
          . "nulla senza il tuo click.";

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

    $labels = ['evento' => 'un nuovo concerto', 'timeline' => 'un nuovo aggiornamento', 'offerta' => 'una nuova offerta speciale', 'album_foto' => 'un nuovo album fotografico', 'servizio' => 'un nuovo servizio', 'video' => 'un nuovo video'];
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
