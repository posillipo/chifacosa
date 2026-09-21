<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

$auth = authenticateApiRequest();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    apiError(400, 'ID post mancante o non valido.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('SELECT * FROM timeline_posts WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $auth['user_id']]);
$post = $stmt->fetch();
if (!$post) {
    apiError(404, 'Post non trovato.', $auth['token_id'], $auth['user_id']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    apiRespond(200, ['success' => true, 'data' => apiSerializePost($post, $auth['slug'])], $auth['token_id'], $auth['user_id']);
}

if ($method === 'PUT') {
    $currentStatus = apiDerivePostStatus($post);
    if (!in_array($currentStatus, ['draft', 'scheduled'], true)) {
        apiError(409, 'Solo i post con status "draft" o "scheduled" possono essere modificati (questo è già "published").', $auth['token_id'], $auth['user_id']);
    }

    $data = apiReadJsonBody();
    $validated = apiValidateSocialPostPayload($data, true);
    if ($validated['error'] !== null) {
        apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
    }
    $v = $validated['values'];

    if (array_key_exists('image_url', $v)) {
        $downloaded = downloadImageFromUrlAsCover($v['image_url'], $auth['slug']);
        if ($downloaded === null) {
            apiError(422, 'Non è stato possibile scaricare la nuova "image_url".', $auth['token_id'], $auth['user_id']);
        }
        deleteCoverFile($post['image_path'] ?? null);
        deleteCoverFile($post['image_thumb_path'] ?? null);
        $v['image_path'] = $downloaded['image_path'];
        $v['image_thumb_path'] = $downloaded['thumb_path'];
        unset($v['image_url']);
    }

    $columnMap = ['title', 'testo', 'hashtags', 'call_to_action', 'redirect_link', 'visibility', 'publish_at', 'image_path', 'image_thumb_path'];
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
        getDB()->prepare('UPDATE timeline_posts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    $stmt = getDB()->prepare('SELECT * FROM timeline_posts WHERE id = ?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();
    apiRespond(200, ['success' => true, 'data' => apiSerializePost($updated, $auth['slug'])], $auth['token_id'], $auth['user_id']);
}

if ($method === 'DELETE') {
    deleteCoverFile($post['image_path'] ?? null);
    deleteFeedShareImage($post['image_path'] ?? '');
    deleteCoverFile($post['image_thumb_path'] ?? null);
    foreach (getTimelinePostPhotos($id) as $extraPath) {
        deleteCoverFile($extraPath);
    }
    getDB()->prepare('DELETE FROM timeline_posts WHERE id = ? AND user_id = ?')->execute([$id, $auth['user_id']]);
    apiRespond(200, ['success' => true, 'message' => 'Post eliminato.'], $auth['token_id'], $auth['user_id']);
}

apiError(405, 'Metodo non permesso, usa GET, PUT o DELETE.', $auth['token_id'], $auth['user_id']);
