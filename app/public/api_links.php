<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/geocoding.php';

// Modulo LINK via token Bearer: stesse tabelle e stesse regole di dashboard_links.php (link,
// separatori, mappe, copertina, ordine, attivo/nascosto, icona sito web, sezioni del sito).
// I link di tipo "film" sono gestiti dalla sincronizzazione Cinema: qui si possono solo
// riordinare, attivare/disattivare ed eliminare.

function linkSerialize(array $l, int $position, array $socialIds, string $slug): array {
    $type = $l['link_type'];
    return [
        'id' => (int) $l['id'],
        'type' => $type,
        'position' => $position,
        'label' => $l['label'],
        'url' => $type === 'link' || $type === 'film' ? $l['url'] : null,
        'cover_image_url' => !empty($l['cover_path']) ? siteUrl('/' . $l['cover_path']) : null,
        'is_active' => (bool) ((int) $l['is_active']),
        'is_website_icon' => (bool) ((int) $l['is_website_icon']),
        'placement' => $type === 'link' ? (isset($socialIds[(int) $l['id']]) ? 'social_icon' : 'button') : null,
        'lat' => $l['map_lat'] !== null ? (float) $l['map_lat'] : null,
        'lng' => $l['map_lng'] !== null ? (float) $l['map_lng'] : null,
        'click_count' => (int) $l['click_count'],
        'is_section_link' => str_starts_with((string) ($l['external_ref'] ?? ''), 'section_'),
        'page_url' => siteUrl('/' . $slug),
    ];
}

function linkLoadAll(int $userId): array {
    $stmt = getDB()->prepare('SELECT * FROM links WHERE user_id=? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function linkSerializeAll(array $links, string $slug): array {
    [$social] = splitSocialAndActionLinks($links);
    $socialIds = [];
    foreach ($social as $s) {
        $socialIds[(int) $s['id']] = true;
    }
    $out = [];
    foreach ($links as $i => $l) {
        $out[] = linkSerialize($l, $i + 1, $socialIds, $slug);
    }
    return $out;
}

// Rinumera sort_order da 1: gli id indicati vanno per primi nell'ordine dato, gli altri restano
// in coda nel loro ordine attuale. Ignora id duplicati o che non appartengono al profilo.
function linkApplyOrder(int $userId, array $orderedIds): void {
    $current = array_map('intval', array_column(linkLoadAll($userId), 'id'));
    $valid = array_flip($current);
    $final = [];
    foreach ($orderedIds as $id) {
        $id = (int) $id;
        if (isset($valid[$id]) && !in_array($id, $final, true)) {
            $final[] = $id;
        }
    }
    foreach ($current as $id) {
        if (!in_array($id, $final, true)) {
            $final[] = $id;
        }
    }
    $db = getDB();
    $db->beginTransaction();
    $upd = $db->prepare('UPDATE links SET sort_order=? WHERE id=? AND user_id=?');
    foreach ($final as $i => $id) {
        $upd->execute([$i + 1, $id, $userId]);
    }
    $db->commit();
}

function linkMoveToPosition(int $userId, int $id, int $position): void {
    $ids = array_map('intval', array_column(linkLoadAll($userId), 'id'));
    $ids = array_values(array_filter($ids, fn($x) => $x !== $id));
    $position = max(1, min($position, count($ids) + 1));
    array_splice($ids, $position - 1, 0, [$id]);
    linkApplyOrder($userId, $ids);
}

function linkValidUrl(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https', 'mailto'], true);
}

// Scarica la copertina da URL pubblico e ritorna il percorso salvato (come handleCoverUpload()).
function linkDownloadCover(string $url, string $slug, int $tid, int $uid): string {
    if (!filter_var($url, FILTER_VALIDATE_URL) || !isSafePublicUrl($url)) {
        apiError(422, 'Il campo "cover_image_url" non è un URL pubblico valido.', $tid, $uid);
    }
    $d = downloadImageFromUrlAsCover($url, $slug);
    if ($d === null) {
        apiError(422, 'Non è stato possibile scaricare "cover_image_url".', $tid, $uid);
    }
    deleteCoverFile($d['thumb_path'] ?? null);
    return $d['image_path'];
}

function linkNumber($v): ?float {
    $v = str_replace(',', '.', trim((string) $v));
    return $v !== '' && is_numeric($v) ? (float) $v : null;
}

$action = (string) ($_GET['action'] ?? '');
$auth = authenticateApiRequest();
$uid = $auth['user_id'];
$tid = $auth['token_id'];
$slug = $auth['slug'];
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list') {
    if ($method !== 'GET') {
        apiError(405, 'Metodo non permesso, usa GET.', $tid, $uid);
    }
    apiRespond(200, ['success' => true, 'data' => linkSerializeAll(linkLoadAll($uid), $slug)], $tid, $uid);
}

