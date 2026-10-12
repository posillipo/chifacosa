<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/spotify.php';

// Gestione "Brani/Band/Playlist/Album che amo" via token Bearer. Una sola risorsa per i quattro
// tipi: la rotta (.htaccess) passa kind e action. Stesse tabelle e stessa regola delle pagine
// dashboard_audio / dashboard_fan_*: un elemento appena aggiunto parte "Solo io" (is_public=0).
const API_FAVORITE_KINDS = [
    'track' => [
        'table' => 'favorite_tracks', 'spotify_col' => 'spotify_track_id', 'name_col' => 'track_name',
        'artist_col' => 'artist_name', 'image_col' => 'track_image', 'url_col' => 'spotify_url',
        'path' => '/brani/%d/scheda', 'list_path' => '/brani-che-amo', 'search' => 'spotifySearchTrack',
    ],
    'band' => [
        'table' => 'fan_favorite_bands', 'spotify_col' => 'spotify_artist_id', 'name_col' => 'spotify_artist_name',
        'artist_col' => null, 'image_col' => 'artist_image', 'url_col' => null,
        'path' => '/band-che-amo/%d', 'list_path' => '/band-che-amo', 'search' => 'spotifySearchArtist',
    ],
    'playlist' => [
        'table' => 'fan_favorite_playlists', 'spotify_col' => 'spotify_playlist_id', 'name_col' => 'playlist_name',
        'artist_col' => null, 'image_col' => 'playlist_image', 'url_col' => null,
        'path' => '/playlist-che-amo/%d', 'list_path' => '/playlist-che-amo', 'search' => 'spotifySearchPlaylist',
    ],
    'album' => [
        'table' => 'fan_favorite_albums', 'spotify_col' => 'spotify_album_id', 'name_col' => 'album_name',
        'artist_col' => 'album_artist_name', 'image_col' => 'album_image', 'url_col' => null,
        'path' => '/album-che-amo/%d', 'list_path' => '/album-che-amo', 'search' => 'spotifySearchAlbum',
    ],
];

function favSerialize(array $row, string $kind, array $cfg, string $slug): array {
    $uploaded = ($row['image_thumb_path'] ?? '') ?: ($row['image_path'] ?? '');
    $isPublic = (bool) ((int) ($row['is_public'] ?? 0));
    $scheduled = !empty($row['publish_at']) && strtotime($row['publish_at']) > time();
    return [
        'id' => (int) $row['id'],
        'kind' => $kind,
        'spotify_id' => $row[$cfg['spotify_col']],
        'name' => $row[$cfg['name_col']],
        'artist_name' => $cfg['artist_col'] ? ($row[$cfg['artist_col']] ?? null) : null,
        'image_url' => $uploaded ? siteUrl('/' . $uploaded) : (($row[$cfg['image_col']] ?? '') ?: null),
        'spotify_url' => $cfg['url_col'] ? ($row[$cfg['url_col']] ?? null) : null,
        'note' => $row['note'] ?? null,
        'status' => !$isPublic ? 'private' : ($scheduled ? 'scheduled' : 'published'),
        'is_public' => $isPublic,
        'include_in_feed' => (bool) ((int) ($row['in_feed'] ?? 1)),
        'publish_at' => apiFormatDateTimeRome($row['publish_at'] ?? null),
        'created_at' => apiFormatDateTimeRome($row['created_at'] ?? null),
        'url' => siteUrl('/' . $slug . sprintf($cfg['path'], (int) $row['id'])),
        'list_url' => siteUrl('/' . $slug . $cfg['list_path']),
    ];
}

function favParsePublishAt($raw, int $tokenId, int $userId): ?string {
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === 'null') {
        return null;
    }
    try {
        $dt = new DateTime($raw);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        apiError(422, 'Il campo "publish_at" non è una data valida (usa il formato ISO 8601).', $tokenId, $userId);
    }
}

$kind = (string) ($_GET['kind'] ?? '');
$action = (string) ($_GET['action'] ?? '');
if (!isset(API_FAVORITE_KINDS[$kind])) {
    apiError(400, 'Parametro "kind" non valido: usa track, band, playlist o album.');
}
$cfg = API_FAVORITE_KINDS[$kind];
$table = $cfg['table'];

$auth = authenticateApiRequest();
$uid = $auth['user_id'];
$tid = $auth['token_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'search') {
    if ($method !== 'GET') {
        apiError(405, 'Metodo non permesso, usa GET.', $tid, $uid);
    }
    $query = trim((string) ($_GET['query'] ?? ''));
    if ($query === '') {
        apiError(422, 'Il parametro "query" è obbligatorio.', $tid, $uid);
    }
    $results = ($cfg['search'])($query);
    $stmt = getDB()->prepare("SELECT {$cfg['spotify_col']} s FROM {$table} WHERE user_id = ?");
    $stmt->execute([$uid]);
    $already = array_column($stmt->fetchAll(), 's');
    foreach ($results as &$r) {
        $r['already_in_list'] = in_array($r['id'], $already, true);
    }
    unset($r);
    apiRespond(200, ['success' => true, 'data' => $results], $tid, $uid);
}

