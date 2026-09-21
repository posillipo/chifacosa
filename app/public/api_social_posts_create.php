<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();
$data = apiReadJsonBody();

$validated = apiValidateSocialPostPayload($data, false);
if ($validated['error'] !== null) {
    apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
}
$v = $validated['values'];

$imagePath = null;
$imageThumbPath = null;
$imageWarning = null;
if (!empty($v['image_url'])) {
    $downloaded = downloadImageFromUrlAsCover($v['image_url'], $auth['slug']);
    if ($downloaded === null) {
        $imageWarning = 'Non è stato possibile scaricare "image_url": il post è stato creato senza immagine.';
    } else {
        $imagePath = $downloaded['image_path'];
        $imageThumbPath = $downloaded['thumb_path'];
    }
}

$visibility = $v['visibility'] ?? 'public';
$publishAt = $v['publish_at'] ?? null;

$stmt = getDB()->prepare('INSERT INTO timeline_posts (user_id, title, testo, image_path, image_thumb_path, hashtags, call_to_action, redirect_link, source, visibility, in_feed, publish_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([
    $auth['user_id'],
    $v['title'] ?? null,
    $v['testo'] ?? null,
    $imagePath,
    $imageThumbPath,
    $v['hashtags'] ?? null,
    $v['call_to_action'] ?? null,
    $v['redirect_link'] ?? null,
    'api',
    $visibility,
    1,
    $publishAt,
]);
$postId = (int) getDB()->lastInsertId();

// Stessa regola della dashboard (dashboard_post.php): niente notifica ai follower se il post è
// privato o programmato per il futuro.
if ($visibility === 'public' && !$publishAt) {
    $stmt = getDB()->prepare('SELECT display_name, slug FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?');
    $stmt->execute([$auth['user_id']]);
    $profileRow = $stmt->fetch();
    if ($profileRow) {
        $anteprima = !empty($v['title']) ? $v['title'] : (!empty($v['testo']) ? textExcerpt($v['testo'], 80) : 'Nuovo contenuto pubblicato');
        $timelineUrl = siteUrl('/' . $profileRow['slug'] . '/timeline');
        notifyFollowersNewContent($auth['user_id'], $profileRow['display_name'], $profileRow['slug'], 'timeline', $anteprima, $timelineUrl);
    }
}

$response = [
    'success' => true,
    'post_id' => $postId,
    'message' => 'Post creato' . ($publishAt ? ' e schedulato.' : '.'),
    'scheduled_for' => $publishAt ? apiFormatDateTimeRome($publishAt) : null,
];
if ($imageWarning) {
    $response['warning'] = $imageWarning;
}

apiRespond(201, $response, $auth['token_id'], $auth['user_id']);
