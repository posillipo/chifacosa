<?php
require_once __DIR__ . '/../src/functions.php';

// Endpoint pubblico, protetto da token, pensato per essere richiamato da un cron di sistema
// (vedi Dashboard → menu hamburger → Cinema per il comando esatto). Sincronizza TUTTI i profili
// che hanno un URL JSON film configurato — non serve login, l'autenticazione è solo il token.
header('Content-Type: text/plain; charset=UTF-8');
set_time_limit(300);

$token = $_GET['token'] ?? '';
if (!hash_equals(getCinemaSyncCronToken(), $token)) {
    http_response_code(403);
    echo "Token non valido.\n";
    exit;
}

$stmt = getDB()->prepare("SELECT u.id, u.slug, p.cinema_films_json_url
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.is_active = 1 AND p.cinema_films_json_url IS NOT NULL AND p.cinema_films_json_url != ''");
$stmt->execute();
$profiles = $stmt->fetchAll();

if (!$profiles) {
    echo "Nessun profilo con un URL JSON film configurato.\n";
    exit;
}

foreach ($profiles as $p) {
    $result = syncCinemaFilms($p);
    if ($result['ok']) {
        echo "{$p['slug']}: OK — {$result['added']} aggiunti, {$result['updated']} aggiornati, {$result['removed']} rimossi ({$result['total']} totali)\n";
    } else {
        echo "{$p['slug']}: ERRORE — {$result['error']}\n";
    }
}
