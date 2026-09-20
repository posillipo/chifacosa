<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Integrazione con Cloudflare Turnstile — CAPTCHA invisibile/gratuito per proteggere i form
 * pubblici del sito (Contatti, Richiedi informazioni, Prenota tavolo, Preconto, Segui) dallo
 * spam automatizzato. Richiesto dall'utente dopo un'ondata di messaggi spam sul form Contatti.
 *
 * Finché in ADMIN -> Antispam non viene salvata una Site Key, il widget non compare e la
 * verifica lato server è sempre "a vuoto" (true): tutti i form restano utilizzabili esattamente
 * come prima dell'introduzione di questo file — nessun rischio di bloccare form legittimi con
 * una configurazione a metà.
 */

function getTurnstileSiteKey(): ?string {
    $key = getSiteSetting('turnstile_site_key');
    return $key !== '' ? $key : null;
}

function getTurnstileSecretKey(): ?string {
    $key = getSiteSetting('turnstile_secret_key');
    return $key !== '' ? $key : null;
}

function isTurnstileEnabled(): bool {
    return getTurnstileSiteKey() !== null;
}

// Markup del widget — va dentro il <form> protetto, prima del pulsante di invio. Lo script
// dell'API Cloudflare viene incluso una sola volta per pagina anche se il widget compare più
// volte (caso raro, ma es. una pagina con più form nello stesso caricamento).
function renderTurnstileWidget(): string {
    $siteKey = getTurnstileSiteKey();
    if (!$siteKey) {
        return '';
    }
    static $scriptPrinted = false;
    $html = '<div class="cf-turnstile" data-sitekey="' . e($siteKey) . '" data-theme="auto" style="margin:10px 0;"></div>';
    if (!$scriptPrinted) {
        $html = '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' . $html;
        $scriptPrinted = true;
    }
    return $html;
}

// Verifica lato server il token del widget contro l'API di Cloudflare — da chiamare SEMPRE dopo
// checkCsrf(), prima di salvare i dati del form. Se Turnstile non è configurato (nessuna secret
// key salvata) la verifica è "a vuoto" (true), vedi commento in testa al file.
function verifyTurnstileToken(): bool {
    $secretKey = getTurnstileSecretKey();
    if (!$secretKey) {
        return true;
    }
    $token = $_POST['cf-turnstile-response'] ?? '';
    if ($token === '') {
        return false;
    }
    $body = http_build_query([
        'secret' => $secretKey,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $response = httpRequest('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', ['Content-Type: application/x-www-form-urlencoded'], $body);
    if (!$response) {
        return false;
    }
    $data = json_decode($response, true);
    return !empty($data['success']);
}
