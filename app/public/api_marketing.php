<?php
/**
 * API Marketing > Pianificate (progetti con passi in sequenza). Stesso token Bearer di tutte le
 * altre API; ogni richiesta lavora solo sui progetti del profilo proprietario del token.
 * Le route arrivano da .htaccess come ?r=...:
 *   GET  /api/v1/marketing/projects                 elenco (?status=active|completed|archived|all, default: non archiviati)
 *   POST /api/v1/marketing/projects                 crea {title, description?, steps?: [{title, instructions?}], final?: {title, instructions?}}
 *   GET  /api/v1/marketing/projects/{id}            dettaglio con tutti i passi
 *   PUT  /api/v1/marketing/projects/{id}            {title?, description?, archived?: bool}
 *   POST /api/v1/marketing/projects/{id}/steps      aggiunge un passo {title, instructions?, final?: bool} (final = "Inserisci Finale")
 *   PUT  /api/v1/marketing/steps/{id}               {title?, instructions?}
 *   POST /api/v1/marketing/steps/{id}/complete      {result?} — solo se i passi precedenti sono completati
 * L'eliminazione di progetti e passi si fa solo dalla dashboard (non esposta alle IA).
 */
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/marketing.php';

$auth = authenticateApiRequest();
$userId = (int) $auth['user_id'];
$tokenId = (int) $auth['token_id'];
$method = $_SERVER['REQUEST_METHOD'];
$route = (string) ($_GET['r'] ?? '');
$id = (int) ($_GET['id'] ?? 0);

function mktFail(int $code, string $msg, int $tokenId, int $userId): void {
    apiError($code, $msg, $tokenId, $userId);
}
function mktBadMethod(string $allowed, int $tokenId, int $userId): void {
    apiError(405, "Metodo non permesso, usa $allowed.", $tokenId, $userId);
}
function mktProjectOr404(int $userId, int $id, int $tokenId): array {
    $p = $id > 0 ? marketingGetProject($userId, $id) : null;
    if (!$p) {
        apiError(404, 'Progetto non trovato o non appartiene a questo profilo.', $tokenId, $userId);
    }
    return $p;
}
// Passo con titolo/istruzioni da un array JSON; ritorna [titolo, istruzioni] o termina con 422.
function mktStepFields($raw, int $tokenId, int $userId): array {
    if (!is_array($raw) || !isset($raw['title']) || !is_string($raw['title'])) {
        apiError(422, 'Ogni passo richiede "title" (testo).', $tokenId, $userId);
    }
    $ins = $raw['instructions'] ?? null;
    if ($ins !== null && !is_string($ins)) {
        apiError(422, '"instructions" deve essere un testo.', $tokenId, $userId);
    }
    return [$raw['title'], $ins];
}

