<?php
require_once __DIR__ . '/../src/functions.php';

// Endpoint pubblico che genera il file .ics di un singolo evento, per il pulsante "Aggiungi al
// calendario" (Apple Calendar/Outlook, che non supportano il link diretto di Google Calendar) —
// vedi renderAddToCalendarLinks() in functions.php.

$slug = $_GET['slug'] ?? '';
$eventId = (int) ($_GET['id'] ?? 0);

$stmt = getDB()->prepare('SELECT p.dashboard_theme, ev.*
                          FROM events ev
                          JOIN users u ON u.id = ev.user_id
                          JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND ev.id = ? AND u.is_active = 1');
$stmt->execute([$slug, $eventId]);
$event = $stmt->fetch();

if (!$event || !empty($event['is_perpetual']) || ($event['recurrence'] ?? 'none') !== 'none') {
    http_response_code(404);
    exit('Evento non trovato.');
}

$profile = ['dashboard_theme' => $event['dashboard_theme'] ?? null];
$start = eventCalendarUtcTimestamp($event['event_date'], $profile);
$end = eventCalendarUtcTimestamp($event['event_date'], $profile, EVENT_CALENDAR_DEFAULT_DURATION_HOURS);
$location = trim(($event['venue'] ?: '') . ($event['venue'] && $event['city'] ? ', ' : '') . ($event['city'] ?: ''));
$url = siteUrl('/' . $slug . '/eventi/' . (int) $event['id']);

// Escape testo secondo RFC 5545: backslash/virgola/punto e virgola vanno preceduti da backslash,
// gli a-capo diventano "\n" letterale (non un vero a-capo, che spezzerebbe la riga ICS).
function icsEscape(string $text): string {
    $text = str_replace(['\\', ',', ';'], ['\\\\', '\\,', '\\;'], $text);
    return str_replace(["\r\n", "\n", "\r"], '\\n', $text);
}

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//' . icsEscape(siteName()) . '//Eventi//IT',
    'CALSCALE:GREGORIAN',
    'BEGIN:VEVENT',
    'UID:evento-' . (int) $event['id'] . '@' . parse_url($url, PHP_URL_HOST),
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . $start,
    'DTEND:' . $end,
    'SUMMARY:' . icsEscape($event['title']),
    'URL:' . icsEscape($url),
];
if ($location !== '') {
    $lines[] = 'LOCATION:' . icsEscape($location);
}
if (!empty($event['description'])) {
    $lines[] = 'DESCRIPTION:' . icsEscape($event['description']);
}
$lines[] = 'END:VEVENT';
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="evento-' . (int) $event['id'] . '.ics"');
echo implode("\r\n", $lines) . "\r\n";
