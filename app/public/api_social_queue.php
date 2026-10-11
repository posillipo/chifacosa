<?php
/**
 * API for the Claude Routine that processes the social_queue.
 *
 * GET  ?secret=X          → returns pending items (max 10) with profile metricool info
 * POST ?secret=X          → body JSON: {id, status, error_msg?}  → marks item done/failed
 */
require_once __DIR__ . '/../src/functions.php';

$secret = getSiteSetting('social_queue_secret') ?: '';
$provided = $_GET['secret'] ?? ($_SERVER['HTTP_X_QUEUE_SECRET'] ?? '');
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = getDB()->prepare(
        'SELECT q.*, p.metricool_blog_id, p.metricool_networks, p.display_name, u.slug
         FROM social_queue q
         JOIN profiles p ON p.user_id = q.user_id
         JOIN users u ON u.id = q.user_id
         WHERE q.status = "pending"
         ORDER BY q.created_at ASC
         LIMIT 10'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark fetched items as "processing" to prevent duplicate processing
    if ($rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'id')));
        getDB()->exec("UPDATE social_queue SET status='processing' WHERE id IN ($ids)");
    }

    echo json_encode(['items' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || !isset($body['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        exit;
    }
    $id     = (int) $body['id'];
    $status = in_array($body['status'] ?? '', ['done', 'failed']) ? $body['status'] : 'failed';
    $errMsg = $body['error_msg'] ?? null;

    $stmt = getDB()->prepare('UPDATE social_queue SET status=?, error_msg=?, processed_at=NOW() WHERE id=?');
    $stmt->execute([$status, $errMsg, $id]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
