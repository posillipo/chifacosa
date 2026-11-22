<?php
// Ricerca profili per la barra in alto della dashboard (utenti collegati). GET ?q=testo → JSON.
// Cerca per nome e per @nome-pagina tra i profili attivi e non privati; esclude il profilo che si sta gestendo.
session_start();
require_once __DIR__ . '/../src/functions.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = currentUser();
if (!$user) {
    http_response_code(401);
    exit(json_encode(['results' => []]));
}

$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr(ltrim($q, '@'), 0, 60);
if (mb_strlen($q) < 2) {
    exit(json_encode(['results' => []]));
}

// Limite di richieste per sessione: 40 al minuto (protezione da scansioni automatiche).
$now = time();
$hits = array_filter($_SESSION['psearch_hits'] ?? [], fn($t) => $t > $now - 60);
if (count($hits) >= 40) {
    http_response_code(429);
    exit(json_encode(['results' => []]));
}
$hits[] = $now;
$_SESSION['psearch_hits'] = array_values($hits);
session_write_close();

$like = '%' . addcslashes($q, '\\%_') . '%';
$prefix = addcslashes($q, '\\%_') . '%';
$self = (int) (getActingProfile($user)['id'] ?? $user['id']);

$privOnly = privateProfileExcludeSql('p');
$st = getDB()->prepare("SELECT u.slug, u.account_type, p.display_name, p.avatar_path, p.citta
    FROM users u JOIN profiles p ON p.user_id = u.id
    WHERE u.is_active = 1{$privOnly} AND u.id <> ? AND (p.display_name LIKE ? OR u.slug LIKE ?)
    ORDER BY (p.display_name LIKE ? OR u.slug LIKE ?) DESC, p.display_name ASC
    LIMIT 8");
$st->execute([$self, $like, $like, $prefix, $prefix]);

$types = ['band' => 'Band', 'label' => 'Etichetta', 'fan' => 'Appassionato'];
$out = [];
foreach ($st->fetchAll() as $r) {
    $out[] = [
        'slug'   => $r['slug'],
        'name'   => $r['display_name'] ?: $r['slug'],
        'avatar' => $r['avatar_path'] ? '/' . ltrim($r['avatar_path'], '/') : null,
        'sub'    => trim('@' . $r['slug'] . ($r['citta'] ? ' · ' . $r['citta'] : '')),
    ];
}
echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE);
