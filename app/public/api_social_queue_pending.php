<?php
/**
 * GET /api/v1/social-queue/pending
 * Returns up to 10 pending social queue items for the authenticated profile,
 * including metricool settings, and marks them as 'processing'.
 */
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

$stmt = getDB()->prepare(
    'SELECT q.*, p.metricool_blog_id, p.metricool_networks, p.display_name
     FROM social_queue q
     JOIN profiles p ON p.user_id = q.user_id
     WHERE q.user_id = ? AND q.status = "pending"
     ORDER BY q.created_at ASC
     LIMIT 10'
);
$stmt->execute([$auth['user_id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    getDB()->exec("UPDATE social_queue SET status='processing' WHERE id IN ($ids)");
}

apiRespond(200, ['success' => true, 'items' => $rows], $auth['token_id'], $auth['user_id']);
