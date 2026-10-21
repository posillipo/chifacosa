<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/videos.php';

// Modulo VIDEO via token Bearer (stesse tabelle e regole di dashboard_videos.php):
//   GET    /api/v1/videos/list                elenco (nell'ordine di visualizzazione)
//   GET    /api/v1/videos/{id}                dettaglio
//   PUT    /api/v1/videos/{id}                titolo, descrizione, visibilità, feed, programmazione, posizione, anteprima
//   DELETE /api/v1/videos/{id}                elimina video e file
//   POST   /api/v1/videos/reorder             {order:[ids]}
//   POST   /api/v1/videos/from-url            crea scaricando il video da un URL pubblico
//   POST   /api/v1/videos/request-upload      indirizzo di caricamento firmato (monouso, 30 minuti)
//   PUT    /api/v1/videos/put/{token}         corpo = file video: crea il video e risponde con i dati

const VIDEO_UPLOAD_TTL = 1800;

function videoApiSecret(): string {
    return 'chifacosa-video-upload:' . (getenv('DB_PASS') ?: 'chifacosa-fallback-secret');
}

function videoApiB64(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function videoApiMakeToken(array $payload): string {
    $payload['e'] = time() + VIDEO_UPLOAD_TTL;
    $payload['n'] = bin2hex(random_bytes(8));
    $enc = videoApiB64(json_encode($payload));
    return $enc . '.' . hash_hmac('sha256', $enc, videoApiSecret());
}

function videoApiReadToken(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], videoApiSecret()), $parts[1])) {
        return null;
    }
    $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
    return (is_array($data) && ($data['e'] ?? 0) >= time()) ? $data : null;
}

function videoApiPublishAt($raw, ?int $tid = null, ?int $uid = null): ?string {
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === 'null') {
        return null;
    }
    try {
        $dt = new DateTime($raw);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        apiError(422, 'Il campo "publish_at" non è una data valida (usa il formato ISO 8601).', $tid, $uid);
    }
}

// Valori comuni (titolo, descrizione, visibilità, feed, programmazione) da un payload JSON.
function videoApiFields(array $d, int $tid, int $uid, bool $titleRequired): array {
    $out = [];
    if (array_key_exists('title', $d)) {
        $t = trim((string) $d['title']);
        if ($t === '' || mb_strlen($t) > 150) {
            apiError(422, 'Il campo "title" è obbligatorio (max 150 caratteri).', $tid, $uid);
        }
        $out['title'] = $t;
    } elseif ($titleRequired) {
        apiError(422, 'Il campo "title" è obbligatorio.', $tid, $uid);
    }
    if (array_key_exists('description', $d)) {
        $out['description'] = trim((string) $d['description']);
    }
    if (array_key_exists('is_public', $d)) {
        $out['is_public'] = !empty($d['is_public']);
    }
    if (array_key_exists('include_in_feed', $d)) {
        $out['in_feed'] = !empty($d['include_in_feed']);
    }
    if (array_key_exists('publish_at', $d)) {
        $out['publish_at'] = videoApiPublishAt($d['publish_at'], $tid, $uid);
    }
    return $out;
}

function videoApiLoadAll(int $uid): array {
    $stmt = getDB()->prepare('SELECT * FROM videos WHERE user_id=? ORDER BY sort_order DESC, id DESC');
    $stmt->execute([$uid]);
    return $stmt->fetchAll();
}

function videoApiOne(int $uid, int $id, string $slug): ?array {
    foreach (videoApiLoadAll($uid) as $i => $v) {
        if ((int) $v['id'] === $id) {
            return videoSerialize($v, $slug, $i + 1);
        }
    }
    return null;
}

// Ora locale italiana "Y-m-d H:i:s" di un publish_at del database (fuso del server).
function videoApiQueueTime(?string $publishAt): ?string {
    if (!$publishAt) {
        return null;
    }
    $dt = new DateTime($publishAt, new DateTimeZone(date_default_timezone_get()));
    $dt->setTimezone(new DateTimeZone('Europe/Rome'));
    return $dt->format('Y-m-d H:i:s');
}

