<?php
require_once __DIR__ . '/../src/functions.php';

header('Content-Type: application/json; charset=UTF-8');

$slug = $_GET['slug'] ?? '';
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$pageSize = 20;

$stmt = getDB()->prepare('SELECT id FROM users WHERE slug = ? AND is_active = 1');
$stmt->execute([$slug]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['html' => '', 'count' => 0]);
    exit;
}

$items = getTimelineFeedForUsers([$user['id']], $pageSize, $offset);

$html = '';
foreach ($items as $item) {
    $html .= renderTimelineFeedItem($item);
}

echo json_encode(['html' => $html, 'count' => count($items)]);
