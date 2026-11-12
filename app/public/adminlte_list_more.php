<?php
require_once __DIR__ . '/../src/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Endpoint unico di scroll infinito per le pagine a elenco del tema AdminLTE (Blog, i 6 moduli
// Che Amo, Brani, Offerte, Servizi, Eventi) — vedi adminLteInfiniteScrollScript() in functions.php.
// $type sceglie sia la query (stessa forma e stesso ordinamento della query iniziale nel file
// pubblico corrispondente, solo con LIMIT/OFFSET) sia il renderer di riga da riusare.

$type = $_GET['type'] ?? '';
$slug = $_GET['slug'] ?? '';
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$pageSize = 20;

$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.dashboard_theme
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    echo json_encode(['html' => '', 'count' => 0]);
    exit;
}

$uid = (int) $artist['id'];
$db = getDB();
$html = '';
$count = 0;

if ($type === 'blog') {
    // Stesso filtro della pagina Blog (parola chiave + categorie), rivalidato qui sull'artista.
    [$filterSql, $filterParams] = blogFilterSql(blogFilterFromRequest($uid, $_GET));
    $stmt = $db->prepare('SELECT * FROM blog_posts WHERE user_id=? AND published_at <= NOW()' . $filterSql . ' ORDER BY published_at DESC LIMIT ? OFFSET ?');
    $bind = array_merge([$uid], $filterParams);
    foreach ($bind as $i => $v) {
        $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(count($bind) + 1, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(count($bind) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $html = renderAdminLteBlogRows($rows, $slug, $artist);
    $count = count($rows);
} elseif (isset(ADMINLTE_FAN_FAVORITE_KINDS[$type])) {
    $cfg = ADMINLTE_FAN_FAVORITE_KINDS[$type];
    $stmt = $db->prepare("SELECT * FROM {$cfg['table']} WHERE user_id=? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW()) ORDER BY sort_order DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $html = renderAdminLteFanFavoriteRows($rows, $slug, $type, $artist);
    $count = count($rows);
} elseif ($type === 'brani') {
    $stmt = $db->prepare('SELECT * FROM favorite_tracks WHERE user_id=? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW()) ORDER BY sort_order DESC, id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $html = renderAdminLteBraniRows($rows, $slug, $artist);
    $count = count($rows);
} elseif ($type === 'offerte') {
    $stmt = $db->prepare("SELECT * FROM special_offers WHERE user_id=? AND is_active = 1
        AND (valid_from IS NULL OR valid_from <= NOW()) AND (valid_until IS NULL OR valid_until >= NOW())
        ORDER BY sort_order DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $html = renderAdminLteOfferteRows($rows, $slug, $artist);
    $count = count($rows);
} elseif ($type === 'servizi') {
    $stmt = $db->prepare("SELECT sv.*, (SELECT COUNT(*) FROM service_photos WHERE service_id = sv.id) AS extra_photos
        FROM services sv WHERE sv.user_id=? AND sv.is_public = 1
        AND (sv.publish_at IS NULL OR sv.publish_at <= NOW()) ORDER BY sv.sort_order DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $html = renderAdminLteServiziRows($rows, $slug);
    $count = count($rows);
} elseif ($type === 'eventi') {
    // provincia (opzionale): stesso filtro di eventi.php?provincia=... (widget "Eventi per
    // provincia" della colonna destra) — lo scroll infinito deve rispettarlo, non ricadere
    // sull'elenco completo dalla seconda pagina in poi.
    $provinciaFilter = trim((string) ($_GET['provincia'] ?? ''));
    $sql = 'SELECT * FROM events WHERE user_id=? AND (event_date >= NOW() OR is_perpetual = 1) AND (publish_at IS NULL OR publish_at <= NOW())';
    if ($provinciaFilter !== '') {
        $sql .= ' AND provincia = ?';
    }
    $sql .= ' ORDER BY is_perpetual DESC, event_date ASC LIMIT ? OFFSET ?';
    $stmt = $db->prepare($sql);
    $i = 1;
    $stmt->bindValue($i++, $uid, PDO::PARAM_INT);
    if ($provinciaFilter !== '') {
        $stmt->bindValue($i++, $provinciaFilter, PDO::PARAM_STR);
    }
    $stmt->bindValue($i++, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $html = renderAdminLteEventiRows($rows, $slug, $artist);
    $count = count($rows);
}

echo json_encode(['html' => $html, 'count' => $count]);
