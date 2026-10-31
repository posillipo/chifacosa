<?php
/**
 * POST /api/v1/social-queue/{id}
 * Marks a social queue item as done, scheduled, failed or skipped.
 * Body JSON: { status: "done"|"scheduled"|"failed"|"skipped", error_msg?: string,
 *              channels?: string[], scheduler_ref?: string }
 * channels / scheduler_ref (registro): canali effettivi e riferimento dello scheduler (es. id del
 * post su Metricool). Se omessi non vengono toccati.
 */
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/metricool_queue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    apiError(400, 'ID non valido.', $auth['token_id'], $auth['user_id']);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    apiError(400, 'Body JSON non valido.', $auth['token_id'], $auth['user_id']);
}

$status = $body['status'] ?? '';
if (!in_array($status, SOCIAL_REGISTRY_FINAL_STATUSES, true)) {
    apiError(422, 'Il campo "status" deve essere uno tra: ' . implode(', ', SOCIAL_REGISTRY_FINAL_STATUSES) . '.', $auth['token_id'], $auth['user_id']);
}
$errMsg = isset($body['error_msg']) ? (string) $body['error_msg'] : null;

$validated = socialRegistryValidate(array_intersect_key($body, ['channels' => 1, 'scheduler_ref' => 1]));
if ($validated['error'] !== null) {
    apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
}
$extra = $validated['values'];

$sets = ['status=?', 'error_msg=?', 'processed_at=NOW()'];
$params = [$status, $errMsg];
foreach ($extra as $col => $val) {
    $sets[] = "$col=?";
    $params[] = $val;
}
$params[] = $id;
$params[] = $auth['user_id'];

try {
    $stmt = getDB()->prepare('UPDATE social_queue SET ' . implode(', ', $sets) . ' WHERE id=? AND user_id=?');
    $stmt->execute($params);
} catch (PDOException $e) {
    if ($extra && $e->getCode() === '42S22') {
        apiError(503, 'Il registro non è ancora attivo su questo database: applicare la migrazione 2026_10_31_social_queue_registry.sql.', $auth['token_id'], $auth['user_id']);
    }
    throw $e;
}

if ($stmt->rowCount() === 0) {
    apiError(404, 'Elemento non trovato o non appartiene a questo profilo.', $auth['token_id'], $auth['user_id']);
}

apiRespond(200, ['success' => true], $auth['token_id'], $auth['user_id']);
