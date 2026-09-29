<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    apiError(400, 'Parametro "q" mancante: cosa cercare tra i contenuti del profilo.', $auth['token_id'], $auth['user_id']);
}

// Nessun filtro di visibilità: il proprietario deve poter trovare e fissare anche un contenuto
// "Solo io" o ancora programmato — il carosello pubblico lo mostrerà comunque solo quando diventa
// visibile (vedi list_pinned_items/getPinnedItemsForUser()).
$results = searchPinnableContent($auth['user_id'], $q);

apiRespond(200, [
    'success' => true,
    'data' => array_map('apiSerializePinnableSearchResult', $results),
], $auth['token_id'], $auth['user_id']);
