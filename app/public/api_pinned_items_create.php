<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();
$data = apiReadJsonBody();

$type = (string) ($data['content_type'] ?? '');
$contentId = (int) ($data['content_id'] ?? 0);

if ($type === '' || $contentId <= 0) {
    apiError(422, 'I campi "content_type" e "content_id" sono obbligatori (usa search_pinnable_content/GET /pinned-items/search per trovarli).', $auth['token_id'], $auth['user_id']);
}
$cfg = PINNABLE_CONTENT_TYPES[$type] ?? null;
if ($cfg === null) {
    apiError(422, 'content_type non valido. Valori ammessi: ' . implode(', ', array_keys(PINNABLE_CONTENT_TYPES)) . '.', $auth['token_id'], $auth['user_id']);
}

// $cfg['table'] viene da una whitelist fissa in codice (PINNABLE_CONTENT_TYPES), non da input
// utente: nessun rischio di SQL injection nell'interpolazione qui sotto.
$stmt = getDB()->prepare("SELECT id FROM {$cfg['table']} WHERE id = ? AND user_id = ?");
$stmt->execute([$contentId, $auth['user_id']]);
if (!$stmt->fetch()) {
    apiError(404, 'Nessun contenuto di tipo "' . $type . '" con questo ID trovato su questo profilo.', $auth['token_id'], $auth['user_id']);
}

// INSERT IGNORE lato pinContentItem(): se era già fissato, non succede nulla (nessun errore, si
// restituisce comunque lo stato attuale del pin).
pinContentItem($auth['user_id'], $type, $contentId);

$pinned = getPinnedItemsForUser($auth['user_id'], false);
$match = null;
foreach ($pinned as $item) {
    if ($item['tipo'] === $type && $item['id'] === $contentId) {
        $match = $item;
        break;
    }
}
if ($match === null) {
    // Non dovrebbe succedere (appena inserito): messaggio esplicito piuttosto che un 500 silenzioso.
    apiError(500, 'Il pin è stato creato ma non è stato possibile rileggerlo.', $auth['token_id'], $auth['user_id']);
}

apiRespond(201, [
    'success' => true,
    'message' => 'Elemento fissato in Primo Piano.',
    'data' => apiSerializePinnedItem($match),
], $auth['token_id'], $auth['user_id']);
