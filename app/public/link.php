<?php
// Redirector che conteggia i click su un link pubblico prima di rimandare all'URL reale
require_once __DIR__ . '/../src/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = getDB()->prepare('SELECT url FROM links WHERE id=? AND is_active=1');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Link non trovato.');
}

$upd = getDB()->prepare('UPDATE links SET click_count = click_count + 1 WHERE id=?');
$upd->execute([$id]);

header('Location: ' . $row['url']);
exit;
