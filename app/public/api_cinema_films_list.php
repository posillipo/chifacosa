<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();

$stmt = getDB()->prepare('SELECT * FROM profiles WHERE user_id = ?');
$stmt->execute([$auth['user_id']]);
$profileRow = $stmt->fetch();
if (!$profileRow) {
    apiError(404, 'Profilo non trovato.', $auth['token_id'], $auth['user_id']);
}
// syncCinemaFilms() si aspetta un array "profilo" con id/slug — stessa forma restituita da
// getActingProfile() nel resto del sito, qui ricostruita a mano dai dati già noti dal token.
$profileRow['id'] = $auth['user_id'];
$profileRow['slug'] = $auth['slug'];

// "sync=1" forza una sincronizzazione col JSON esterno prima di leggere — utile se non è già
// configurato un cron (cron_cinema_sync.php) per tenerlo aggiornato da solo. Un errore di sync
// non blocca la risposta: si torna comunque l'elenco già salvato, con un avviso.
$syncWarning = null;
if (!empty($_GET['sync'])) {
    $syncResult = syncCinemaFilms($profileRow);
    if (!$syncResult['ok']) {
        $syncWarning = 'Sincronizzazione non riuscita: ' . $syncResult['error'];
    } else {
        // syncCinemaFilms() aggiorna cinema_films_synced_at nel database con NOW(), ma $profileRow
        // è già stato letto prima: senza questo la risposta mostrerebbe ancora l'orario del sync
        // precedente invece di quello appena eseguito.
        $profileRow['cinema_films_synced_at'] = date('Y-m-d H:i:s');
    }
}

$since = trim((string) ($_GET['since'] ?? ''));
$where = "user_id = ? AND link_type = 'film'";
$params = [$auth['user_id']];
if ($since !== '') {
    $where .= ' AND created_at >= ?';
    $params[] = $since . ' 00:00:00';
}

$stmt = getDB()->prepare("SELECT * FROM links WHERE {$where} ORDER BY created_at DESC");
$stmt->execute($params);
$films = $stmt->fetchAll();

$response = [
    'success' => true,
    'data' => array_map('apiSerializeCinemaFilm', $films),
    'last_synced_at' => apiFormatDateTimeRome($profileRow['cinema_films_synced_at'] ?? null),
];
if ($syncWarning) {
    $response['sync_warning'] = $syncWarning;
}

apiRespond(200, $response, $auth['token_id'], $auth['user_id']);