if ($action === 'reorder') {
    if ($method !== 'POST') {
        apiError(405, 'Metodo non permesso, usa POST.', $tid, $uid);
    }
    $d = apiReadJsonBody();
    if (!isset($d['order']) || !is_array($d['order']) || !$d['order']) {
        apiError(422, 'Il campo "order" deve essere un elenco di ID link nell\'ordine desiderato.', $tid, $uid);
    }
    linkApplyOrder($uid, $d['order']);
    apiRespond(200, ['success' => true, 'data' => linkSerializeAll(linkLoadAll($uid), $slug)], $tid, $uid);
}

if ($action === 'sync-sections') {
    if ($method !== 'POST') {
        apiError(405, 'Metodo non permesso, usa POST.', $tid, $uid);
    }
    $stmt = getDB()->prepare('SELECT u.*, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id
        FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
    $stmt->execute([$uid]);
    $profileRow = $stmt->fetch();
    $result = syncSectionLinksForProfile($profileRow);
    if (!$result['ok']) {
        apiError(422, $result['error'], $tid, $uid);
    }
    apiRespond(200, [
        'success' => true,
        'added' => $result['added'], 'updated' => $result['updated'], 'removed' => $result['removed'],
        'data' => linkSerializeAll(linkLoadAll($uid), $slug),
    ], $tid, $uid);
}

if ($action === 'create') {
    if ($method !== 'POST') {
        apiError(405, 'Metodo non permesso, usa POST.', $tid, $uid);
    }
    $d = apiReadJsonBody();
    $type = (string) ($d['type'] ?? 'link');
    if (!in_array($type, ['link', 'divider', 'map'], true)) {
        apiError(422, 'Il campo "type" deve essere link, divider o map.', $tid, $uid);
    }
    $label = trim((string) ($d['label'] ?? ''));
    if (mb_strlen($label) > 120) {
        apiError(422, 'Il campo "label" supera i 120 caratteri.', $tid, $uid);
    }
    $url = '';
    $cover = null;
    $isWebsite = 0;
    $lat = $lng = null;

    if ($type === 'link') {
        $url = trim((string) ($d['url'] ?? ''));
        if ($label === '' || !linkValidUrl($url)) {
            apiError(422, 'Per un link servono "label" e un "url" valido (http, https o mailto).', $tid, $uid);
        }
        $isWebsite = !empty($d['is_website_icon']) ? 1 : 0;
        if (!empty($d['cover_image_url'])) {
            $cover = linkDownloadCover(trim((string) $d['cover_image_url']), $slug, $tid, $uid);
        }
    } elseif ($type === 'divider') {
        if ($label === '') {
            apiError(422, 'Per un separatore serve "label" (il titolo).', $tid, $uid);
        }
    } else {
        $lat = linkNumber($d['lat'] ?? '');
        $lng = linkNumber($d['lng'] ?? '');
        $address = trim((string) ($d['address'] ?? ''));
        if ($lat !== null && $lng !== null) {
            $geoName = null;
        } elseif ($address !== '') {
            $geo = geocodeAddress($address);
            if (!$geo) {
                apiError(422, 'Indirizzo non trovato: riprova più preciso o passa "lat" e "lng".', $tid, $uid);
            }
            $lat = $geo['lat'];
            $lng = $geo['lng'];
            $geoName = $geo['display_name'];
        } else {
            apiError(422, 'Per una mappa servono "address" oppure "lat" e "lng".', $tid, $uid);
        }
        if ($label === '') {
            $label = $geoName ?: (number_format($lat, 5) . ', ' . number_format($lng, 5));
        }
    }

    $stmt = getDB()->prepare('INSERT INTO links (user_id, label, url, is_website_icon, cover_path, link_type, map_lat, map_lng, sort_order)
        VALUES (?,?,?,?,?,?,?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM links WHERE user_id=?) t))');
    $stmt->execute([$uid, $label, $url, $isWebsite, $cover, $type, $lat, $lng, $uid]);
    $newId = (int) getDB()->lastInsertId();
    if (isset($d['position'])) {
        linkMoveToPosition($uid, $newId, (int) $d['position']);
    }
    $all = linkSerializeAll(linkLoadAll($uid), $slug);
    $created = current(array_filter($all, fn($x) => $x['id'] === $newId));
    apiRespond(201, ['success' => true, 'message' => 'Creato.', 'data' => $created], $tid, $uid);
}

