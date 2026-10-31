<?php
/**
 * GET /api/v1/social-queue/list
 * Registro delle pubblicazioni social del profilo autenticato. SOLA LETTURA: a differenza di
 * /social-queue/pending NON marca nulla come "processing".
 *
 * Filtri (tutti opzionali, combinabili):
 *   source_type  tipo dell'elemento sorgente (es. product, album, video, track, event, che_amo, blog...)
 *   source_id    id dell'elemento sorgente
 *   scope        announcement|reminder|result|new|promo|release|reprise
 *   outcome      pending|processing|scheduled|done|failed|skipped (anche più valori separati da virgola)
 *   from, to     intervallo su publication_date (ISO 8601 o "YYYY-MM-DD"); per le righe senza data
 *                programmata si usa la data di elaborazione e, in mancanza, quella di creazione
 *   limit        1-200 (default 50)
 *   offset       paginazione
 */
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/metricool_queue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Metodo non permesso, usa GET.');
}

$auth = authenticateApiRequest();
$userId = (int) $auth['user_id'];

$where = ['q.user_id = ?'];
$params = [$userId];

if (!empty($_GET['source_type'])) {
    $where[] = 'q.content_type = ?';
    $params[] = (string) $_GET['source_type'];
}
if (isset($_GET['source_id']) && $_GET['source_id'] !== '') {
    $where[] = 'q.content_id = ?';
    $params[] = (int) $_GET['source_id'];
}
if (!empty($_GET['scope'])) {
    if (!in_array($_GET['scope'], SOCIAL_REGISTRY_SCOPES, true)) {
        apiError(422, 'scope deve essere uno tra: ' . implode(', ', SOCIAL_REGISTRY_SCOPES) . '.', $auth['token_id'], $userId);
    }
    $where[] = 'q.scope = ?';
    $params[] = $_GET['scope'];
}
if (!empty($_GET['outcome'])) {
    $outcomes = array_filter(array_map('trim', explode(',', (string) $_GET['outcome'])));
    foreach ($outcomes as $o) {
        if (!in_array($o, SOCIAL_REGISTRY_STATUSES, true)) {
            apiError(422, 'outcome deve essere tra: ' . implode(', ', SOCIAL_REGISTRY_STATUSES) . '.', $auth['token_id'], $userId);
        }
    }
    if ($outcomes) {
        $where[] = 'q.status IN (' . implode(',', array_fill(0, count($outcomes), '?')) . ')';
        array_push($params, ...$outcomes);
    }
}

$dateExpr = 'COALESCE(q.scheduled_for, q.processed_at, q.created_at)';
foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
    if (!empty($_GET[$key])) {
        try {
            $dt = new DateTime((string) $_GET[$key]);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (Exception $e) {
            apiError(422, "Il filtro \"$key\" non è una data valida (usa ISO 8601 o YYYY-MM-DD).", $auth['token_id'], $userId);
        }
        // "to" con sola data ("2026-10-31") include tutta la giornata.
        $isDateOnly = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET[$key]);
        if ($key === 'to' && $isDateOnly) {
            $dt->setTime(23, 59, 59);
        }
        $where[] = "$dateExpr $op ?";
        $params[] = $dt->format('Y-m-d H:i:s');
    }
}

$limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $sql = 'FROM social_queue q WHERE ' . implode(' AND ', $where);
    $cnt = getDB()->prepare('SELECT COUNT(*) ' . $sql);
    $cnt->execute($params);
    $total = (int) $cnt->fetchColumn();

    $stmt = getDB()->prepare("SELECT q.* $sql ORDER BY $dateExpr DESC, q.id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Filtro su "scope" senza che la migrazione 2026_10_31 sia stata applicata.
    if ($e->getCode() === '42S22') {
        apiError(503, 'Il registro non è ancora attivo su questo database: applicare la migrazione 2026_10_31_social_queue_registry.sql.', $auth['token_id'], $userId);
    }
    throw $e;
}

apiRespond(200, [
    'success' => true,
    'total'   => $total,
    'limit'   => $limit,
    'offset'  => $offset,
    'items'   => array_map('socialRegistrySerialize', $rows),
], $auth['token_id'], $userId);
