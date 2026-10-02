<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$where = ['user_id = ?'];
$params = [$auth['user_id']];

$visibility = trim((string) ($_GET['visibility'] ?? ''));
if ($visibility === 'public') {
    $where[] = 'is_public = 1';
} elseif ($visibility === 'private') {
    $where[] = 'is_public = 0';
}

$whereSql = implode(' AND ', $where);

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM photo_albums WHERE {$whereSql}");
$stmt->execute($params);
$total = (int) $stmt->fetch()['c'];

$stmt = getDB()->prepare("SELECT * FROM photo_albums WHERE {$whereSql} ORDER BY sort_order DESC, created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

apiRespond(200, [
    'success' => true,
    'data' => array_map(fn($a) => apiSerializeAlbum($a, $auth['slug']), $rows),
    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
], $auth['token_id'], $auth['user_id']);