try {
    switch ($route) {
        case 'projects':
            if ($method === 'GET') {
                $status = (string) ($_GET['status'] ?? '');
                $all = marketingListProjects($userId, true);
                $items = [];
                foreach ($all as $p) {
                    if ($status === '' ? $p['status'] === 'archived' : ($status !== 'all' && $p['status'] !== $status)) {
                        continue;
                    }
                    $items[] = marketingSerializeProject($p);
                }
                apiRespond(200, ['success' => true, 'total' => count($items), 'items' => $items], $tokenId, $userId);
            }
            if ($method === 'POST') {
                $b = apiReadJsonBody();
                if (!isset($b['title']) || !is_string($b['title'])) {
                    mktFail(422, 'Il campo "title" è obbligatorio.', $tokenId, $userId);
                }
                $steps = $b['steps'] ?? [];
                if (!is_array($steps) || count($steps) > MARKETING_MAX_STEPS) {
                    mktFail(422, '"steps" deve essere un elenco di al massimo ' . MARKETING_MAX_STEPS . ' passi.', $tokenId, $userId);
                }
                // Si valida tutto PRIMA di scrivere, così un errore non lascia un progetto a metà.
                $parsed = [];
                foreach ($steps as $st) {
                    $parsed[] = mktStepFields($st, $tokenId, $userId);
                }
                $final = isset($b['final']) && $b['final'] !== null && $b['final'] !== false ? mktStepFields($b['final'], $tokenId, $userId) : null;
                $created = marketingCreateProject($userId, $b['title'], isset($b['description']) ? (string) $b['description'] : null, 'ai');
                if (isset($created['error'])) {
                    mktFail(422, $created['error'], $tokenId, $userId);
                }
                foreach ($parsed as [$t, $i]) {
                    $r = marketingAddStep($userId, $created['id'], $t, $i, false, 'ai');
                    if (isset($r['error'])) {
                        mktFail(422, $r['error'], $tokenId, $userId);
                    }
                }
                if ($final) {
                    $r = marketingAddStep($userId, $created['id'], $final[0], $final[1], true, 'ai');
                    if (isset($r['error'])) {
                        mktFail(422, $r['error'], $tokenId, $userId);
                    }
                }
                apiRespond(201, ['success' => true, 'data' => marketingSerializeProject(marketingGetProject($userId, $created['id']))], $tokenId, $userId);
            }
            mktBadMethod('GET o POST', $tokenId, $userId);
            break;

        case 'project':
            $p = mktProjectOr404($userId, $id, $tokenId);
            if ($method === 'GET') {
                apiRespond(200, ['success' => true, 'data' => marketingSerializeProject($p)], $tokenId, $userId);
            }
            if ($method === 'PUT') {
                $b = apiReadJsonBody();
                $fields = array_intersect_key($b, ['title' => 1, 'description' => 1, 'archived' => 1]);
                if (!$fields) {
                    mktFail(422, 'Nessun campo da aggiornare: usa title, description o archived.', $tokenId, $userId);
                }
                if (isset($fields['archived'])) {
                    $fields['archived'] = (bool) $fields['archived'];
                }
                $err = marketingUpdateProject($userId, $id, $fields);
                if ($err !== null) {
                    mktFail(422, $err, $tokenId, $userId);
                }
                apiRespond(200, ['success' => true, 'data' => marketingSerializeProject(marketingGetProject($userId, $id))], $tokenId, $userId);
            }
            mktBadMethod('GET o PUT', $tokenId, $userId);
            break;

        case 'project_steps':
            if ($method !== 'POST') {
                mktBadMethod('POST', $tokenId, $userId);
            }
            mktProjectOr404($userId, $id, $tokenId);
            $b = apiReadJsonBody();
            [$t, $i] = mktStepFields($b, $tokenId, $userId);
            $r = marketingAddStep($userId, $id, $t, $i, !empty($b['final']), 'ai');
            if (isset($r['error'])) {
                mktFail(422, $r['error'], $tokenId, $userId);
            }
            apiRespond(201, ['success' => true, 'data' => marketingSerializeProject(marketingGetProject($userId, $id))], $tokenId, $userId);
            break;

        case 'step':
            if ($method !== 'PUT') {
                mktBadMethod('PUT', $tokenId, $userId);
            }
            $step = $id > 0 ? marketingGetStep($userId, $id) : null;
            if (!$step) {
                mktFail(404, 'Passo non trovato o non appartiene a questo profilo.', $tokenId, $userId);
            }
            $b = apiReadJsonBody();
            $fields = array_intersect_key($b, ['title' => 1, 'instructions' => 1]);
            if (!$fields) {
                mktFail(422, 'Nessun campo da aggiornare: usa title o instructions.', $tokenId, $userId);
            }
            $err = marketingUpdateStep($userId, $id, $fields);
            if ($err !== null) {
                mktFail(422, $err, $tokenId, $userId);
            }
            apiRespond(200, ['success' => true, 'data' => marketingSerializeProject(marketingGetProject($userId, (int) $step['project_id']))], $tokenId, $userId);
            break;

        case 'step_complete':
            if ($method !== 'POST') {
                mktBadMethod('POST', $tokenId, $userId);
            }
            $step = $id > 0 ? marketingGetStep($userId, $id) : null;
            if (!$step) {
                mktFail(404, 'Passo non trovato o non appartiene a questo profilo.', $tokenId, $userId);
            }
            $b = apiReadJsonBody();
            $err = marketingCompleteStep($userId, $id, isset($b['result']) ? (string) $b['result'] : null, 'ai');
            if ($err !== null) {
                mktFail(409, $err, $tokenId, $userId);
            }
            apiRespond(200, ['success' => true, 'data' => marketingSerializeProject(marketingGetProject($userId, (int) $step['project_id']))], $tokenId, $userId);
            break;

        default:
            mktFail(404, 'Risorsa non trovata.', $tokenId, $userId);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '42S02') {
        mktFail(503, 'Il modulo Marketing non è ancora attivo su questo database: applicare la migrazione 2026_11_21_marketing_plans.sql.', $tokenId, $userId);
    }
    throw $e;
}
