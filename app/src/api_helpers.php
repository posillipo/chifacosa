<?php
// Helper condivisi per l'API pubblica /api/v1/social-posts/* — pensata per script/automazioni
// esterne (non per il browser): autenticazione via header "Authorization: Bearer <token>",
// niente sessione/cookie/CSRF (un token Bearer non viene mai allegato automaticamente dal
// browser, quindi non è soggetto a CSRF come i form con sessione — vedi dashboard_ai_caption.php
// per il pattern opposto, usato invece nella pagina admin di gestione token).

const API_RATE_LIMIT_PER_HOUR = 100;

// IP del chiamante reale: dietro il reverse proxy (Nginx Proxy Manager) REMOTE_ADDR è sempre
// l'IP interno del proxy, va letto X-Forwarded-For — stesso principio già applicato a
// requestScheme() per lo schema http/https.
function apiClientIp(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// Termina la richiesta con una risposta JSON di successo, loggando l'esito reale (status code
// incluso) per l'audit trail — vedi logApiRequest().
function apiRespond(int $httpCode, array $data, ?int $tokenId = null, ?int $userId = null): void {
    logApiRequest($tokenId, $userId, $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '', $httpCode);
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Termina la richiesta con un errore JSON in un formato uniforme, loggando comunque il tentativo
// (autenticato o no) per l'audit trail — vedi logApiRequest().
function apiError(int $httpCode, string $message, ?int $tokenId = null, ?int $userId = null): void {
    logApiRequest($tokenId, $userId, $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '', $httpCode);
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function logApiRequest(?int $tokenId, ?int $userId, string $method, string $endpoint, int $statusCode): void {
    $stmt = getDB()->prepare('INSERT INTO api_request_logs (api_token_id, user_id, method, endpoint, status_code, ip_address) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$tokenId, $userId, $method, parse_url($endpoint, PHP_URL_PATH) ?: $endpoint, $statusCode, apiClientIp()]);
}

// Genera un nuovo token API per un profilo: formato sk_{brand}_{32 caratteri casuali}, dove
// {brand} è lo slug del profilo ripulito. Ritorna il token IN CHIARO (mostrato una sola volta a
// chi lo genera) insieme ai dati da salvare — nel database finisce solo l'hash, mai il token.
function generateApiToken(string $slug): array {
    $brand = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('-', '_', $slug)));
    $random = bin2hex(random_bytes(16)); // 32 caratteri esadecimali
    $token = 'sk_' . $brand . '_' . $random;
    return [
        'token' => $token,
        'hash' => hash('sha256', $token),
        'prefix' => substr($token, 0, 24) . '…',
    ];
}

// Autentica la richiesta corrente via header Authorization: Bearer — valida il token, controlla
// stato/scadenza, applica il rate limiting (100 richieste/ora per token) e logga la richiesta.
// Termina la richiesta con un errore JSON (e non ritorna) se qualunque controllo fallisce.
// In caso di successo ritorna ['user_id' => ..., 'token_id' => ..., 'slug' => ...] del profilo
// proprietario del token.
function authenticateApiRequest(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        // Alcune configurazioni PHP-Apache non popolano HTTP_AUTHORIZATION in $_SERVER di
        // default (dipende da mod_php/FastCGI): fallback esplicito.
        $headers = apache_request_headers() ?: [];
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(sk_[A-Za-z0-9_]+)$/', trim($header), $m)) {
        apiError(401, 'Header Authorization mancante o non nel formato "Bearer sk_...".');
    }
    $token = $m[1];
    $hash = hash('sha256', $token);

    $stmt = getDB()->prepare('SELECT at.id, at.user_id, at.is_active, at.expires_at, u.slug
                              FROM api_tokens at JOIN users u ON u.id = at.user_id
                              WHERE at.token_hash = ?');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) {
        apiError(401, 'Token non valido.');
    }
    if (!$row['is_active']) {
        apiError(401, 'Token disattivato.', (int) $row['id'], (int) $row['user_id']);
    }
    if ($row['expires_at'] && strtotime($row['expires_at']) <= time()) {
        apiError(401, 'Token scaduto.', (int) $row['id'], (int) $row['user_id']);
    }

    $tokenId = (int) $row['id'];
    $userId = (int) $row['user_id'];

    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM api_request_logs WHERE api_token_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$tokenId]);
    if ((int) $stmt->fetch()['c'] >= API_RATE_LIMIT_PER_HOUR) {
        apiError(429, 'Limite di ' . API_RATE_LIMIT_PER_HOUR . ' richieste/ora superato per questo token.', $tokenId, $userId);
    }

    getDB()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$tokenId]);

    // Il logging della richiesta vera e propria (con lo status code reale) spetta a chi chiama
    // apiRespond()/apiError() più avanti — qui logghiamo solo quanto serve per il rate limiting,
    // che conta le righe già presenti PRIMA di questa richiesta.
    return ['user_id' => $userId, 'token_id' => $tokenId, 'slug' => $row['slug']];
}

