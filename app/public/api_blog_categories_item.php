<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

$auth = authenticateApiRequest();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    apiError(400, 'ID categoria mancante o non valido.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('SELECT * FROM blog_categories WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $auth['user_id']]);
$category = $stmt->fetch();
if (!$category) {
    apiError(404, 'Categoria non trovata.', $auth['token_id'], $auth['user_id']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'DELETE') {
    // blog_post_categories ha ON DELETE CASCADE: gli articoli eventualmente assegnati restano,
    // perdono solo questa categoria — stesso comportamento di dashboard_blog_categories.php.
    getDB()->prepare('DELETE FROM blog_categories WHERE id = ? AND user_id = ?')->execute([$id, $auth['user_id']]);
    apiRespond(200, ['success' => true, 'message' => 'Categoria eliminata.'], $auth['token_id'], $auth['user_id']);
}

apiError(405, 'Metodo non permesso, usa DELETE.', $auth['token_id'], $auth['user_id']);