// Crea il record dal file già salvato e, se richiesto e il video è pubblico, lo accoda per Metricool.
function videoApiFinish(array $auth, string $rel, string $mime, int $size, array $meta): array {
    $id = videoCreateRecord($auth['user_id'], $auth['slug'], $rel, $mime, $size, $meta);
    $stmt = getDB()->prepare('SELECT * FROM videos WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $queueId = null;
    if (!empty($meta['queue_social']) && !empty($meta['is_public'])) {
        $queueId = videoEnqueueSocial($auth['user_id'], $auth['slug'], $row, videoApiQueueTime($row['publish_at'] ?? null));
    }
    return ['video' => videoApiOne($auth['user_id'], $id, $auth['slug']), 'queue_id' => $queueId];
}

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// ---- Ricezione del file (autenticata dal token firmato, non dal Bearer) ----
if ($action === 'put') {
    header('Content-Type: application/json; charset=UTF-8');
    $fail = function (int $code, string $msg) {
        http_response_code($code);
        exit(json_encode(['success' => false, 'error' => $msg]));
    };
    if ($method !== 'PUT') {
        $fail(405, 'Metodo non permesso, usa PUT.');
    }
    $token = (string) ($_GET['token'] ?? '');
    $data = videoApiReadToken($token);
    if (!$data) {
        $fail(401, 'Indirizzo di caricamento non valido o scaduto.');
    }
    $marker = sys_get_temp_dir() . '/vupl_' . hash('sha256', $token);
    $fh = @fopen($marker, 'x');
    if ($fh === false) {
        $fail(409, 'Indirizzo di caricamento già usato: richiedine uno nuovo.');
    }
    fclose($fh);

    @set_time_limit(0);
    $tmp = tempnam(sys_get_temp_dir(), 'vid_');
    $out = fopen($tmp, 'wb');
    $in = fopen('php://input', 'rb');
    $size = 0;
    while (!feof($in)) {
        $chunk = fread($in, 1048576);
        $size += strlen($chunk);
        if ($size > VIDEO_MAX_BYTES) {
            break;
        }
        fwrite($out, $chunk);
    }
    fclose($in);
    fclose($out);
    if ($size > VIDEO_MAX_BYTES) {
        @unlink($tmp);
        @unlink($marker);
        $fail(413, 'Il video supera il limite di ' . (VIDEO_MAX_BYTES / 1048576) . ' MB.');
    }
    $check = videoValidateFile($tmp, 'video.' . ($data['x'] ?? 'mp4'), $size);
    if (isset($check['error'])) {
        @unlink($tmp);
        @unlink($marker);
        $fail(422, $check['error']);
    }
    $stmt = getDB()->prepare('SELECT u.id user_id, u.slug FROM users u WHERE u.id = ? AND u.is_active = 1');
    $stmt->execute([(int) $data['u']]);
    $owner = $stmt->fetch();
    if (!$owner) {
        @unlink($tmp);
        @unlink($marker);
        $fail(404, 'Profilo non trovato.');
    }
    $rel = videoStoreFile($tmp, $owner['slug'], $check['ext'], false);
    if (!$rel) {
        @unlink($tmp);
        @unlink($marker);
        $fail(500, 'Salvataggio del file non riuscito.');
    }
    $res = videoApiFinish(['user_id' => (int) $owner['user_id'], 'slug' => $owner['slug']], $rel, $check['mime'], $size, $data['m'] ?? []);
    http_response_code(201);
    echo json_encode(['success' => true, 'data' => $res['video'], 'queue_id' => $res['queue_id']]);
    exit;
}

$auth = authenticateApiRequest();
$uid = $auth['user_id'];
$tid = $auth['token_id'];
$slug = $auth['slug'];

if ($action === 'list') {
    if ($method !== 'GET') {
        apiError(405, 'Metodo non permesso, usa GET.', $tid, $uid);
    }
    $out = [];
    foreach (videoApiLoadAll($uid) as $i => $v) {
        $out[] = videoSerialize($v, $slug, $i + 1);
    }
    apiRespond(200, ['success' => true, 'data' => $out], $tid, $uid);
}

if ($action === 'reorder') {
    if ($method !== 'POST') {
        apiError(405, 'Metodo non permesso, usa POST.', $tid, $uid);
    }
    $d = apiReadJsonBody();
    if (empty($d['order']) || !is_array($d['order'])) {
        apiError(422, 'Il campo "order" deve essere un elenco di ID video nell\'ordine desiderato (il primo compare in alto).', $tid, $uid);
    }
    videoApplyOrder($uid, $d['order']);
    $out = [];
    foreach (videoApiLoadAll($uid) as $i => $v) {
        $out[] = videoSerialize($v, $slug, $i + 1);
    }
    apiRespond(200, ['success' => true, 'data' => $out], $tid, $uid);
}

if ($action === 'request-upload') {
    if ($method !== 'POST') {
        apiError(405, 'Metodo non permesso, usa POST.', $tid, $uid);
    }
    $d = apiReadJsonBody();
    $f = videoApiFields($d, $tid, $uid, true);
    $ext = strtolower(pathinfo((string) ($d['filename'] ?? 'video.mp4'), PATHINFO_EXTENSION)) ?: 'mp4';
    if (!isset(VIDEO_ALLOWED_EXT[$ext])) {
        apiError(422, 'Formato non supportato: usa MP4, MOV o WebM (campo "filename").', $tid, $uid);
    }
    $meta = [
        'title' => $f['title'],
        'description' => $f['description'] ?? '',
        'is_public' => $f['is_public'] ?? true,
        'in_feed' => $f['in_feed'] ?? true,
        'publish_at' => $f['publish_at'] ?? null,
        'queue_social' => !empty($d['queue_social']),
    ];
    $token = videoApiMakeToken(['u' => $uid, 'x' => $ext, 'm' => $meta]);
    apiRespond(200, [
        'success' => true,
        'upload_url' => siteUrl('/api/v1/videos/put/' . $token),
        'method' => 'PUT',
        'expires_in_seconds' => VIDEO_UPLOAD_TTL,
        'max_bytes' => VIDEO_MAX_BYTES,
        'hint' => 'curl -sS -X PUT --data-binary @video.mp4 "<upload_url>" — la risposta contiene il video creato (video_url, thumbnail_url, url).',
    ], $tid, $uid);
}

if ($action === 'from-url') {
    if ($method !== 'POST') {
        apiError(405, 'Metodo non permesso, usa POST.', $tid, $uid);
    }
    $d = apiReadJsonBody();
    $f = videoApiFields($d, $tid, $uid, true);
    $url = trim((string) ($d['video_url'] ?? ''));
    if ($url === '') {
        apiError(422, 'Il campo "video_url" è obbligatorio.', $tid, $uid);
    }
    @set_time_limit(0);
    $dl = videoDownloadToTemp($url);
    if (isset($dl['error'])) {
        apiError(422, $dl['error'], $tid, $uid);
    }
    $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $check = videoValidateFile($dl['path'], 'video.' . (isset(VIDEO_ALLOWED_EXT[$ext]) ? $ext : 'mp4'));
    if (isset($check['error'])) {
        @unlink($dl['path']);
        apiError(422, $check['error'], $tid, $uid);
    }
    $size = (int) filesize($dl['path']);
    $rel = videoStoreFile($dl['path'], $slug, $check['ext'], false);
    if (!$rel) {
        @unlink($dl['path']);
        apiError(500, 'Salvataggio del file non riuscito.', $tid, $uid);
    }
    $meta = [
        'title' => $f['title'],
        'description' => $f['description'] ?? '',
        'is_public' => $f['is_public'] ?? true,
        'in_feed' => $f['in_feed'] ?? true,
        'publish_at' => $f['publish_at'] ?? null,
        'queue_social' => !empty($d['queue_social']),
    ];
    $res = videoApiFinish($auth, $rel, $check['mime'], $size, $meta);
    apiRespond(201, ['success' => true, 'message' => 'Video creato.', 'data' => $res['video'], 'queue_id' => $res['queue_id']], $tid, $uid);
}

if ($action === 'item') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = getDB()->prepare('SELECT * FROM videos WHERE id=? AND user_id=?');
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch();
    if (!$row) {
        apiError(404, 'Video non trovato.', $tid, $uid);
    }

    if ($method === 'GET') {
        apiRespond(200, ['success' => true, 'data' => videoApiOne($uid, $id, $slug)], $tid, $uid);
    }

    if ($method === 'DELETE') {
        videoDeleteFiles($row);
        getDB()->prepare('DELETE FROM videos WHERE id=? AND user_id=?')->execute([$id, $uid]);
        apiRespond(200, ['success' => true, 'message' => 'Video eliminato.'], $tid, $uid);
    }

    if ($method === 'PUT') {
        $d = apiReadJsonBody();
        $f = videoApiFields($d, $tid, $uid, false);
        $sets = [];
        $params = [];
        foreach (['title' => 'title', 'description' => 'description', 'publish_at' => 'publish_at'] as $k => $col) {
            if (array_key_exists($k, $f)) {
                $sets[] = "$col = ?";
                $params[] = ($k === 'description') ? ($f[$k] !== '' ? $f[$k] : null) : $f[$k];
            }
        }
        if (array_key_exists('is_public', $f)) {
            $sets[] = 'is_public = ?';
            $params[] = $f['is_public'] ? 1 : 0;
        }
        if (array_key_exists('in_feed', $f)) {
            $sets[] = 'in_feed = ?';
            $params[] = $f['in_feed'] ? 1 : 0;
        }
        if (!empty($d['thumbnail_url'])) {
            $turl = trim((string) $d['thumbnail_url']);
            if (!filter_var($turl, FILTER_VALIDATE_URL) || !isSafePublicUrl($turl)) {
                apiError(422, 'Il campo "thumbnail_url" non è un URL pubblico valido.', $tid, $uid);
            }
            $bytes = cinemaHttpGet($turl, 20);
            $newThumb = $bytes ? videoStoreThumbFromBytes($bytes, $slug) : null;
            if (!$newThumb) {
                apiError(422, 'Non è stato possibile scaricare "thumbnail_url".', $tid, $uid);
            }
            videoDeleteFile($row['thumb_path']);
            $sets[] = 'thumb_path = ?';
            $params[] = $newThumb;
        }
        if ($sets) {
            $params[] = $id;
            $params[] = $uid;
            getDB()->prepare('UPDATE videos SET ' . implode(', ', $sets) . ' WHERE id=? AND user_id=?')->execute($params);
        } elseif (!isset($d['position'])) {
            apiError(422, 'Nessun campo da modificare.', $tid, $uid);
        }
        if (isset($d['position'])) {
            $ids = array_map('intval', array_column(videoApiLoadAll($uid), 'id'));
            $ids = array_values(array_filter($ids, fn($x) => $x !== $id));
            array_splice($ids, max(0, min((int) $d['position'], count($ids) + 1) - 1), 0, [$id]);
            videoApplyOrder($uid, $ids);
        }
        apiRespond(200, ['success' => true, 'data' => videoApiOne($uid, $id, $slug)], $tid, $uid);
    }

    apiError(405, 'Metodo non permesso, usa GET, PUT o DELETE.', $tid, $uid);
}

apiError(404, 'Azione non trovata.', $tid, $uid);
