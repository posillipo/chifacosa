<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();
$data = apiReadJsonBody();

$validated = apiValidateBlogPostPayload($data, false);
if ($validated['error'] !== null) {
    apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
}
$v = $validated['values'];

if (empty($v['title']) || empty($v['content'])) {
    apiError(422, 'I campi "title" e "content" sono obbligatori.', $auth['token_id'], $auth['user_id']);
}

$coverPath = null;
$imageWarning = null;
if (!empty($v['image_url'])) {
    $downloaded = downloadImageFromUrlAsCover($v['image_url'], $auth['slug']);
    if ($downloaded === null) {
        $imageWarning = 'Non è stato possibile scaricare "image_url": l\'articolo è stato creato senza copertina.';
    } else {
        $coverPath = $downloaded['image_path'];
    }
}

$categoryIds = !empty($v['categories']) ? apiResolveOrCreateBlogCategories($auth['user_id'], $v['categories']) : [];
$publishedAt = $v['published_at'] ?? date('Y-m-d H:i:s');
$excerpt = textExcerpt($v['content'], 200);
$slug = generateUniquePostSlug($auth['user_id'], $v['title']);

$stmt = getDB()->prepare('INSERT INTO blog_posts (user_id, title, slug, excerpt, content, cover_path, tags, published_at) VALUES (?,?,?,?,?,?,?,?)');
$stmt->execute([$auth['user_id'], $v['title'], $slug, $excerpt, $v['content'], $coverPath, $v['tags'] ?? null, $publishedAt]);
$postId = (int) getDB()->lastInsertId();

if ($categoryIds) {
    $insCat = getDB()->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (?,?)');
    foreach ($categoryIds as $cid) {
        $insCat->execute([$postId, $cid]);
    }
}

// Stessa regola della dashboard (dashboard_blog_new.php): niente notifica ai follower se
// l'articolo è programmato per il futuro.
if (strtotime($publishedAt) <= time()) {
    $stmt = getDB()->prepare('SELECT display_name, slug FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?');
    $stmt->execute([$auth['user_id']]);
    $profileRow = $stmt->fetch();
    if ($profileRow) {
        $postUrl = siteUrl(blogPostUrl($profileRow['slug'], ['published_at' => $publishedAt, 'slug' => $slug]));
        notifyFollowersNewContent($auth['user_id'], $profileRow['display_name'], $profileRow['slug'], 'blog', $v['title'], $postUrl);
    }
}

$response = [
    'success' => true,
    'post_id' => $postId,
    'message' => 'Articolo creato' . (strtotime($publishedAt) > time() ? ' e schedulato.' : '.'),
    'scheduled_for' => strtotime($publishedAt) > time() ? apiFormatDateTimeRome($publishedAt) : null,
];
if ($imageWarning) {
    $response['warning'] = $imageWarning;
}

apiRespond(201, $response, $auth['token_id'], $auth['user_id']);
