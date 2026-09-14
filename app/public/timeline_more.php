<?php
require_once __DIR__ . '/../src/functions.php';

header('Content-Type: application/json; charset=UTF-8');

$slug = $_GET['slug'] ?? '';
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$afterDay = $_GET['after_day'] ?? '';
$pageSize = 20;

$stmt = getDB()->prepare('SELECT id, page_theme FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['html' => '', 'count' => 0]);
    exit;
}

$items = getTimelineFeedForUsers([$user['id']], $pageSize, $offset);

// Il tema AdminLTE raggruppa gli item per giorno (widget .timeline reale) e ha bisogno
// dell'ultima etichetta già mostrata in pagina per non ripeterla a cavallo tra due pagine.
if (($user['page_theme'] ?? 'colorful') === 'adminlte-profile') {
    $rows = renderAdminLteTimelineRows($items, $afterDay !== '' ? $afterDay : null);
    echo json_encode(['html' => $rows['html'], 'count' => count($items), 'lastDay' => $rows['lastDay']]);
    exit;
}

$html = '';
foreach ($items as $item) {
    $html .= renderTimelineFeedItem($item);
}

echo json_encode(['html' => $html, 'count' => count($items)]);