if ($action === 'item') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = getDB()->prepare('SELECT * FROM links WHERE id=? AND user_id=?');
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch();
    if (!$row) {
        apiError(404, 'Link non trovato.', $tid, $uid);
    }
    $find = function () use ($uid, $slug, $id) {
        $all = linkSerializeAll(linkLoadAll($uid), $slug);
        return current(array_filter($all, fn($x) => $x['id'] === $id));
    };

    if ($method === 'GET') {
        apiRespond(200, ['success' => true, 'data' => $find()], $tid, $uid);
    }

    if ($method === 'DELETE') {
        deleteCoverFile($row['cover_path'] ?? null);
        getDB()->prepare('DELETE FROM links WHERE id=? AND user_id=?')->execute([$id, $uid]);
        apiRespond(200, ['success' => true, 'message' => 'Eliminato.'], $tid, $uid);
    }

    if ($method === 'PUT') {
        $d = apiReadJsonBody();
        $type = $row['link_type'];
        $sets = [];
        $params = [];
        $editable = $type !== 'film';

        if (array_key_exists('is_active', $d)) {
            $sets[] = 'is_active = ?';
            $params[] = !empty($d['is_active']) ? 1 : 0;
        }
        if ($editable && array_key_exists('label', $d)) {
            $label = trim((string) $d['label']);
            if ($label === '' || mb_strlen($label) > 120) {
                apiError(422, 'Il campo "label" è obbligatorio (max 120 caratteri).', $tid, $uid);
            }
            $sets[] = 'label = ?';
            $params[] = $label;
        }
        if ($type === 'link') {
            if (array_key_exists('url', $d)) {
                $url = trim((string) $d['url']);
                if (!linkValidUrl($url)) {
                    apiError(422, 'Il campo "url" non è valido (http, https o mailto).', $tid, $uid);
                }
                $sets[] = 'url = ?';
                $params[] = $url;
            }
            if (array_key_exists('is_website_icon', $d)) {
                $sets[] = 'is_website_icon = ?';
                $params[] = !empty($d['is_website_icon']) ? 1 : 0;
            }
            if (!empty($d['remove_cover'])) {
                deleteCoverFile($row['cover_path'] ?? null);
                $sets[] = 'cover_path = NULL';
            } elseif (!empty($d['cover_image_url'])) {
                $newCover = linkDownloadCover(trim((string) $d['cover_image_url']), $slug, $tid, $uid);
                deleteCoverFile($row['cover_path'] ?? null);
                $sets[] = 'cover_path = ?';
                $params[] = $newCover;
            }
        }
        if ($type === 'map' && (array_key_exists('lat', $d) || array_key_exists('lng', $d) || !empty($d['address']))) {
            $lat = linkNumber($d['lat'] ?? '');
            $lng = linkNumber($d['lng'] ?? '');
            if ($lat === null || $lng === null) {
                $geo = !empty($d['address']) ? geocodeAddress(trim((string) $d['address'])) : null;
                if (!$geo) {
                    apiError(422, 'Indirizzo non trovato o coordinate incomplete: passa "address" oppure "lat" e "lng".', $tid, $uid);
                }
                $lat = $geo['lat'];
                $lng = $geo['lng'];
            }
            $sets[] = 'map_lat = ?';
            $params[] = $lat;
            $sets[] = 'map_lng = ?';
            $params[] = $lng;
        }
        if ($sets) {
            $params[] = $id;
            $params[] = $uid;
            getDB()->prepare('UPDATE links SET ' . implode(', ', $sets) . ' WHERE id=? AND user_id=?')->execute($params);
        } elseif (!isset($d['position'])) {
            apiError(422, 'Nessun campo da modificare.', $tid, $uid);
        }
        if (isset($d['position'])) {
            linkMoveToPosition($uid, $id, (int) $d['position']);
        }
        apiRespond(200, ['success' => true, 'data' => $find()], $tid, $uid);
    }

    apiError(405, 'Metodo non permesso, usa GET, PUT o DELETE.', $tid, $uid);
}

apiError(404, 'Azione non trovata.', $tid, $uid);
