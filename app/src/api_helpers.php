<?php
// Helper condivisi per l'API pubblica /api/v1/social-posts/* — pensata per script/automazioni
// esterne (non per il browser): autenticazione via header "Authorization: Bearer <token>",
// niente sessione/cookie/CSRF (un token Bearer non viene mai allegato automaticamente dal
// browser, quindi non è soggetto a CSRF come i form con sessione — vedi dashboard_ai_caption.php
// per il pattern opposto, usato invece nella pagina admin di gestione token).

const API_RATE_LIMIT_PER_HOUR = 500;

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
        'redirect_link' => $post['redirect_link'] ?? null,
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
    if (array_key_exists('redirect_link', $data)) {
        $redirectLink = trim((string) $data['redirect_link']);
        if ($redirectLink !== '' && !filter_var($redirectLink, FILTER_VALIDATE_URL)) {
            return ['error' => 'Il campo "redirect_link" non è un URL valido.', 'values' => []];
        }
        $values['redirect_link'] = $redirectLink !== '' ? $redirectLink : null;
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

// Deriva lo status "logico" (scheduled/published) di un articolo blog dalla sola colonna
// published_at — a differenza di timeline_posts, blog_posts non ha una colonna visibility: non
// esiste un concetto di "draft" per il blog (stessa scelta già fatta in dashboard_blog_edit.php),
// solo "pubblicato subito" o "programmato per il futuro".
function apiDeriveBlogPostStatus(array $post): string {
    if (!empty($post['published_at']) && strtotime($post['published_at']) > time()) {
        return 'scheduled';
    }
    return 'published';
}

// Trasforma una riga di blog_posts nella forma esposta dall'API pubblica.
function apiSerializeBlogPost(array $post, string $slug): array {
    return [
        'id' => (int) $post['id'],
        'title' => $post['title'],
        'content' => $post['content'],
        'excerpt' => $post['excerpt'],
        'tags' => $post['tags'],
        'categories' => array_column(getBlogPostCategories((int) $post['id']), 'name'),
        'cover_image_url' => $post['cover_path'] ? siteUrl('/' . $post['cover_path']) : null,
        'status' => apiDeriveBlogPostStatus($post),
        'publication_date' => apiFormatDateTimeRome($post['published_at'] ?? null),
        'url' => siteUrl(blogPostUrl($slug, $post)),
    ];
}

// Trova o crea (per nome, non case-sensitive sul confronto) le categorie di un profilo — un
// chiamante API non può conoscere gli ID interni delle categorie, quindi accetta nomi liberi
// esattamente come farebbe compilando le checkbox in dashboard_blog_new.php, con la differenza
// che una categoria non ancora esistente viene creata al volo invece di essere ignorata.
function apiResolveOrCreateBlogCategories(int $userId, array $names): array {
    $ids = [];
    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $stmt = getDB()->prepare('SELECT id FROM blog_categories WHERE user_id = ? AND name = ?');
        $stmt->execute([$userId, $name]);
        $row = $stmt->fetch();
        if ($row) {
            $ids[] = (int) $row['id'];
            continue;
        }
        $catSlug = generateUniqueBlogCategorySlug($userId, $name);
        $ins = getDB()->prepare('INSERT INTO blog_categories (user_id, name, slug) VALUES (?,?,?)');
        $ins->execute([$userId, $name, $catSlug]);
        $ids[] = (int) getDB()->lastInsertId();
    }
    return array_values(array_unique($ids));
}

