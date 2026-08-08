<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();

header('Content-Type: application/json; charset=UTF-8');

$offset = max(0, (int) ($_GET['offset'] ?? 0));
$pageSize = 20;

$followedIds = getFollowedUserIds((int) $user['id']);
$feedUserIds = array_merge($followedIds, [(int) $user['id']]);
$items = getTimelineFeedForUsers($feedUserIds, $pageSize, $offset);

$html = '';
foreach ($items as $item) {
    $html .= renderDashboardTimelineItem($item, $user['slug']);
}

echo json_encode(['html' => $html, 'count' => count($items)]);
