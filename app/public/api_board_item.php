<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

$auth = authenticateApiRequest();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    apiError(400, 'ID messaggio mancante o non valido.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('SELECT * FROM board_messages WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $auth['user_id']]);
$message = $stmt->fetch();
if (!$message) {
    apiError(404, 'Messaggio non trovato.', $auth['token_id'], $auth['user_id']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    apiRespond(200, ['success' => true, 'data' => apiSerializeBoardMessage($message)], $auth['token_id'], $auth['user_id']);
}

// Nessun DELETE: la bacheca è un registro. Si chiude un messaggio (status "closed"), non si cancella.
if ($method === 'PUT') {
    $data = apiReadJsonBody();

    // if_status: aggiornamento condizionato ("compare-and-set"). Serve a prendere in carico un
    // brief senza pestarsi i piedi: se due AI provano a passare da "open" a "in_progress" nello
    // stesso momento, una sola ci riesce e l'altra riceve 409.
    $ifStatus = null;
    if (array_key_exists('if_status', $data)) {
        $ifStatus = (string) $data['if_status'];
        if (!in_array($ifStatus, BOARD_STATUSES, true)) {
            apiError(422, 'Il campo "if_status" deve essere uno tra ' . implode(', ', BOARD_STATUSES) . '.', $auth['token_id'], $auth['user_id']);
        }
        unset($data['if_status']);
    }

    $validated = apiValidateBoardPayload($data, true);
    if ($validated['error'] !== null) {
        apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
    }
    $v = $validated['values'];

    if (isset($v['ref_type']) && !apiBoardRefExists($auth['user_id'], $v['ref_type'], $v['ref_id'])) {
        apiError(422, 'Il contenuto indicato in "ref_type"/"ref_id" non esiste in questo profilo.', $auth['token_id'], $auth['user_id']);
    }

    $columnMap = ['recipient', 'status', 'body', 'ref_type', 'ref_id'];
    $sets = [];
    $params = [];
    foreach ($columnMap as $col) {
        if (array_key_exists($col, $v)) {
            $sets[] = "{$col} = ?";
            $params[] = $v[$col];
        }
    }
    if (!$sets) {
        apiError(422, 'Nessun campo da aggiornare: indica almeno uno tra status, recipient, body, ref_type/ref_id.', $auth['token_id'], $auth['user_id']);
    }

    // Transazione con blocco della riga: il controllo di "if_status" e l'UPDATE devono essere
    // un'operazione sola, altrimenti due richieste simultanee passerebbero entrambe il controllo.
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status FROM board_messages WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$id, $auth['user_id']]);
        $currentStatus = $stmt->fetchColumn();
        if ($currentStatus === false) {
            $pdo->rollBack();
            apiError(404, 'Messaggio non trovato.', $auth['token_id'], $auth['user_id']);
        }
        if ($ifStatus !== null && $currentStatus !== $ifStatus) {
            $pdo->rollBack();
            apiError(409, 'Lo stato del messaggio non è più "' . $ifStatus . '" (ora è "' . $currentStatus . '"): qualcun altro l\'ha già cambiato.', $auth['token_id'], $auth['user_id']);
        }
        $params[] = $id;
        $pdo->prepare('UPDATE board_messages SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stmt = $pdo->prepare('SELECT * FROM board_messages WHERE id = ?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();
    apiRespond(200, ['success' => true, 'data' => apiSerializeBoardMessage($updated)], $auth['token_id'], $auth['user_id']);
}

apiError(405, 'Metodo non permesso, usa GET o PUT.', $auth['token_id'], $auth['user_id']);