// Valida e normalizza il payload JSON di create/update per un articolo blog. $partial=true per
// PUT (tutti i campi opzionali, solo quelli presenti vengono validati/aggiornati) — stesso
// principio di apiValidateSocialPostPayload(). 'categories' resta un elenco di nomi grezzi (non
// ancora risolto in ID: lo fa apiResolveOrCreateBlogCategories(), che serve anche in update dove
// va rifatto DOPO aver caricato l'articolo esistente).
function apiValidateBlogPostPayload(array $data, bool $partial): array {
    $values = [];

    if (array_key_exists('title', $data)) {
        $title = trim((string) $data['title']);
        if (mb_strlen($title) > 200) {
            return ['error' => 'Il campo "title" supera i 200 caratteri consentiti.', 'values' => []];
        }
        if (!$partial && $title === '') {
            return ['error' => 'Il campo "title" è obbligatorio.', 'values' => []];
        }
        if ($partial && $title === '') {
            return ['error' => 'Il campo "title" non può essere svuotato.', 'values' => []];
        }
        $values['title'] = $title;
    }
    if (array_key_exists('content', $data)) {
        $content = trim((string) $data['content']);
        if (!$partial && $content === '') {
            return ['error' => 'Il campo "content" è obbligatorio.', 'values' => []];
        }
        if ($partial && $content === '') {
            return ['error' => 'Il campo "content" non può essere svuotato.', 'values' => []];
        }
        $values['content'] = $content;
    }
    if (array_key_exists('tags', $data)) {
        $tagsRaw = trim((string) $data['tags']);
        if (mb_strlen($tagsRaw) > 300) {
            return ['error' => 'Il campo "tags" supera i 300 caratteri consentiti.', 'values' => []];
        }
        $tags = $tagsRaw !== '' ? implode(', ', array_filter(array_map('trim', explode(',', $tagsRaw)), fn ($t) => $t !== '')) : null;
        $values['tags'] = $tags ?: null;
    }
    if (array_key_exists('categories', $data)) {
        if (!is_array($data['categories'])) {
            return ['error' => 'Il campo "categories" deve essere un elenco di nomi.', 'values' => []];
        }
        $values['categories'] = $data['categories'];
    }

    if (array_key_exists('image_url', $data) && trim((string) $data['image_url']) !== '') {
        $imageUrl = trim((string) $data['image_url']);
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !isSafePublicUrl($imageUrl)) {
            return ['error' => 'Il campo "image_url" non è un URL pubblico valido.', 'values' => []];
        }
        $values['image_url'] = $imageUrl;
    }

    if (array_key_exists('publication_date', $data) && trim((string) $data['publication_date']) !== '') {
        try {
            $dt = new DateTime((string) $data['publication_date']);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $values['published_at'] = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return ['error' => 'Il campo "publication_date" non è una data valida (usa il formato ISO 8601, es. 2026-09-24T08:00:00+02:00).', 'values' => []];
        }
    }

    return ['error' => null, 'values' => $values];
}

// Trasforma una riga di events nella forma esposta dall'API pubblica.
function apiSerializeEvent(array $event, string $slug): array {
    return [
        'id' => (int) $event['id'],
        'title' => $event['title'],
        'venue' => $event['venue'],
        'city' => $event['city'],
        'provincia' => $event['provincia'] ?? null,
        'event_date' => apiFormatDateTimeRome($event['event_date'] ?? null),
        'ticket_url' => $event['ticket_url'],
        'description' => $event['description'],
        'is_perpetual' => (bool) $event['is_perpetual'],
        'recurrence' => $event['recurrence'],
        'accepts_reservations' => (bool) $event['accepts_reservations'],
        'cover_image_url' => $event['cover_path'] ? siteUrl('/' . $event['cover_path']) : null,
        'url' => siteUrl('/' . $slug . '/eventi/' . (int) $event['id']),
    ];
}

