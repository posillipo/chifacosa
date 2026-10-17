<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';

// Caricamento di immagini generate (es. dalle routine) in un album fotografico PRIVATO del profilo
// ("Immagini social"), al posto di un servizio esterno:
//   POST /api/v1/uploads/request   (Bearer)  -> indirizzo di caricamento monouso, valido 10 minuti
//   PUT  /api/v1/uploads/put/{token}          -> corpo = file immagine; risponde con l'URL pubblico
// L'indirizzo di caricamento è firmato (HMAC) e porta già profilo e album: chi lo riceve può solo
// aggiungere UNA foto a quell'album, niente altro.

const UPLOAD_ALBUM_TITLE = 'Immagini social';
const UPLOAD_TTL_SECONDS = 600;
const UPLOAD_MAX_BYTES = 12 * 1024 * 1024;
const UPLOAD_RETENTION_DAYS = 60;

function uploadSecret(): string {
    return 'chifacosa-upload:' . (getenv('DB_PASS') ?: 'chifacosa-fallback-secret');
}

function uploadB64(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function uploadMakeToken(int $userId, int $albumId): string {
    $payload = uploadB64(json_encode(['u' => $userId, 'a' => $albumId, 'e' => time() + UPLOAD_TTL_SECONDS, 'n' => bin2hex(random_bytes(8))]));
    return $payload . '.' . hash_hmac('sha256', $payload, uploadSecret());
}

function uploadReadToken(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], uploadSecret()), $parts[1])) {
        return null;
    }
    $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
    if (!is_array($data) || ($data['e'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

function uploadEnsureAlbum(int $userId): int {
    $stmt = getDB()->prepare('SELECT id FROM photo_albums WHERE user_id = ? AND title = ? LIMIT 1');
    $stmt->execute([$userId, UPLOAD_ALBUM_TITLE]);
    if ($id = $stmt->fetchColumn()) {
        return (int) $id;
    }
    $max = getDB()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM photo_albums WHERE user_id = ?');
    $max->execute([$userId]);
    getDB()->prepare('INSERT INTO photo_albums (user_id, title, description, is_public, in_feed, sort_order) VALUES (?,?,?,0,0,?)')
        ->execute([$userId, UPLOAD_ALBUM_TITLE, 'Immagini generate per i post social (uso interno, non pubblico).', (int) $max->fetchColumn()]);
    return (int) getDB()->lastInsertId();
}

// Elimina le foto più vecchie di UPLOAD_RETENTION_DAYS dall'album, per non riempire il disco.
function uploadPruneAlbum(int $albumId): void {
    $stmt = getDB()->prepare('SELECT id, image_path FROM photo_album_photos WHERE album_id = ? AND created_at < NOW() - INTERVAL ' . UPLOAD_RETENTION_DAYS . ' DAY');
    $stmt->execute([$albumId]);
    foreach ($stmt->fetchAll() as $old) {
        deleteCoverFile($old['image_path']);
        getDB()->prepare('DELETE FROM photo_album_photos WHERE id = ?')->execute([$old['id']]);
    }
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'request') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiError(405, 'Metodo non permesso, usa POST.');
    }
    $auth = authenticateApiRequest();
    $albumId = uploadEnsureAlbum($auth['user_id']);
    uploadPruneAlbum($albumId);
    $token = uploadMakeToken($auth['user_id'], $albumId);
    apiRespond(200, [
        'success' => true,
        'upload_url' => siteUrl('/api/v1/uploads/put/' . $token),
        'method' => 'PUT',
        'content_type' => 'image/jpeg (o png/webp)',
        'expires_in_seconds' => UPLOAD_TTL_SECONDS,
        'max_bytes' => UPLOAD_MAX_BYTES,
        'album_id' => $albumId,
        'album_title' => UPLOAD_ALBUM_TITLE,
        'hint' => 'curl -sS -X PUT -H "Content-Type: image/jpeg" --data-binary @file.jpg "<upload_url>" — la risposta contiene image_url, da usare come image_url/cover_image_url.',
    ], $auth['token_id'], $auth['user_id']);
}

if ($action === 'put') {
    header('Content-Type: application/json; charset=UTF-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        http_response_code(405);
        exit(json_encode(['success' => false, 'error' => 'Metodo non permesso, usa PUT.']));
    }
    $token = (string) ($_GET['token'] ?? '');
    $data = uploadReadToken($token);
    if (!$data) {
        http_response_code(401);
        exit(json_encode(['success' => false, 'error' => 'Indirizzo di caricamento non valido o scaduto.']));
    }
    // Monouso: il marcatore si crea in modo atomico, una seconda richiesta con lo stesso token fallisce.
    $marker = sys_get_temp_dir() . '/upl_' . hash('sha256', $token);
    $fh = @fopen($marker, 'x');
    if ($fh === false) {
        http_response_code(409);
        exit(json_encode(['success' => false, 'error' => 'Indirizzo di caricamento già usato: richiedine uno nuovo.']));
    }
    fclose($fh);

    $raw = '';
    $in = fopen('php://input', 'rb');
    while (!feof($in) && strlen($raw) <= UPLOAD_MAX_BYTES) {
        $raw .= fread($in, 65536);
    }
    fclose($in);
    if ($raw === '' || strlen($raw) > UPLOAD_MAX_BYTES) {
        @unlink($marker);
        http_response_code(413);
        exit(json_encode(['success' => false, 'error' => 'File mancante o più grande di ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB.']));
    }
    $jpeg = compressImageToJpeg($raw, 900000, 2000);
    if ($jpeg === null) {
        @unlink($marker);
        http_response_code(422);
        exit(json_encode(['success' => false, 'error' => 'Il file non è un\'immagine valida.']));
    }

    $stmt = getDB()->prepare('SELECT a.id, u.slug FROM photo_albums a JOIN users u ON u.id = a.user_id WHERE a.id = ? AND a.user_id = ?');
    $stmt->execute([(int) $data['a'], (int) $data['u']]);
    $album = $stmt->fetch();
    if (!$album) {
        @unlink($marker);
        http_response_code(404);
        exit(json_encode(['success' => false, 'error' => 'Album non trovato.']));
    }

    $dir = '/var/www/html/uploads/images/' . $album['slug'];
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $fname = bin2hex(random_bytes(6)) . '.jpg';
    if (file_put_contents($dir . '/' . $fname, $jpeg) === false) {
        @unlink($marker);
        http_response_code(500);
        exit(json_encode(['success' => false, 'error' => 'Salvataggio non riuscito.']));
    }
    $path = 'uploads/images/' . $album['slug'] . '/' . $fname;
    $next = getDB()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM photo_album_photos WHERE album_id = ?');
    $next->execute([(int) $album['id']]);
    getDB()->prepare('INSERT INTO photo_album_photos (album_id, image_path, sort_order) VALUES (?,?,?)')
        ->execute([(int) $album['id'], $path, (int) $next->fetchColumn()]);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'image_url' => siteUrl('/' . $path),
        'photo_id' => (int) getDB()->lastInsertId(),
        'album_id' => (int) $album['id'],
        'bytes' => strlen($jpeg),
    ]);
    exit;
}

apiError(404, 'Azione non trovata.');