if ($action === 'list') {
    if ($method !== 'GET') {
        apiError(405, 'Metodo non permesso, usa GET.', $tid, $uid);
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $where = 'user_id = ?';
    $visibility = trim((string) ($_GET['visibility'] ?? ''));
    if ($visibility === 'public') {
        $where .= ' AND is_public = 1';
    } elseif ($visibility === 'private') {
        $where .= ' AND is_public = 0';
    }
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM {$table} WHERE {$where}");
    $stmt->execute([$uid]);
    $total = (int) $stmt->fetch()['c'];
    $stmt = getDB()->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY sort_order DESC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute([$uid]);
    apiRespond(200, [
        'success' => true,
        'data' => array_map(fn($r) => favSerialize($r, $kind, $cfg, $auth['slug']), $stmt->fetchAll()),
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
    ], $tid, $uid);
}

if ($action === 'add') {
    if ($method !== 'POST') {
        apiError(405, 'Metodo non permesso, usa POST.', $tid, $uid);
    }
    $d = apiReadJsonBody();
    $spotifyId = trim((string) ($d['spotify_id'] ?? ''));
    $name = trim((string) ($d['name'] ?? ''));
    if ($spotifyId === '' || $name === '') {
        apiError(422, 'I campi "spotify_id" e "name" sono obbligatori (usa i valori restituiti dalla ricerca).', $tid, $uid);
    }
    $image = trim((string) ($d['image_url'] ?? ''));
    if ($image !== '' && (!filter_var($image, FILTER_VALIDATE_URL) || !isSafePublicUrl($image))) {
        apiError(422, 'Il campo "image_url" non è un URL pubblico valido.', $tid, $uid);
    }
    $cols = ['user_id', $cfg['spotify_col'], $cfg['name_col'], $cfg['image_col'], 'note', 'is_public', 'in_feed', 'publish_at'];
    $vals = [
        $uid, $spotifyId, $name, $image ?: null,
        trim((string) ($d['note'] ?? '')) ?: null,
        !empty($d['is_public']) ? 1 : 0,
        array_key_exists('include_in_feed', $d) ? (!empty($d['include_in_feed']) ? 1 : 0) : 1,
        favParsePublishAt($d['publish_at'] ?? '', $tid, $uid),
    ];
    if ($cfg['artist_col']) {
        $cols[] = $cfg['artist_col'];
        $vals[] = trim((string) ($d['artist_name'] ?? '')) ?: null;
    }
    if ($cfg['url_col']) {
        $cols[] = $cfg['url_col'];
        $vals[] = trim((string) ($d['spotify_url'] ?? '')) ?: null;
    }
    $cols[] = 'sort_order';
    $placeholders = implode(',', array_fill(0, count($vals), '?'))
        . ", (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM {$table} WHERE user_id=?) t)";
    $vals[] = $uid;

    $exists = getDB()->prepare("SELECT id FROM {$table} WHERE user_id=? AND {$cfg['spotify_col']}=?");
    $exists->execute([$uid, $spotifyId]);
    $alreadyThere = (bool) $exists->fetch();

    getDB()->prepare("INSERT IGNORE INTO {$table} (" . implode(',', $cols) . ") VALUES ({$placeholders})")->execute($vals);

    $stmt = getDB()->prepare("SELECT * FROM {$table} WHERE user_id=? AND {$cfg['spotify_col']}=?");
    $stmt->execute([$uid, $spotifyId]);
    $row = $stmt->fetch();
    if (!$row) {
        apiError(500, 'Inserimento non riuscito.', $tid, $uid);
    }
    apiRespond($alreadyThere ? 200 : 201, [
        'success' => true,
        'message' => $alreadyThere ? 'Già presente in lista: nessuna modifica.' : 'Aggiunto alla lista.',
        'data' => favSerialize($row, $kind, $cfg, $auth['slug']),
    ], $tid, $uid);
}

if ($action === 'item') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        apiError(400, 'ID mancante o non valido.', $tid, $uid);
    }
    $stmt = getDB()->prepare("SELECT * FROM {$table} WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch();
    if (!$row) {
        apiError(404, 'Elemento non trovato.', $tid, $uid);
    }

    if ($method === 'GET') {
        apiRespond(200, ['success' => true, 'data' => favSerialize($row, $kind, $cfg, $auth['slug'])], $tid, $uid);
    }

    if ($method === 'PUT') {
        $d = apiReadJsonBody();
        $sets = [];
        $params = [];
        if (array_key_exists('note', $d)) {
            $sets[] = 'note = ?';
            $params[] = trim((string) $d['note']) ?: null;
        }
        if (array_key_exists('is_public', $d)) {
            $sets[] = 'is_public = ?';
            $params[] = !empty($d['is_public']) ? 1 : 0;
        }
        if (array_key_exists('include_in_feed', $d)) {
            $sets[] = 'in_feed = ?';
            $params[] = !empty($d['include_in_feed']) ? 1 : 0;
        }
        if (array_key_exists('publish_at', $d)) {
            $sets[] = 'publish_at = ?';
            $params[] = favParsePublishAt($d['publish_at'], $tid, $uid);
        }
        if (!$sets) {
            apiError(422, 'Nessun campo da modificare: usa note, is_public, include_in_feed o publish_at.', $tid, $uid);
        }
        $params[] = $id;
        $params[] = $uid;
        getDB()->prepare("UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')->execute($params);
        $stmt = getDB()->prepare("SELECT * FROM {$table} WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
        apiRespond(200, ['success' => true, 'data' => favSerialize($stmt->fetch(), $kind, $cfg, $auth['slug'])], $tid, $uid);
    }

    if ($method === 'DELETE') {
        deleteCoverFile($row['image_path'] ?? null);
        deleteCoverFile($row['image_thumb_path'] ?? null);
        getDB()->prepare("DELETE FROM {$table} WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        apiRespond(200, ['success' => true, 'message' => 'Rimosso dalla lista.'], $tid, $uid);
    }

    apiError(405, 'Metodo non permesso, usa GET, PUT o DELETE.', $tid, $uid);
}

apiError(404, 'Azione non trovata.', $tid, $uid);
