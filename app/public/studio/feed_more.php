<?php
// Pagine successive del Feed (scorrimento infinito): {html, count}.
require_once __DIR__ . '/_studio.php';
header('Content-Type: application/json; charset=UTF-8');
$c = studioBoot();
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$items = studioFeed($c, $offset);
$html = '';
foreach ($items as $it) { $html .= studioCard($it, $c['profile']['slug']); }
echo json_encode(['html' => $html, 'count' => count($items)], JSON_UNESCAPED_UNICODE);
