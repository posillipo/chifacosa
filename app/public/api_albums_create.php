<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();
$data = apiReadJsonBody();

$validated = apiValidateAlbumPayload($data, false);
if ($validated['error'] !== null) {
    apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
}
$v = $validated['values'];

if (empty($v['title'])) {
    apiError(422, 'Il campo "title" è obbligatorio.', $auth['token_id'], $auth['user_id']);
}

$coverPath = null;
$imageWarning = null;
if (!empty($v['cover_image_url'])) {
    $downloaded = downloadImageFromUrlAsCover($v['cover_image_url'], $auth['slug']);
    if ($downloaded === null) {
        $imageWarning = 'Non è stato possibile scaricare "cover_image_url": l\'album è stato creato senza copertina.';
    } else {
        $coverPath = $downloaded['image_path'];
    }
}

$maxSort = getDB()->prepare('SELECT COALESCE(MAX(sort_order), 0) m FROM photo_albums WHERE user_id = ?');
$maxSort->execute([$auth['user_id']]);
$sortOrder = (int) $maxSort->fetch()['m'] + 1;

$stmt = getDB()->prepare('INSERT INTO photo_albums (user_id, title, description, cover_path, is_public, in_feed, publish_at, sort_order) VALUES (?,?,?,?,?,?,?,?)');
$stmt->execute([
    $auth['user_id'],
    $v['title'],
    $v['description'] ?? null,
    $coverPath,
    $v['is_public'] ?? 1,
    $v['in_feed'] ?? 1,
    $v['publish_at'] ?? null,
    $sortOrder,
]);
$albumId = (int) getDB()->lastInsertId();

$photoWarnings = [];
if (!empty($v['extra_photo_urls'])) {
    $saved = apiDownloadAndSaveAlbumPhotos($v['extra_photo_urls'], $auth['slug']);
    $order = 0;
    foreach ($saved as $path) {
        $ins = getDB()->prepare('INSERT INTO photo_album_photos (album_id, image_path, sort_order) VALUES (?,?,?)');
        $ins->execute([$albumId, $path, $order++]);
    }
    $failed = count($v['extra_photo_urls']) - count($saved);
    if ($failed > 0) {
        $photoWarnings[] = "{$failed} foto extra non scaricate (URL non raggiungibili).";
    }
}

$publishAt = $v['publish_at'] ?? null;
$isPublic = (int) ($v['is_public'] ?? 1);
$isScheduledForFuture = $publishAt !== null && strtotime($publishAt) > time();
if ($isPublic && !$isScheduledForFuture) {
    $stmt = getDB()->prepare('SELECT display_name, slug FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?');
    $stmt->execute([$auth['user_id']]);
    $profileRow = $stmt->fetch();
    if ($profileRow) {
        $albumUrl = siteUrl('/' . $profileRow['slug'] . '/album/' . $albumId);
        notifyFollowersNewContent($auth['user_id'], $profileRow['display_name'], $profileRow['slug'], 'album_foto', $v['title'], $albumUrl);
    }
}

$stmt = getDB()->prepare('SELECT * FROM photo_albums WHERE id = ?');
$stmt->execute([$albumId]);
$album = $stmt->fetch();

$response = [
    'success' => true,
    'album_id' => $albumId,
    'message' => 'Album creato.',
    'data' => apiSerializeAlbum($album, $auth['slug']),
];
if ($imageWarning) {
    $response['warning'] = $imageWarning;
}
if ($photoWarnings) {
    $response['photo_warnings'] = $photoWarnings;
}

apiRespond(201, $response, $auth['token_id'], $auth['user_id']);