// Valida e normalizza il payload JSON di create/update per un evento. $partial=true per PUT
// (tutti i campi opzionali, solo quelli presenti vengono validati/aggiornati) — stesso principio
// di apiValidateBlogPostPayload(). A differenza di Timeline/Blog un evento non ha un concetto di
// programmazione/bozza (vedi dashboard_events.php): è sempre visibile subito, "event_date" è solo
// quando si terrà, non quando pubblicarlo.
function apiValidateEventPayload(array $data, bool $partial): array {
    $values = [];

    if (array_key_exists('title', $data)) {
        $title = trim((string) $data['title']);
        if (mb_strlen($title) > 150) {
            return ['error' => 'Il campo "title" supera i 150 caratteri consentiti.', 'values' => []];
        }
        if (!$partial && $title === '') {
            return ['error' => 'Il campo "title" è obbligatorio.', 'values' => []];
        }
        if ($partial && $title === '') {
            return ['error' => 'Il campo "title" non può essere svuotato.', 'values' => []];
        }
        $values['title'] = $title;
    }
    if (array_key_exists('venue', $data)) {
        $venue = trim((string) $data['venue']);
        if (mb_strlen($venue) > 150) {
            return ['error' => 'Il campo "venue" supera i 150 caratteri consentiti.', 'values' => []];
        }
        $values['venue'] = $venue !== '' ? $venue : null;
    }
    if (array_key_exists('city', $data)) {
        $city = trim((string) $data['city']);
        if (mb_strlen($city) > 100) {
            return ['error' => 'Il campo "city" supera i 100 caratteri consentiti.', 'values' => []];
        }
        $values['city'] = $city !== '' ? $city : null;
    }
    if (array_key_exists('provincia', $data)) {
        $provincia = trim((string) $data['provincia']);
        if (mb_strlen($provincia) > 100) {
            return ['error' => 'Il campo "provincia" supera i 100 caratteri consentiti.', 'values' => []];
        }
        $values['provincia'] = $provincia !== '' ? $provincia : null;
    }
    if (array_key_exists('ticket_url', $data)) {
        $ticketUrl = trim((string) $data['ticket_url']);
        if ($ticketUrl !== '' && !filter_var($ticketUrl, FILTER_VALIDATE_URL)) {
            return ['error' => 'Il campo "ticket_url" non è un URL valido.', 'values' => []];
        }
        $values['ticket_url'] = $ticketUrl !== '' ? $ticketUrl : null;
    }
    if (array_key_exists('description', $data)) {
        $values['description'] = trim((string) $data['description']) ?: null;
    }
    if (array_key_exists('is_perpetual', $data)) {
        $values['is_perpetual'] = !empty($data['is_perpetual']) ? 1 : 0;
    }
    if (array_key_exists('recurrence', $data)) {
        $recurrence = (string) $data['recurrence'];
        if (!in_array($recurrence, ['none', 'weekdays', 'weekend'], true)) {
            return ['error' => 'Il campo "recurrence" deve essere uno tra none, weekdays, weekend.', 'values' => []];
        }
        $values['recurrence'] = $recurrence;
    }
    if (array_key_exists('accepts_reservations', $data)) {
        $values['accepts_reservations'] = !empty($data['accepts_reservations']) ? 1 : 0;
    }
    if (array_key_exists('image_url', $data) && trim((string) $data['image_url']) !== '') {
        $imageUrl = trim((string) $data['image_url']);
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !isSafePublicUrl($imageUrl)) {
            return ['error' => 'Il campo "image_url" non è un URL pubblico valido.', 'values' => []];
        }
        $values['image_url'] = $imageUrl;
    }

    if (array_key_exists('event_date', $data) && trim((string) $data['event_date']) !== '') {
        try {
            $dt = new DateTime((string) $data['event_date']);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $values['event_date'] = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return ['error' => 'Il campo "event_date" non è una data valida (usa il formato ISO 8601, es. 2026-09-24T21:00:00+02:00).', 'values' => []];
        }
    } elseif ($partial && array_key_exists('event_date', $data)) {
        return ['error' => 'Il campo "event_date" non può essere svuotato.', 'values' => []];
    }

    return ['error' => null, 'values' => $values];
}

// Trasforma una riga di links (link_type='film', vedi syncCinemaFilms()) nella forma esposta
// dall'API pubblica di sola lettura /api/v1/cinema-films/list — usata per capire quali film sono
// stati aggiunti di recente (added_at) e creare un post Timeline/Blog per ciascuno.
function apiSerializeCinemaFilm(array $link): array {
    return [
        'id' => $link['external_ref'],
        'title' => $link['label'],
        'film_url' => $link['url'],
        'cover_image_url' => $link['cover_path'] ? siteUrl('/' . $link['cover_path']) : null,
        'added_at' => apiFormatDateTimeRome($link['created_at'] ?? null),
    ];
}

