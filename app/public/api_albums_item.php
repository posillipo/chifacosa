<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

$auth = authenticateApiRequest();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    apiError(400, 'ID album mancante o non valido.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('SELECT * FROM photo_albums WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $auth['user_id']]);
$album = $stmt->fetch();
if (!$album) {
    apiError(404, 'Album non trovato.', $auth['token_id'], $auth['user_id']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    apiRespond(200, ['success' => true, 'data' => apiSerializeAlbum($album, $auth['slug'])], $auth['token_id'], $auth['user_id']);
}

if ($method === 'PUT') {
    $data = apiReadJsonBody();
    $validated = apiValidateAlbumPayload($data, true);
    if ($validated['error'] !== null) {
        apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
    }
    $v = $validated['values'];

    if (array_key_exists('cover_image_url', $v)) {
        $downloaded = downloadImageFromUrlAsCover($v['cover_image_url'], $auth['slug']);
        if ($downloaded === null) {
            apiError(422, 'Non è stato possibile scaricare "cover_image_url".', $auth['token_id'], $auth['user_id']);
        }
        deleteCoverFile($album['cover_path'] ?? null);
        $v['cover_path'] = $downloaded['image_path'];
        unset($v['cover_image_url']);
    }

    $photoWarnings = [];
    if (array_key_exists('extra_photo_urls', $v)) {
        $oldPhotos = getAlbumPhotos($id);
        foreach ($oldPhotos as $old) {
            deleteCoverFile($old);
        }
        getDB()->prepare('DELETE FROM photo_album_photos WHERE album_id = ?')->execute([$id]);

        $saved = apiDownloadAndSaveAlbumPhotos($v['extra_photo_urls'], $auth['slug']);
        $order = 0;
        foreach ($saved as $path) {
            $ins = getDB()->prepare('INSERT INTO photo_album_photos (album_id, image_path, sort_order) VALUES (?,?,?)');
            $ins->execute([$id, $path, $order++]);
        }
        $failed = count($v['extra_photo_urls']) - count($saved);
        if ($failed > 0) {
            $photoWarnings[] = "{$failed} foto extra non scaricate (URL non raggiungibili).";
        }
        unset($v['extra_photo_urls']);
    }

    $columnMap = ['title', 'description', 'cover_path', 'is_public', 'in_feed', 'publish_at'];
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
        getDB()->prepare('UPDATE photo_albums SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    $stmt = getDB()->prepare('SELECT * FROM photo_albums WHERE id = ?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();

    $response = ['success' => true, 'data' => apiSerializeAlbum($updated, $auth['slug'])];
    if ($photoWarnings) {
        $response['photo_warnings'] = $photoWarnings;
    }
    apiRespond(200, $response, $auth['token_id'], $auth['user_id']);
}

if ($method === 'DELETE') {
    $oldPhotos = getAlbumPhotos($id);
    foreach ($oldPhotos as $old) {
        deleteCoverFile($old);
    }
    deleteCoverFile($album['cover_path'] ?? null);
    getDB()->prepare('DELETE FROM photo_albums WHERE id = ? AND user_id = ?')->execute([$id, $auth['user_id']]);
    apiRespond(200, ['success' => true, 'message' => 'Album eliminato.'], $auth['token_id'], $auth['user_id']);
}

apiError(405, 'Metodo non permesso, usa GET, PUT o DELETE.', $auth['token_id'], $auth['user_id']);
