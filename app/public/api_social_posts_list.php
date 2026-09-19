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
$whereSql = implode(' AND ', $where);

$stmt = getDB()->prepare("SELECT * FROM timeline_posts WHERE {$whereSql} ORDER BY created_at DESC");
$stmt->execute($params);
$allRows = $stmt->fetchAll();

// Il filtro per status è calcolato in PHP (deriva da visibility+publish_at, non una singola
// colonna indicizzabile) — accettabile: uno stesso token pubblica al massimo poche centinaia di
// post (vedi nota "500+ post/mese" della specifica), niente paginazione lato SQL necessaria qui.
if ($statusFilter !== '') {
    $allRows = array_values(array_filter($allRows, fn($p) => apiDerivePostStatus($p) === $statusFilter));
}

$total = count($allRows);
$pageRows = array_slice($allRows, $offset, $perPage);

apiRespond(200, [
    'success' => true,
    'data' => array_map(fn($p) => apiSerializePost($p, $auth['slug']), $pageRows),
    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
], $auth['token_id'], $auth['user_id']);
