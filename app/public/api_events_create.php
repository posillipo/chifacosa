<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();
$data = apiReadJsonBody();

$validated = apiValidateEventPayload($data, false);
if ($validated['error'] !== null) {
    apiError(422, $validated['error'], $auth['token_id'], $auth['user_id']);
}
$v = $validated['values'];

if (empty($v['title']) || empty($v['event_date'])) {
    apiError(422, 'I campi "title" e "event_date" sono obbligatori.', $auth['token_id'], $auth['user_id']);
}

$coverPath = null;
$imageWarning = null;
if (!empty($v['image_url'])) {
    $downloaded = downloadImageFromUrlAsCover($v['image_url'], $auth['slug']);
    if ($downloaded === null) {
        $imageWarning = 'Non è stato possibile scaricare "image_url": l\'evento è stato creato senza copertina.';
    } else {
        $coverPath = $downloaded['image_path'];
    }
}

$stmt = getDB()->prepare('INSERT INTO events (user_id, title, venue, city, provincia, event_date, ticket_url, description, is_perpetual, recurrence, cover_path, accepts_reservations) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([
    $auth['user_id'],
    $v['title'],
    $v['venue'] ?? null,
    $v['city'] ?? null,
    $v['provincia'] ?? null,
    $v['event_date'],
    $v['ticket_url'] ?? null,
    $v['description'] ?? null,
    $v['is_perpetual'] ?? 0,
    $v['recurrence'] ?? 'none',
    $coverPath,
    $v['accepts_reservations'] ?? 0,
]);
$eventId = (int) getDB()->lastInsertId();

// Stessa regola della dashboard (dashboard_events.php): un evento è sempre visibile subito (non
// esiste un concetto di programmazione/bozza per gli eventi), quindi la notifica ai follower
// parte sempre alla creazione.
$stmt = getDB()->prepare('SELECT display_name, slug FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?');
$stmt->execute([$auth['user_id']]);
$profileRow = $stmt->fetch();
if ($profileRow) {
    $eventUrl = siteUrl('/' . $profileRow['slug'] . '/eventi/' . $eventId);
    notifyFollowersNewContent($auth['user_id'], $profileRow['display_name'], $profileRow['slug'], 'evento', $v['title'], $eventUrl);
}

$response = [
    'success' => true,
    'event_id' => $eventId,
    'message' => 'Evento creato.',
];
if ($imageWarning) {
    $response['warning'] = $imageWarning;
}

apiRespond(201, $response, $auth['token_id'], $auth['user_id']);
