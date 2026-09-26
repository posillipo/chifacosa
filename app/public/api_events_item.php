<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

$auth = authenticateApiRequest();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    apiError(400, 'ID evento mancante o non valido.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('SELECT * FROM events WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $auth['user_id']]);
$event = $stmt->fetch();
if (!$event) {
    apiError(404, 'Evento non trovato.', $auth['token_id'], $auth['user_id']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    apiRespond(200, ['success' => true, 'data' => apiSerializeEvent($event, $auth['slug'])], $auth['token_id'], $auth['user_id']);
}

if ($method === 'PUT') {
    $data = apiReadJsonBody();
    $validated = apiValidateEventPayload($data, true);
    if ($validated['error'] !== null) {
        apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
    }
    $v = $validated['values'];

    if (array_key_exists('image_url', $v)) {
        $downloaded = downloadImageFromUrlAsCover($v['image_url'], $auth['slug']);
        if ($downloaded === null) {
            apiError(422, 'Non è stato possibile scaricare la nuova "image_url".', $auth['token_id'], $auth['user_id']);
        }
        deleteCoverFile($event['cover_path'] ?? null);
        $v['cover_path'] = $downloaded['image_path'];
        unset($v['image_url']);
    }

    $columnMap = ['title', 'venue', 'city', 'provincia', 'event_date', 'ticket_url', 'description', 'is_perpetual', 'recurrence', 'accepts_reservations', 'cover_path'];
    $sets = [];
    $params = [];
    foreach ($columnMap as $col) {
        if (array_key_exists($col, $v)) {
            $sets[] = "{$col} = ?";
            $params[] = $v[$col];
        }
    }
    if ($sets) {
        $params[] = $id;
        getDB()->prepare('UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    $stmt = getDB()->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();
    apiRespond(200, ['success' => true, 'data' => apiSerializeEvent($updated, $auth['slug'])], $auth['token_id'], $auth['user_id']);
}

if ($method === 'DELETE') {
    deleteCoverFile($event['cover_path'] ?? null);
    getDB()->prepare('DELETE FROM events WHERE id = ? AND user_id = ?')->execute([$id, $auth['user_id']]);
    apiRespond(200, ['success' => true, 'message' => 'Evento eliminato.'], $auth['token_id'], $auth['user_id']);
}

apiError(405, 'Metodo non permesso, usa GET, PUT o DELETE.', $auth['token_id'], $auth['user_id']);
