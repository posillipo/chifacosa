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
 *   scheduled_for string|null  "YYYY-MM-DD HH:MM" or "YYYY-MM-DDTHH:MM"
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
$scheduledFor = $body['scheduled_for'] ?? null;

if (!$contentType || !$contentId) {
    apiError(400, 'content_type e content_id sono obbligatori.');
}

// Parse and normalize scheduled_for
if ($scheduledFor) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduledFor)
       ?: DateTime::createFromFormat('Y-m-d H:i', $scheduledFor)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $scheduledFor)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $scheduledFor);
    $scheduledFor = $dt ? $dt->format('Y-m-d H:i:s') : null;
}

$userId = (int) $auth['user_id'];

// Deduplication: if same content already pending/processing/suspended, just update scheduled_for
$dup = getDB()->prepare(
    'SELECT id FROM social_queue WHERE user_id=? AND content_type=? AND content_id=? AND status IN ("pending","processing","suspended") LIMIT 1'
);
$dup->execute([$userId, $contentType, $contentId]);
if ($existing = $dup->fetch()) {
    if ($scheduledFor) {
        getDB()->prepare('UPDATE social_queue SET scheduled_for=? WHERE id=?')->execute([$scheduledFor, $existing['id']]);
    }
    apiRespond(200, ['success' => true, 'id' => $existing['id'], 'updated' => true], $auth['token_id'], $userId);
    exit;
}

$id = socialQueueEnqueue($userId, $contentType, [
    'content_id' => $contentId,
    'title'      => $title,
    'image_url'  => $imageUrl,
    'public_url' => $publicUrl,
    'media_url'  => $mediaUrl,
], $scheduledFor);

apiRespond(201, ['success' => true, 'id' => $id], $auth['token_id'], $userId);
