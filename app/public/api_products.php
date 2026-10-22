<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/products.php';

// Catalogo prodotti via token Bearer (sola lettura + registro delle pubblicazioni):
//   GET  /api/v1/products/list          filtri: q, category, has_discount, in_stock, featured, only_unpublished,
//                                       not_published_since_days, post_type, random, page, per_page
//   GET  /api/v1/products/categories    percorsi di categoria con numero di prodotti
//   GET  /api/v1/products/{id}          dettaglio + storico pubblicazioni
//   POST /api/v1/products/{id}/publications   {post_type, published_at?, channels?, metricool_post_id?, note?}
// L'aggiornamento del catalogo dal feed si fa solo a mano da dashboard.

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$auth = authenticateApiRequest();
$uid = $auth['user_id'];
$tid = $auth['token_id'];

function productApiBool(string $key): ?bool {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }
    return in_array(strtolower((string) $_GET[$key]), ['1', 'true', 'yes', 'si'], true);
}

function productApiLoad(int $uid, int $id): ?array {
    $stmt = getDB()->prepare('SELECT p.*, (SELECT COUNT(*) FROM product_publications x WHERE x.product_id = p.id) AS times_published,
            (SELECT MAX(x.published_at) FROM product_publications x WHERE x.product_id = p.id) AS last_published_at
        FROM products p WHERE p.id = ? AND p.user_id = ?');
    $stmt->execute([$id, $uid]);
    return $stmt->fetch() ?: null;
}

function productApiPublications(int $productId): array {
    $stmt = getDB()->prepare('SELECT id, post_type, channels, published_at, metricool_post_id, note FROM product_publications WHERE product_id = ? ORDER BY published_at DESC, id DESC LIMIT 50');
    $stmt->execute([$productId]);
    return array_map(fn ($r) => [
        'id' => (int) $r['id'],
        'post_type' => $r['post_type'],
        'channels' => $r['channels'],
        'published_at' => apiFormatDateTimeRome($r['published_at']),
        'metricool_post_id' => $r['metricool_post_id'],
        'note' => $r['note'],
    ], $stmt->fetchAll());
}

if ($action === 'list') {
    if ($method !== 'GET') {
        apiError(405, 'Metodo non permesso, usa GET.', $tid, $uid);
    }
    $f = [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'category' => trim((string) ($_GET['category'] ?? '')),
        'featured' => productApiBool('featured'),
        'only_unpublished' => productApiBool('only_unpublished'),
        'random' => productApiBool('random'),
        'post_type' => trim((string) ($_GET['post_type'] ?? '')),
        'page' => (int) ($_GET['page'] ?? 1),
        'per_page' => (int) ($_GET['per_page'] ?? 25),
    ];
    if (($v = productApiBool('has_discount')) !== null) {
        $f['has_discount'] = $v;
    }
    // Di default solo i prodotti disponibili: in_stock=false per includere gli esauriti.
    $f['in_stock'] = productApiBool('in_stock') ?? true;
    if (isset($_GET['not_published_since_days']) && $_GET['not_published_since_days'] !== '') {
        $f['not_published_since_days'] = (int) $_GET['not_published_since_days'];
    }
    $res = productQuery($uid, $f);
    apiRespond(200, [
        'success' => true,
        'total' => $res['total'],
        'page' => $res['page'],
        'per_page' => $res['per_page'],
        'data' => array_map('productSerialize', $res['rows']),
    ], $tid, $uid);
}

if ($action === 'categories') {
    if ($method !== 'GET') {
        apiError(405, 'Metodo non permesso, usa GET.', $tid, $uid);
    }
    apiRespond(200, ['success' => true, 'data' => productCategories($uid)], $tid, $uid);
}

$id = (int) ($_GET['id'] ?? 0);
$product = $id > 0 ? productApiLoad($uid, $id) : null;
if (!$product) {
    apiError(404, 'Prodotto non trovato.', $tid, $uid);
}

if ($action === 'item') {
    if ($method !== 'GET') {
        apiError(405, 'Metodo non permesso, usa GET.', $tid, $uid);
    }
    $out = productSerialize($product);
    $out['publications'] = productApiPublications($id);
    apiRespond(200, ['success' => true, 'data' => $out], $tid, $uid);
}

if ($action === 'publications') {
    if ($method === 'GET') {
        apiRespond(200, ['success' => true, 'data' => productApiPublications($id)], $tid, $uid);
    }
    if ($method !== 'POST') {
        apiError(405, 'Metodo non permesso, usa GET o POST.', $tid, $uid);
    }
    $d = apiReadJsonBody();
    $type = trim((string) ($d['post_type'] ?? ''));
    if ($type === '' || mb_strlen($type) > 40) {
        apiError(422, 'Il campo "post_type" è obbligatorio (max 40 caratteri), es. special_price, novita, reel, story.', $tid, $uid);
    }
    $when = date('Y-m-d H:i:s');
    if (!empty($d['published_at'])) {
        try {
            $dt = new DateTime((string) $d['published_at']);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $when = $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            apiError(422, 'Il campo "published_at" non è una data ISO 8601 valida.', $tid, $uid);
        }
    }
    $ch = $d['channels'] ?? null;
    if (is_array($ch)) {
        $ch = implode(',', array_map('strval', $ch));
    }
    getDB()->prepare('INSERT INTO product_publications (product_id, user_id, post_type, channels, published_at, metricool_post_id, note) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $uid, $type, $ch !== null ? mb_substr((string) $ch, 0, 200) : null, $when,
            !empty($d['metricool_post_id']) ? mb_substr((string) $d['metricool_post_id'], 0, 80) : null,
            !empty($d['note']) ? mb_substr((string) $d['note'], 0, 300) : null]);
    apiRespond(201, ['success' => true, 'data' => productApiPublications($id)], $tid, $uid);
}

apiError(404, 'Azione non trovata.', $tid, $uid);
