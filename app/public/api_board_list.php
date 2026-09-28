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

// "recipient" include sempre anche i messaggi rivolti a tutti ("all"): chi fa il suo giro in
// bacheca vuole vedere sia quelli per lui sia quelli per tutti.
if (($_GET['recipient'] ?? '') !== '') {
    $recipient = apiNormalizeBoardActor((string) $_GET['recipient']);
    if ($recipient === null) {
        apiError(422, 'Il filtro "recipient" non è valido.', $auth['token_id'], $auth['user_id']);
    }
    $where[] = '(recipient = ? OR recipient = \'all\')';
    $params[] = $recipient;
}
if (($_GET['author'] ?? '') !== '') {
    $author = apiNormalizeBoardActor((string) $_GET['author']);
    if ($author === null) {
        apiError(422, 'Il filtro "author" non è valido.', $auth['token_id'], $auth['user_id']);
    }
    $where[] = 'author = ?';
    $params[] = $author;
}
if (($_GET['status'] ?? '') !== '') {
    $statuses = array_filter(array_map('trim', explode(',', (string) $_GET['status'])));
    foreach ($statuses as $s) {
        if (!in_array($s, BOARD_STATUSES, true)) {
            apiError(422, 'Il filtro "status" accetta solo: ' . implode(', ', BOARD_STATUSES) . ' (anche più valori separati da virgola).', $auth['token_id'], $auth['user_id']);
        }
    }
    if ($statuses) {
        $where[] = 'status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        array_push($params, ...$statuses);
    }
}
if (($_GET['type'] ?? '') !== '') {
    if (!in_array($_GET['type'], BOARD_MESSAGE_TYPES, true)) {
        apiError(422, 'Il filtro "type" deve essere uno tra ' . implode(', ', BOARD_MESSAGE_TYPES) . '.', $auth['token_id'], $auth['user_id']);
    }
    $where[] = 'message_type = ?';
    $params[] = $_GET['type'];
}
if (($_GET['thread_id'] ?? '') !== '') {
    $where[] = 'thread_id = ?';
    $params[] = (int) $_GET['thread_id'];
}
// since_id: solo i messaggi più recenti di un ID già visto (utile ai giri periodici).
if (($_GET['since_id'] ?? '') !== '') {
    $where[] = 'id > ?';
    $params[] = (int) $_GET['since_id'];
}
$whereSql = implode(' AND ', $where);

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM board_messages WHERE {$whereSql}");
$stmt->execute($params);
$total = (int) $stmt->fetch()['c'];

// Dal più vecchio al più recente: chi legge lavora in ordine di arrivo, e un thread si legge
// come una conversazione.
$stmt = getDB()->prepare("SELECT * FROM board_messages WHERE {$whereSql} ORDER BY id ASC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);

apiRespond(200, [
    'success' => true,
    'data' => array_map('apiSerializeBoardMessage', $stmt->fetchAll()),
    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
], $auth['token_id'], $auth['user_id']);
