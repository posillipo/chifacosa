<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

// from/to filtrano su event_date (quando si terrà l'evento), non su una data di creazione — non
// esiste un equivalente di "publish_at" per gli eventi, sono sempre visibili subito.
$where = ['user_id = ?'];
$params = [$auth['user_id']];
if ($from !== '') {
    $where[] = 'event_date >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'event_date <= ?';
    $params[] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM events WHERE {$whereSql}");
$stmt->execute($params);
$total = (int) $stmt->fetch()['c'];

// Stesso ordinamento della dashboard (dashboard_events.php): i perpetui prima, poi per data.
$stmt = getDB()->prepare("SELECT * FROM events WHERE {$whereSql} ORDER BY is_perpetual DESC, event_date ASC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

apiRespond(200, [
    'success' => true,
    'data' => array_map(fn($e) => apiSerializeEvent($e, $auth['slug']), $rows),
    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
], $auth['token_id'], $auth['user_id']);
