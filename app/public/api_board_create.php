<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();
$data = apiReadJsonBody();

$validated = apiValidateBoardPayload($data, false);
if ($validated['error'] !== null) {
    apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
}
$v = $validated['values'];

// Ogni messaggio va firmato da chi lo scrive (author): il token API identifica solo il profilo,
// non quale AI o persona lo sta usando in quel momento — vedi apiVerifyBoardSignature().
$signature = (string) ($data['signature'] ?? '');
if (!apiVerifyBoardSignature($auth['user_id'], $v['author'], $signature)) {
    apiError(403, 'Firma mancante o non valida per l\'autore "' . $v['author'] . '". Ogni messaggio in bacheca va firmato con il segreto assegnato a chi lo scrive (Dashboard → API).', $auth['token_id'], $auth['user_id']);
}

// Lo stato "approved" è un permesso, non una semplice etichetta: solo chi firma come direttore
// può assegnarlo, altrimenti l'approvazione si aggirerebbe scrivendosi da soli "approved" in fase
// di creazione del messaggio.
if (($v['status'] ?? 'open') === 'approved' && $v['author'] !== BOARD_DIRECTOR_ACTOR) {
    apiError(403, 'Solo "' . BOARD_DIRECTOR_ACTOR . '" può impostare lo stato "approved".', $auth['token_id'], $auth['user_id']);
}

// Un messaggio-risposta eredita il thread del messaggio a cui risponde (che deve esistere ed
// essere di questo profilo); un messaggio nuovo apre un thread proprio, dopo l'INSERT.
$threadId = null;
if (isset($v['reply_to_id'])) {
    $stmt = getDB()->prepare('SELECT id, thread_id FROM board_messages WHERE id = ? AND user_id = ?');
    $stmt->execute([$v['reply_to_id'], $auth['user_id']]);
    $parent = $stmt->fetch();
    if (!$parent) {
        apiError(422, '"reply_to_id" non corrisponde a nessun messaggio di questo profilo.', $auth['token_id'], $auth['user_id']);
    }
    $threadId = $parent['thread_id'] !== null ? (int) $parent['thread_id'] : (int) $parent['id'];
}

if (isset($v['ref_type']) && !apiBoardRefExists($auth['user_id'], $v['ref_type'], $v['ref_id'])) {
    apiError(422, 'Il contenuto indicato in "ref_type"/"ref_id" non esiste in questo profilo.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('INSERT INTO board_messages (user_id, thread_id, reply_to_id, author, recipient, message_type, status, body, ref_type, ref_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([
    $auth['user_id'],
    $threadId,
    $v['reply_to_id'] ?? null,
    $v['author'],
    $v['recipient'] ?? 'all',
    $v['message_type'],
    $v['status'] ?? 'open',
    $v['body'],
    $v['ref_type'] ?? null,
    $v['ref_id'] ?? null,
]);
$messageId = (int) getDB()->lastInsertId();

if ($threadId === null) {
    getDB()->prepare('UPDATE board_messages SET thread_id = ? WHERE id = ?')->execute([$messageId, $messageId]);
}

$stmt = getDB()->prepare('SELECT * FROM board_messages WHERE id = ?');
$stmt->execute([$messageId]);

apiRespond(201, [
    'success' => true,
    'message' => 'Messaggio pubblicato in bacheca.',
    'data' => apiSerializeBoardMessage($stmt->fetch()),
], $auth['token_id'], $auth['user_id']);