// ---------------------------------------------------------------------------------------------
// Bacheca condivisa tra AI e direttore (/api/v1/board/*): messaggi, brief e consegne scambiati tra
// più assistenti (Claude, Grok, Manus, ...) e la persona che dirige il lavoro. Vive nello stesso
// profilo/token delle altre risorse. "author" e "recipient" sono etichette libere (slug), NON
// identità autenticate: un token API identifica il profilo, non quale AI lo sta usando.
// ---------------------------------------------------------------------------------------------

const BOARD_MESSAGE_TYPES = ['brief', 'delivery', 'review', 'note'];
const BOARD_STATUSES = ['open', 'in_progress', 'delivered', 'awaiting_approval', 'approved', 'rework', 'closed'];
const BOARD_REF_TYPES = ['blog_post', 'social_post', 'event'];
const BOARD_BODY_MAX_CHARS = 20000;

function apiSerializeBoardMessage(array $m): array {
    return [
        'id' => (int) $m['id'],
        'thread_id' => $m['thread_id'] !== null ? (int) $m['thread_id'] : null,
        'reply_to_id' => $m['reply_to_id'] !== null ? (int) $m['reply_to_id'] : null,
        'author' => $m['author'],
        'recipient' => $m['recipient'],
        'type' => $m['message_type'],
        'status' => $m['status'],
        'body' => $m['body'],
        'ref_type' => $m['ref_type'],
        'ref_id' => $m['ref_id'] !== null ? (int) $m['ref_id'] : null,
        'created_at' => apiFormatDateTimeRome($m['created_at'] ?? null),
        'updated_at' => apiFormatDateTimeRome($m['updated_at'] ?? null),
    ];
}

// Etichetta di un autore/destinatario: slug minuscolo (es. "claude", "grok", "manus", "direttore"),
// oppure "all" come destinatario per un messaggio rivolto a tutti.
function apiNormalizeBoardActor(string $value): ?string {
    $v = strtolower(trim($value));
    return preg_match('/^[a-z0-9_-]{2,30}$/', $v) ? $v : null;
}

// Valida e normalizza il payload di create/update di un messaggio. $partial=true per PUT: solo
// status, recipient, body e il riferimento al contenuto sono modificabili — autore, tipo e
// thread restano quelli originali (la bacheca è un registro, non si riscrive la storia).
function apiValidateBoardPayload(array $data, bool $partial): array {
    $values = [];

    if (!$partial) {
        $author = apiNormalizeBoardActor((string) ($data['author'] ?? ''));
        if ($author === null) {
            return ['error' => 'Il campo "author" è obbligatorio: 2-30 caratteri tra lettere minuscole, numeri, "-" e "_" (es. claude, grok, manus, direttore).', 'values' => []];
        }
        $values['author'] = $author;

        $type = (string) ($data['type'] ?? 'note');
        if (!in_array($type, BOARD_MESSAGE_TYPES, true)) {
            return ['error' => 'Il campo "type" deve essere uno tra ' . implode(', ', BOARD_MESSAGE_TYPES) . '.', 'values' => []];
        }
        $values['message_type'] = $type;

        if (array_key_exists('reply_to_id', $data) && $data['reply_to_id'] !== null) {
            if (!is_int($data['reply_to_id']) || $data['reply_to_id'] <= 0) {
                return ['error' => 'Il campo "reply_to_id" deve essere l\'ID numerico di un messaggio esistente.', 'values' => []];
            }
            $values['reply_to_id'] = $data['reply_to_id'];
        }
    }

    if (array_key_exists('recipient', $data)) {
        $recipient = apiNormalizeBoardActor((string) $data['recipient']);
        if ($recipient === null) {
            return ['error' => 'Il campo "recipient" non è valido: usa un\'etichetta come "claude", "grok", "manus", "direttore" oppure "all".', 'values' => []];
        }
        $values['recipient'] = $recipient;
    }

    if (array_key_exists('status', $data)) {
        $status = (string) $data['status'];
        if (!in_array($status, BOARD_STATUSES, true)) {
            return ['error' => 'Il campo "status" deve essere uno tra ' . implode(', ', BOARD_STATUSES) . '.', 'values' => []];
        }
        $values['status'] = $status;
    }

    if (array_key_exists('body', $data)) {
        $body = trim((string) $data['body']);
        if ($body === '') {
            return ['error' => 'Il campo "body" non può essere vuoto.', 'values' => []];
        }
        if (mb_strlen($body) > BOARD_BODY_MAX_CHARS) {
            return ['error' => 'Il campo "body" supera i ' . BOARD_BODY_MAX_CHARS . ' caratteri consentiti.', 'values' => []];
        }
        $values['body'] = $body;
    } elseif (!$partial) {
        return ['error' => 'Il campo "body" è obbligatorio.', 'values' => []];
    }

    $hasRefType = array_key_exists('ref_type', $data) && $data['ref_type'] !== null;
    $hasRefId = array_key_exists('ref_id', $data) && $data['ref_id'] !== null;
    if ($hasRefType || $hasRefId) {
        if (!$hasRefType || !$hasRefId) {
            return ['error' => '"ref_type" e "ref_id" vanno indicati insieme.', 'values' => []];
        }
        if (!in_array($data['ref_type'], BOARD_REF_TYPES, true)) {
            return ['error' => 'Il campo "ref_type" deve essere uno tra ' . implode(', ', BOARD_REF_TYPES) . '.', 'values' => []];
        }
        if (!is_int($data['ref_id']) || $data['ref_id'] <= 0) {
            return ['error' => 'Il campo "ref_id" deve essere l\'ID numerico del contenuto collegato.', 'values' => []];
        }
        $values['ref_type'] = $data['ref_type'];
        $values['ref_id'] = $data['ref_id'];
    }

    return ['error' => null, 'values' => $values];
}

