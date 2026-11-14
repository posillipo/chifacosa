<?php
/**
 * API Marketing > Pianificate. Un PROGETTO è un modello (passi + istruzioni); ogni volta che viene usato
 * nasce un'ESECUZIONE con la sua cronologia (chi, quando, cosa è stato fatto). Stesso token Bearer di tutte
 * le altre API; ogni richiesta lavora solo sui progetti del profilo proprietario del token.
 *   GET  /api/v1/marketing/projects                  elenco (?status=active|inactive|archived|all, default: attivi non archiviati)
 *   POST /api/v1/marketing/projects                  crea {title, description?, steps?: [{title, instructions?}], final?: {title, instructions?}}
 *   GET  /api/v1/marketing/projects/{id}             dettaglio: passi, esecuzione in corso, ultima esecuzione
 *   PUT  /api/v1/marketing/projects/{id}             {title?, description?, archived?: bool, active?: bool}
 *   POST /api/v1/marketing/projects/{id}/steps       aggiunge un passo {title, instructions?, final?: bool}
 *   POST /api/v1/marketing/projects/{id}/runs        avvia l'esecuzione (o riprende quella in corso)
 *   GET  /api/v1/marketing/projects/{id}/runs        cronologia delle esecuzioni (?limit=)
 *   POST /api/v1/marketing/projects/{id}/notes       nota nell'esecuzione in corso {text, step_id?}
 *   POST /api/v1/marketing/projects/{id}/interrupt   interrompe l'esecuzione in corso {reason?}
 *   GET  /api/v1/marketing/runs/{id}                 esecuzione con cronologia completa
 *   PUT  /api/v1/marketing/steps/{id}                {title?, instructions?}
 *   POST /api/v1/marketing/steps/{id}/complete       {result?} completa il passo nell'esecuzione in corso, solo se i precedenti sono completati
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
                if (!in_array($status, ['', 'active', 'inactive', 'archived', 'all'], true)) {
                    mktFail(422, 'status deve essere active, inactive, archived o all.', $tokenId, $userId);
                }
                $items = [];
                foreach (marketingListProjects($userId, true) as $p) {
                    $isArch = $p['status'] === 'archived';
                    $keep = match ($status) {
                        '', 'active' => !$isArch && $p['is_active'],
                        'inactive' => !$isArch && !$p['is_active'],
                        'archived' => $isArch,
                        default => true,
                    };
                    if ($keep) {
                        $items[] = marketingSerializeProject($p);
                    }
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
                $fields = array_intersect_key($b, ['title' => 1, 'description' => 1, 'archived' => 1, 'active' => 1]);
                if (!$fields) {
                    mktFail(422, 'Nessun campo da aggiornare: usa title, description, archived o active.', $tokenId, $userId);
                }
                foreach (['archived', 'active'] as $flag) {
                    if (isset($fields[$flag])) {
                        $fields[$flag] = (bool) $fields[$flag];
                    }
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
            $note = isset($b['result']) ? (string) $b['result'] : (isset($b['note']) ? (string) $b['note'] : null);
            $runBefore = marketingOpenRun($userId, (int) $step['project_id']);
            $err = marketingCompleteStep($userId, $id, $note, 'ai');
            if ($err !== null) {
                mktFail(409, $err, $tokenId, $userId);
            }
            apiRespond(200, ['success' => true, 'data' => marketingSerializeRun(marketingGetRun($userId, (int) $runBefore['id']))], $tokenId, $userId);
            break;

        case 'project_runs':
            mktProjectOr404($userId, $id, $tokenId);
            if ($method === 'GET') {
                $stepsNow = count(marketingGetProject($userId, $id)['steps']);
                $runs = array_map(fn ($r) => marketingSerializeRunSummary($r, $stepsNow), marketingListRuns($userId, $id, (int) ($_GET['limit'] ?? 20)));
                apiRespond(200, ['success' => true, 'total' => count($runs), 'items' => $runs], $tokenId, $userId);
            }
            if ($method === 'POST') {
                $r = marketingStartRun($userId, $id, 'ai');
                if (isset($r['error'])) {
                    mktFail(409, $r['error'], $tokenId, $userId);
                }
                $run = marketingGetRun($userId, $r['run_id']);
                apiRespond($r['resumed'] ? 200 : 201, ['success' => true, 'resumed' => $r['resumed'], 'data' => marketingSerializeRun($run)], $tokenId, $userId);
            }
            mktBadMethod('GET o POST', $tokenId, $userId);
            break;

        case 'project_notes':
            if ($method !== 'POST') {
                mktBadMethod('POST', $tokenId, $userId);
            }
            mktProjectOr404($userId, $id, $tokenId);
            $b = apiReadJsonBody();
            if (!isset($b['text']) || !is_string($b['text'])) {
                mktFail(422, 'Il campo "text" è obbligatorio.', $tokenId, $userId);
            }
            $err = marketingAddNote($userId, $id, $b['text'], isset($b['step_id']) ? (int) $b['step_id'] : null, 'ai');
            if ($err !== null) {
                mktFail(409, $err, $tokenId, $userId);
            }
            $open = marketingOpenRun($userId, $id);
            apiRespond(201, ['success' => true, 'data' => marketingSerializeRun(marketingGetRun($userId, (int) $open['id']))], $tokenId, $userId);
            break;

        case 'project_interrupt':
            if ($method !== 'POST') {
                mktBadMethod('POST', $tokenId, $userId);
            }
            mktProjectOr404($userId, $id, $tokenId);
            $b = apiReadJsonBody();
            $open = marketingOpenRun($userId, $id);
            $err = marketingInterruptRun($userId, $id, isset($b['reason']) ? (string) $b['reason'] : null, 'ai');
            if ($err !== null) {
                mktFail(409, $err, $tokenId, $userId);
            }
            apiRespond(200, ['success' => true, 'data' => marketingSerializeRun(marketingGetRun($userId, (int) $open['id']))], $tokenId, $userId);
            break;

        case 'run':
            if ($method !== 'GET') {
                mktBadMethod('GET', $tokenId, $userId);
            }
            $run = $id > 0 ? marketingGetRun($userId, $id) : null;
            if (!$run) {
                mktFail(404, 'Esecuzione non trovata o non appartiene a questo profilo.', $tokenId, $userId);
            }
            apiRespond(200, ['success' => true, 'data' => marketingSerializeRun($run)], $tokenId, $userId);
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
