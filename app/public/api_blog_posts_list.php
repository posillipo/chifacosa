<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, ['scheduled', 'published'], true)) {
    apiError(422, 'Il parametro "status" deve essere uno tra scheduled, published.', $auth['token_id'], $auth['user_id']);
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

// blog_posts non ha una colonna created_at separata (solo published_at, usata sia per ordinare
// che per la visibilità pubblica) — a differenza di timeline_posts, il filtro from/to qui si
// applica direttamente a published_at.
$where = ['user_id = ?'];
$params = [$auth['user_id']];
if ($from !== '') {
    $where[] = 'published_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'published_at <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$stmt = getDB()->prepare("SELECT * FROM blog_posts WHERE {$whereSql} ORDER BY published_at DESC");
$stmt->execute($params);
$allRows = $stmt->fetchAll();

if ($statusFilter !== '') {
    $allRows = array_values(array_filter($allRows, fn($p) => apiDeriveBlogPostStatus($p) === $statusFilter));
}

$total = count($allRows);
$pageRows = array_slice($allRows, $offset, $perPage);

apiRespond(200, [
    'success' => true,
    'data' => array_map(fn($p) => apiSerializeBlogPost($p, $auth['slug']), $pageRows),
    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
], $auth['token_id'], $auth['user_id']);