// Verifica che il contenuto collegato a un messaggio (ref_type/ref_id) esista e appartenga al
// profilo: la tabella viene scelta da una mappa fissa, mai dal valore ricevuto.
function apiBoardRefExists(int $userId, string $refType, int $refId): bool {
    $tables = ['blog_post' => 'blog_posts', 'social_post' => 'timeline_posts', 'event' => 'events'];
    if (!isset($tables[$refType])) {
        return false;
    }
    $stmt = getDB()->prepare('SELECT 1 FROM ' . $tables[$refType] . ' WHERE id = ? AND user_id = ?');
    $stmt->execute([$refId, $userId]);
    return (bool) $stmt->fetchColumn();
}

// Etichetta riservata al direttore: solo chi firma con questa identità può approvare un messaggio
// (status "approved") — vedi apiVerifyBoardSignature() e i controlli in api_board_create.php /
// api_board_item.php.
const BOARD_DIRECTOR_ACTOR = 'direttore';

// Verifica la firma di un attore della bacheca (author su POST, "as" su PUT) contro il segreto
// assegnato a quell'etichetta in board_actor_keys (dashboard_api_tokens.php). Senza questo
// controllo, chiunque avesse il token API del profilo potrebbe scriversi "author": "direttore" e
// autoapprovarsi: il token identifica solo il profilo, non quale AI o persona lo sta usando in
// quel momento — la firma è il secondo fattore che lega il messaggio a un'identità specifica.
function apiVerifyBoardSignature(int $userId, string $actor, string $signature): bool {
    if ($signature === '') {
        return false;
    }
    $stmt = getDB()->prepare('SELECT secret_hash FROM board_actor_keys WHERE user_id = ? AND actor = ?');
    $stmt->execute([$userId, $actor]);
    $hash = $stmt->fetchColumn();
    return $hash !== false && hash_equals($hash, hash('sha256', $signature));
}

// Genera una nuova firma in chiaro per un attore della bacheca (mostrata una sola volta a chi la
// crea) insieme al suo hash da salvare — stesso pattern di generateApiToken().
function generateBoardActorSignature(): array {
    $signature = bin2hex(random_bytes(20)); // 40 caratteri esadecimali
    return ['signature' => $signature, 'hash' => hash('sha256', $signature)];
}
