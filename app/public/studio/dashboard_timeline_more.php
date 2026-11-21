<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);

header('Content-Type: application/json; charset=UTF-8');

$offset = max(0, (int) ($_GET['offset'] ?? 0));
$pageSize = 20;
$viewMode = ($_GET['view'] ?? 'list') === 'card' ? 'card' : 'list';

$followedIds = getFollowedUserIds((int) $profile['id']);
$feedUserIds = array_merge($followedIds, [(int) $profile['id']]);
$feedFilters = dashboardFeedFilters($_GET);
$items = dashboardFeedFiltersActive($feedFilters)
    ? dashboardFeedFiltered((int) $profile['id'], $profile['slug'], $feedFilters, $pageSize, $offset)
    : dashboardFeedEnrich(getTimelineFeedForUsers($feedUserIds, $pageSize, $offset, (int) $profile['id']), (int) $profile['id'], $profile['slug']);
require_once __DIR__ . '/../../src/metricool_queue.php';
$metricoolActive = metricoolIsEnabled((int) $profile['id']);

$html = '';
foreach ($items as $item) {
    $html .= $viewMode === 'card'
        ? studioRenderTimelineCard($item, $profile['slug'], $metricoolActive)
        : studioRenderTimelineItem($item, $profile['slug'], $metricoolActive);
}

echo json_encode(['html' => $html, 'count' => count($items)]);
