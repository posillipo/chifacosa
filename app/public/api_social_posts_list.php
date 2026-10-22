<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, ['draft', 'scheduled', 'published'], true)) {
    apiError(422, 'Il parametro "status" deve essere uno tra draft, scheduled, published.', $auth['token_id'], $auth['user_id']);
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$where = ['user_id = ?'];
$params = [$auth['user_id']];
if ($from !== '') {
    $where[] = 'created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
if ($statusFilter === 'draft') {
    $where[] = "visibility = 'private'";
} elseif ($statusFilter === 'published') {
    $where[] = "visibility = 'public' AND (publish_at IS NULL OR publish_at <= NOW())";
} elseif ($statusFilter === 'scheduled') {
    $where[] = "visibility = 'public' AND publish_at > NOW()";
}
$whereSql = implode(' AND ', $where);

$countStmt = getDB()->prepare("SELECT COUNT(*) FROM timeline_posts WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$pageParams = array_merge($params, [$perPage, $offset]);
$stmt = getDB()->prepare("SELECT * FROM timeline_posts WHERE {$whereSql} ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($pageParams);
$pageRows = $stmt->fetchAll();

apiRespond(200, [
    'success' => true,
    'data' => array_map(fn($p) => apiSerializePost($p, $auth['slug']), $pageRows),
    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
], $auth['token_id'], $auth['user_id']);
