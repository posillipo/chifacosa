<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

$slug = $_POST['slug'] ?? $_GET['slug'] ?? '';
$eventId = (int) ($_POST['event_id'] ?? $_GET['event_id'] ?? 0);

$stmt = getDB()->prepare('SELECT u.id, u.slug, u.email, p.display_name, p.privacy_tracking_settings
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$owner = $stmt->fetch();

if (!$owner) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$stmt = getDB()->prepare('SELECT * FROM events WHERE id = ? AND user_id = ? AND accepts_reservations = 1');
$stmt->execute([$eventId, $owner['id']]);
$event = $stmt->fetch();

if (!$event) {
    http_response_code(404);
    exit('Evento non trovato o prenotazioni non attive.');
}

$redirectBase = '/' . $owner['slug'] . '/eventi/' . $eventId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectBase);
    exit;
}

checkCsrf();

$guestName = trim($_POST['guest_name'] ?? '');
$guestEmail = trim($_POST['guest_email'] ?? '');
$guestPhone = trim($_POST['guest_phone'] ?? '');
$partySize = (int) ($_POST['party_size'] ?? 0);
$notes = trim($_POST['notes'] ?? '');
$marketingOptIn = isset($_POST['marketing_opt_in']) ? 1 : 0;

$message = null;
$isError = false;

if ($guestName === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    $message = 'Inserisci nome ed email validi.';
    $isError = true;
} elseif ($partySize < 1 || $partySize > 50) {
    $message = 'Inserisci un numero di persone valido (1-50).';
    $isError = true;
} else {
    $stmt = getDB()->prepare('INSERT INTO table_reservations
        (user_id, event_id, guest_name, guest_email, guest_phone, party_size, notes, marketing_opt_in)
        VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $owner['id'], $eventId, $guestName, $guestEmail, $guestPhone ?: null, $partySize, $notes ?: null, $marketingOptIn,
    ]);

    $reservationsUrl = siteUrl('/dashboard_reservations.php');
    notifyNewReservation($owner['email'], $owner['display_name'], $event['title'], $guestName, $guestEmail, $guestPhone ?: null, $partySize, $notes ?: null, $reservationsUrl);
    notifyReservationConfirmation($guestEmail, $guestName, $owner['display_name'], $event['title'], date('d/m/Y H:i', strtotime($event['event_date'])), $partySize);
    sendMetaConversionEvent('Schedule', generateEventId(), $guestEmail, $owner);

    $message = 'Prenotazione ricevuta! Controlla la tua email per la conferma.';
}

header('Location: ' . $redirectBase . '?res_msg=' . urlencode($message) . '&res_err=' . ($isError ? '1' : '0'));
exit;
