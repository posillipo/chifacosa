<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

// respectVisibility=false: chi gestisce i pin deve vederli ed eventualmente toglierli anche se
// puntano a un elemento non ancora pubblico/programmato — stesso comportamento di
// dashboard_featured.php.
$items = getPinnedItemsForUser($auth['user_id'], false);

apiRespond(200, [
    'success' => true,
    'data' => array_map('apiSerializePinnedItem', $items),
], $auth['token_id'], $auth['user_id']);
