<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * "Accedi con Google" — OAuth 2.0 / OpenID Connect, completamente gratuito (nessun costo per
 * l'autenticazione, solo un progetto gratuito su Google Cloud Console per ottenere Client
 * ID/Secret). Stesso approccio già usato per Spotify/TMDb/Google Books/Geoapify: solo
 * httpRequest(), nessuna libreria/SDK esterna.
 */

function getGoogleOAuthClientId(): ?string {
    $v = getSiteSetting('google_oauth_client_id');
    return $v !== '' && $v !== null ? $v : null;
}

function getGoogleOAuthClientSecret(): ?string {
    $v = getSiteSetting('google_oauth_client_secret');
    return $v !== '' && $v !== null ? $v : null;
}

// URI di reindirizzamento da registrare nel progetto Google Cloud Console ("URI di
// reindirizzamento autorizzati") — DELIBERATAMENTE basato sull'host della richiesta corrente
// (come siteUrl() quando SITE_URL non è impostato), non su SITE_URL: il cookie di sessione PHP
// resta legato all'host esatto che l'ha impostato (nessun cookie_domain esplicito in
// session-security.ini). Se questo URI fosse fisso su un solo host (es. sempre senza "www."),
// un visitatore arrivato su "www.<dominio>" avrebbe il cookie di sessione legato a "www.", ma al
// ritorno da Google (reindirizzato sul dominio fisso senza "www.") il browser non lo manderebbe
// più — sessione e "state" OAuth risulterebbero vuoti e il login fallirebbe in modo
// intermittente, proprio a seconda di quale variante del dominio l'utente stava usando quando
// ha cliccato "Accedi con Google" (bug reale riscontrato su myband.it, login a volte fallito, al
// tentativo successivo riuscito). Usando sempre l'host della richiesta corrente, il redirect_uri
// coincide sempre con l'host su cui il cookie è stato impostato — ma questo significa che in
// Google Cloud Console vanno registrate TUTTE le varianti di dominio realmente raggiungibili
// (con e senza "www."), non solo quella "ufficiale" di SITE_URL — vedi le istruzioni mostrate in
// admin_google_login.php.
function googleOAuthRedirectUri(): string {
    return requestScheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth_google_callback.php';
}

// Costruisce l'URL della schermata di consenso Google a cui reindirizzare il browser. $state è
// un valore casuale salvato in sessione, riverificato al ritorno per escludere richieste
// contraffatte (CSRF sul flusso di login).
function googleOAuthAuthUrl(string $state): string {
    $params = [
        'client_id' => getGoogleOAuthClientId(),
        'redirect_uri' => googleOAuthRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

// Scambia il "code" ricevuto nel redirect di ritorno con un vero access token — richiesta
// server-to-server, il client_secret non passa mai dal browser.
function googleOAuthExchangeCode(string $code): ?string {
    $clientId = getGoogleOAuthClientId();
    $clientSecret = getGoogleOAuthClientSecret();
    if (!$clientId || !$clientSecret || $code === '') {
        return null;
    }
    $body = http_build_query([
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => googleOAuthRedirectUri(),
        'grant_type' => 'authorization_code',
    ]);
    $response = httpRequest('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], $body);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

// Dati base dell'account Google autenticato: email (già verificata da Google), nome visualizzato.
function googleOAuthGetUserInfo(string $accessToken): ?array {
    $response = httpRequest('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', ['Authorization: Bearer ' . $accessToken]);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    // Non basta che Google restituisca un indirizzo: se email_verified non è true, quell'email
    // non è stata effettivamente confermata sull'account Google (es. alias di dominio aggiunto
    // da un admin Workspace ma mai verificato) — accettarla comunque permetterebbe di autenticarsi
    // come un account chifacosa esistente con quella email senza averne mai dimostrato il possesso.
    if (empty($data['email']) || empty($data['email_verified'])) {
        return null;
    }
    return [
        'email' => strtolower(trim($data['email'])),
        'name' => trim($data['name'] ?? '') ?: null,
    ];
}
