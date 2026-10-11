<?php
/**
 * POST /api/v1/social-queue/{id}
 * Marks a social queue item as done or failed.
 * Body JSON: { status: "done"|"failed", error_msg?: string }
 */
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

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
if (!in_array($status, ['done', 'failed'], true)) {
    apiError(422, 'Il campo "status" deve essere "done" o "failed".', $auth['token_id'], $auth['user_id']);
}
$errMsg = isset($body['error_msg']) ? (string) $body['error_msg'] : null;

$stmt = getDB()->prepare('UPDATE social_queue SET status=?, error_msg=?, processed_at=NOW() WHERE id=? AND user_id=?');
$stmt->execute([$status, $errMsg, $id, $auth['user_id']]);

if ($stmt->rowCount() === 0) {
    apiError(404, 'Elemento non trovato o non appartiene a questo profilo.', $auth['token_id'], $auth['user_id']);
}

apiRespond(200, ['success' => true], $auth['token_id'], $auth['user_id']);
