<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

$auth = authenticateApiRequest();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    apiError(400, 'ID articolo mancante o non valido.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('SELECT * FROM blog_posts WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $auth['user_id']]);
$post = $stmt->fetch();
if (!$post) {
    apiError(404, 'Articolo non trovato.', $auth['token_id'], $auth['user_id']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    apiRespond(200, ['success' => true, 'data' => apiSerializeBlogPost($post, $auth['slug'])], $auth['token_id'], $auth['user_id']);
}

if ($method === 'PUT') {
    // A differenza dei post Timeline, un articolo blog resta modificabile via API anche da
    // pubblicato: stessa possibilità già offerta in dashboard (dashboard_blog_edit.php), nessuna
    // restrizione aggiuntiva qui.
    $data = apiReadJsonBody();
    $validated = apiValidateBlogPostPayload($data, true);
    if ($validated['error'] !== null) {
        apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
    }
    $v = $validated['values'];

    if (array_key_exists('title', $v) && $v['title'] !== $post['title']) {
        // Rigenera lo slug solo se il titolo cambia davvero, per non spostare inutilmente
        // l'URL pubblico di un articolo già condiviso.
        $v['slug'] = generateUniquePostSlug($auth['user_id'], $v['title'], $id);
    }
    if (array_key_exists('content', $v)) {
        $v['excerpt'] = textExcerpt($v['content'], 200);
    }

    if (array_key_exists('image_url', $v)) {
        $downloaded = downloadImageFromUrlAsCover($v['image_url'], $auth['slug']);
        if ($downloaded === null) {
            apiError(422, 'Non è stato possibile scaricare la nuova "image_url".', $auth['token_id'], $auth['user_id']);
        }
        deleteCoverFile($post['cover_path'] ?? null);
        $v['cover_path'] = $downloaded['image_path'];
        unset($v['image_url']);
    }

    // Le categorie non sono una colonna di blog_posts (tabella ponte blog_post_categories):
    // vanno risolte/salvate a parte, dopo l'UPDATE delle colonne dirette.
    $categoryNames = null;
    if (array_key_exists('categories', $v)) {
        $categoryNames = $v['categories'];
        unset($v['categories']);
    }

    $columnMap = ['title', 'slug', 'excerpt', 'content', 'tags', 'published_at', 'cover_path'];
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
        getDB()->prepare('UPDATE blog_posts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    if ($categoryNames !== null) {
        $categoryIds = apiResolveOrCreateBlogCategories($auth['user_id'], $categoryNames);
        getDB()->prepare('DELETE FROM blog_post_categories WHERE post_id = ?')->execute([$id]);
        if ($categoryIds) {
            $insCat = getDB()->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (?,?)');
            foreach ($categoryIds as $cid) {
                $insCat->execute([$id, $cid]);
            }
        }
    }

    $stmt = getDB()->prepare('SELECT * FROM blog_posts WHERE id = ?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();
    apiRespond(200, ['success' => true, 'data' => apiSerializeBlogPost($updated, $auth['slug'])], $auth['token_id'], $auth['user_id']);
}

if ($method === 'DELETE') {
    deleteCoverFile($post['cover_path'] ?? null);
    getDB()->prepare('DELETE FROM blog_posts WHERE id = ? AND user_id = ?')->execute([$id, $auth['user_id']]);
    apiRespond(200, ['success' => true, 'message' => 'Articolo eliminato.'], $auth['token_id'], $auth['user_id']);
}

apiError(405, 'Metodo non permesso, usa GET, PUT o DELETE.', $auth['token_id'], $auth['user_id']);
