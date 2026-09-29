<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

$auth = authenticateApiRequest();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    apiError(400, 'ID pin mancante o non valido.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('SELECT id FROM pinned_items WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $auth['user_id']]);
if (!$stmt->fetch()) {
    apiError(404, 'Pin non trovato.', $auth['token_id'], $auth['user_id']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'PUT') {
    // Stesso riordino a tasti già offerto da dashboard_featured.php: scambia la posizione col pin
    // immediatamente sopra/sotto, nessun indice/posizione assoluta da passare.
    $data = apiReadJsonBody();
    $direction = $data['direction'] ?? '';
    if ($direction !== 'up' && $direction !== 'down') {
        apiError(422, 'Campo "direction" mancante o non valido: usa "up" o "down".', $auth['token_id'], $auth['user_id']);
    }
    movePinnedItem($auth['user_id'], $id, $direction);

    $pinned = getPinnedItemsForUser($auth['user_id'], false);
    apiRespond(200, [
        'success' => true,
        'message' => 'Ordine aggiornato.',
        'data' => array_map('apiSerializePinnedItem', $pinned),
    ], $auth['token_id'], $auth['user_id']);
}

if ($method === 'DELETE') {
    unpinContentItem($auth['user_id'], $id);
    apiRespond(200, ['success' => true, 'message' => 'Pin rimosso da Primo Piano.'], $auth['token_id'], $auth['user_id']);
}

apiError(405, 'Metodo non permesso, usa PUT o DELETE.', $auth['token_id'], $auth['user_id']);
