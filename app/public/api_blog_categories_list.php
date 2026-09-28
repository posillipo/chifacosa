<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

// post_count qui è quello che serve per individuare le categorie "orfane" (create al volo da
// apiResolveOrCreateBlogCategories() quando un articolo la referenzia per nome, mai più usate se
// quell'articolo viene poi modificato o eliminato): un chiamante MCP non ha altro modo di saperlo
// senza incrociare a mano l'elenco articoli.
$stmt = getDB()->prepare('SELECT c.id, c.name, c.slug,
        (SELECT COUNT(*) FROM blog_post_categories pc WHERE pc.category_id = c.id) AS post_count
    FROM blog_categories c WHERE c.user_id = ? ORDER BY c.name ASC');
$stmt->execute([$auth['user_id']]);
$categories = $stmt->fetchAll();

apiRespond(200, [
    'success' => true,
    'data' => array_map(fn ($c) => [
        'id' => (int) $c['id'],
        'name' => $c['name'],
        'slug' => $c['slug'],
        'post_count' => (int) $c['post_count'],
        'url' => siteUrl(blogCategoryUrl($auth['slug'], $c)),
    ], $categories),
], $auth['token_id'], $auth['user_id']);