// Legge e decodifica il body JSON della richiesta corrente. Termina con un errore 400 se il
// corpo non è JSON valido (un oggetto vuoto {} è valido: alcuni endpoint non richiedono campi).
function apiReadJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        apiError(400, 'Corpo della richiesta non è JSON valido.');
    }
    return $data;
}

// Scarica un'immagine da URL esterno e la salva come copertina (stesso trattamento delle foto
// caricate a mano: ricompressa/ridimensionata via compressImageToJpeg, più una miniatura fino a
// 600px come da convenzione del sito — vedi admin_regenerate_thumbs.php). Ritorna
// ['image_path' => ..., 'thumb_path' => ...] o null se il download/salvataggio fallisce. Usa
// cinemaHttpGet()/isSafePublicUrl() (già in uso per il feed Cinema) invece di un whitelist per
// dominio: un whitelist fisso su un solo CDN si aggira/rompe facilmente (basta un redirect o un
// cambio di infrastruttura lato Canva), mentre il controllo SSRF blocca la vera minaccia — che il
// server richieda un indirizzo interno/privato — qualunque sia il dominio di partenza.
function downloadImageFromUrlAsCover(string $url, string $slug): ?array {
    $data = cinemaHttpGet($url, 20);
    if ($data === null || strlen($data) < 100 || strlen($data) > 15 * 1024 * 1024) {
        return null;
    }
    $jpeg = compressImageToJpeg($data);
    if ($jpeg === null) {
        return null;
    }
    $dir = '/var/www/html/uploads/images/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $fname = bin2hex(random_bytes(6)) . '.jpg';
    if (file_put_contents($dir . '/' . $fname, $jpeg) === false) {
        return null;
    }
    $imagePath = 'uploads/images/' . $slug . '/' . $fname;

    $thumbPath = null;
    $img = @imagecreatefromstring($jpeg);
    if ($img !== false) {
        $width = imagesx($img);
        $height = imagesy($img);
        $maxDim = 600;
        $scale = min(1, $maxDim / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($img);
        $thumbFname = 'thumb_' . bin2hex(random_bytes(6)) . '.jpg';
        if (imagejpeg($resized, $dir . '/' . $thumbFname, 82)) {
            $thumbPath = 'uploads/images/' . $slug . '/' . $thumbFname;
        }
        imagedestroy($resized);
    }

    return ['image_path' => $imagePath, 'thumb_path' => $thumbPath];
}

// Converte un DATETIME salvato (nel fuso del server, UTC) in ISO 8601 nel fuso Europe/Rome — le
// risposte dell'API mostrano sempre le date in questo fuso, come dalla nota "Timezone: Europe/Rome"
// della specifica, indipendentemente da come il server è configurato internamente.
function apiFormatDateTimeRome(?string $datetime): ?string {
    if (!$datetime) {
        return null;
    }
    $dt = new DateTime($datetime, new DateTimeZone(date_default_timezone_get()));
    $dt->setTimezone(new DateTimeZone('Europe/Rome'));
    return $dt->format(DateTime::ATOM);
}

// Deriva lo status "logico" (draft/scheduled/published) usato dall'API dalle colonne esistenti
// di timeline_posts (visibility + publish_at) — non serve una colonna status a parte, la
// combinazione delle due è già sufficiente e coerente con come il resto del sito già interpreta
// questi due campi (vedi WHERE in getTimelineFeedForUsers() e timeline_post.php).
function apiDerivePostStatus(array $post): string {
    if (($post['visibility'] ?? 'public') === 'private') {
        return 'draft';
    }
    if (!empty($post['publish_at']) && strtotime($post['publish_at']) > time()) {
        return 'scheduled';
    }
    return 'published';
}

// Trasforma una riga di timeline_posts nella forma esposta dall'API pubblica.
function apiSerializePost(array $post, string $slug): array {
    return [
        'id' => (int) $post['id'],
        'title' => $post['title'] ?? null,
        'description' => $post['testo'] ?? null,
        'image_url' => $post['image_path'] ? siteUrl('/' . $post['image_path']) : null,
        'hashtags' => $post['hashtags'] ?? null,
        'call_to_action' => $post['call_to_action'] ?? null,
        'status' => apiDerivePostStatus($post),
        'publication_date' => apiFormatDateTimeRome($post['publish_at'] ?? null),
        'source' => $post['source'] ?? 'dashboard',
        'created_at' => apiFormatDateTimeRome($post['created_at'] ?? null),
        'url' => siteUrl('/' . $slug . '/timeline/' . (int) $post['id']),
    ];
}

// Valida e normalizza il payload JSON di create/update per un social post. $partial=true per PUT
// (tutti i campi opzionali, solo quelli presenti vengono validati/aggiornati). Ritorna
// ['error' => string|null, 'values' => [...]] — 'values' contiene solo le chiavi effettivamente
// fornite (utile per un UPDATE parziale che non deve toccare i campi non inclusi nel payload).
function apiValidateSocialPostPayload(array $data, bool $partial): array {
    $values = [];

    if (array_key_exists('title', $data)) {
        $title = trim((string) $data['title']);
        if (mb_strlen($title) > 100) {
            return ['error' => 'Il campo "title" supera i 100 caratteri consentiti.', 'values' => []];
        }
        $values['title'] = $title !== '' ? $title : null;
    }
    if (array_key_exists('description', $data)) {
        $values['testo'] = trim((string) $data['description']) ?: null;
    }
    if (array_key_exists('hashtags', $data)) {
        $hashtags = trim((string) $data['hashtags']);
        if (mb_strlen($hashtags) > 300) {
            return ['error' => 'Il campo "hashtags" supera i 300 caratteri consentiti.', 'values' => []];
        }
        $values['hashtags'] = $hashtags !== '' ? $hashtags : null;
    }
    if (array_key_exists('call_to_action', $data)) {
        $cta = trim((string) $data['call_to_action']);
        if (mb_strlen($cta) > 200) {
            return ['error' => 'Il campo "call_to_action" supera i 200 caratteri consentiti.', 'values' => []];
        }
        $values['call_to_action'] = $cta !== '' ? $cta : null;
    }

    if (array_key_exists('image_url', $data) && trim((string) $data['image_url']) !== '') {
        $imageUrl = trim((string) $data['image_url']);
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !isSafePublicUrl($imageUrl)) {
            return ['error' => 'Il campo "image_url" non è un URL pubblico valido.', 'values' => []];
        }
        $values['image_url'] = $imageUrl;
    }

    $publishAt = null;
    $hasPublicationDate = array_key_exists('publication_date', $data) && trim((string) $data['publication_date']) !== '';
    if ($hasPublicationDate) {
        try {
            $dt = new DateTime((string) $data['publication_date']);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $publishAt = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return ['error' => 'Il campo "publication_date" non è una data valida (usa il formato ISO 8601, es. 2026-09-24T08:00:00+02:00).', 'values' => []];
        }
    }

    $status = array_key_exists('status', $data) ? trim((string) $data['status']) : null;
    if ($status !== null && !in_array($status, ['draft', 'scheduled', 'published'], true)) {
        return ['error' => 'Il campo "status" deve essere uno tra draft, scheduled, published.', 'values' => []];
    }
    if ($status === null && !$partial) {
        // Creazione senza "status" esplicito: dedotto dalla data di pubblicazione, come nel form
        // della dashboard (dashboard_post.php) quando si programma una pubblicazione futura.
        $status = ($hasPublicationDate && strtotime($publishAt) > time()) ? 'scheduled' : 'published';
    }
    if ($status === 'scheduled' && (!$hasPublicationDate || strtotime($publishAt) <= time())) {
        return ['error' => 'status "scheduled" richiede un "publication_date" nel futuro.', 'values' => []];
    }

    if ($status !== null) {
        $values['visibility'] = $status === 'draft' ? 'private' : 'public';
        if ($hasPublicationDate) {
            $values['publish_at'] = $status === 'published' && strtotime($publishAt) <= time() ? null : $publishAt;
        } elseif (!$partial) {
            $values['publish_at'] = null;
        }
    } elseif ($hasPublicationDate) {
        $values['publish_at'] = $publishAt;
    }

    if (!$partial) {
        $hasAnyContent = !empty($values['title']) || !empty($values['testo']) || !empty($values['image_url']);
        if (!$hasAnyContent) {
            return ['error' => 'Fornisci almeno uno tra "title", "description" o "image_url".', 'values' => []];
        }
    }

    return ['error' => null, 'values' => $values];
}
