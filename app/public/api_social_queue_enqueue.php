<?php
/**
 * POST /api/v1/social-queue/enqueue
 * Adds an item to the social queue for the authenticated profile.
 *
 * Body (JSON): {
 *   content_type  string   e.g. "blog", "fan_band", "event", "service", "offer", "album", ...
 *   content_id    int
 *   title         string
 *   image_url     string|null
 *   public_url    string
 *   media_url     string|null  file video/audio da pubblicare (es. content_type "video")
 *   scheduled_for string|null  "YYYY-MM-DD HH:MM" or "YYYY-MM-DDTHH:MM" (alias: publication_date)
 *
 *   Campi del registro (tutti opzionali):
 *   scope           announcement|reminder|result|new|promo|release|reprise
 *   format          feed|story|reel
 *   image_story_url string|null  immagine in formato storia
 *   channels        string[]     es. ["FB","IG","Threads"]
 *   scheduler_ref   string|null  riferimento esterno opaco dello scheduler
 *   status          pending (default, coda da lavorare) | scheduled | done | failed | skipped
 *                   — con uno stato diverso da pending l'uscita è REGISTRATA a cose fatte e la
 *                   routine non la prende in carico.
 *
 *   Con "scope" ogni (profilo, content_type, content_id, scope) è unico: un secondo invio
 *   risponde 409 con la riga già esistente, senza crearne un'altra.
 * }
 */
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/metricool_queue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    apiError(400, 'Payload JSON mancante o non valido.');
}

$contentType = trim($body['content_type'] ?? '');
$contentId   = (int) ($body['content_id'] ?? 0);
$title       = mb_substr(trim($body['title'] ?? ''), 0, 250);
$imageUrl    = $body['image_url'] ?? null;
$publicUrl   = trim($body['public_url'] ?? '');
$mediaUrl    = trim($body['media_url'] ?? '');
$scheduledFor = $body['scheduled_for'] ?? ($body['publication_date'] ?? null);

if (!$contentType || !$contentId) {
    apiError(400, 'content_type e content_id sono obbligatori.');
}

// Parse and normalize scheduled_for
if ($scheduledFor) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduledFor)
       ?: DateTime::createFromFormat('Y-m-d H:i', $scheduledFor)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $scheduledFor)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $scheduledFor);
    if (!$dt) {
        // ISO 8601 con fuso orario (es. 2026-10-31T09:00:00+01:00): convertito nel fuso del server.
        try {
            $dt = new DateTime((string) $scheduledFor);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (Exception $e) {
            $dt = null;
        }
    }
    $scheduledFor = $dt ? $dt->format('Y-m-d H:i:s') : null;
}

$validated = socialRegistryValidate($body);
if ($validated['error'] !== null) {
    apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
}
$registry = $validated['values'];

$status = $body['status'] ?? 'pending';
if ($status !== 'pending' && !in_array($status, SOCIAL_REGISTRY_FINAL_STATUSES, true)) {
    apiError(422, 'status deve essere uno tra: pending, ' . implode(', ', SOCIAL_REGISTRY_FINAL_STATUSES) . '.', $auth['token_id'], $auth['user_id']);
}

$userId = (int) $auth['user_id'];

// Registro: la stessa uscita (profilo + elemento + scope) esiste una sola volta. Non si crea una
// seconda riga né si sovrascrive quella esistente: si risponde 409 con lo stato attuale, così chi
// chiama sa che quell'uscita è già stata registrata (o programmata) e non la pubblica due volte.
if (isset($registry['scope'])) {
    try {
        $ex = getDB()->prepare('SELECT * FROM social_queue WHERE user_id=? AND content_type=? AND content_id=? AND scope=? LIMIT 1');
        $ex->execute([$userId, $contentType, $contentId, $registry['scope']]);
        $row = $ex->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        apiError(503, 'Il registro non è ancora attivo su questo database: applicare la migrazione 2026_10_31_social_queue_registry.sql.', $auth['token_id'], $userId);
    }
    if ($row) {
        apiRespond(409, [
            'success'  => false,
            'error'    => 'Uscita già registrata per questo elemento e questo scope.',
            'existing' => socialRegistrySerialize($row),
        ], $auth['token_id'], $userId);
        exit;
    }
}

// Deduplication: if same content already pending/processing/suspended, just update scheduled_for
$dup = getDB()->prepare(
    'SELECT id FROM social_queue WHERE user_id=? AND content_type=? AND content_id=? AND status IN ("pending","processing","suspended") LIMIT 1'
);
$dup->execute([$userId, $contentType, $contentId]);
// La dedup della coda vale solo per le voci da lavorare: registrare un'uscita già fatta
// (status diverso da pending) non deve essere assorbito da una voce in coda dello stesso elemento.
if ($status === 'pending' && !isset($registry['scope']) && ($existing = $dup->fetch())) {
    if ($scheduledFor) {
        getDB()->prepare('UPDATE social_queue SET scheduled_for=? WHERE id=?')->execute([$scheduledFor, $existing['id']]);
    }
    apiRespond(200, ['success' => true, 'id' => $existing['id'], 'updated' => true], $auth['token_id'], $userId);
    exit;
}

try {
    $id = socialQueueEnqueue($userId, $contentType, [
        'content_id' => $contentId,
        'title'      => $title,
        'image_url'  => $imageUrl,
        'public_url' => $publicUrl,
        'media_url'  => $mediaUrl,
    ], $scheduledFor, $registry, $status);
} catch (PDOException $e) {
    // 23000 = violazione del vincolo di unicità: due richieste uguali in contemporanea.
    if ($e->getCode() === '23000') {
        apiError(409, 'Uscita già registrata per questo elemento e questo scope.', $auth['token_id'], $userId);
    }
    // Colonne del registro assenti: la migrazione 2026_10_31 non è ancora stata applicata.
    if ($registry && $e->getCode() === '42S22') {
        apiError(503, 'Il registro non è ancora attivo su questo database: applicare la migrazione 2026_10_31_social_queue_registry.sql.', $auth['token_id'], $userId);
    }
    throw $e;
}

apiRespond(201, ['success' => true, 'id' => $id], $auth['token_id'], $userId);
