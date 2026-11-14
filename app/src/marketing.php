<?php
// Modulo Marketing > Pianificate.
//
// PROGETTO = modello riutilizzabile: una serie ordinata di PASSI (Passo 1, Passo 2, ... e Finale), ognuno
// con titolo e istruzioni. Ogni volta che il progetto viene USATO (da una persona con "Esegui ora" o da
// un'IA via MCP) nasce un'ESECUZIONE con la sua CRONOLOGIA: chi (Utente/IA), quando, cosa è stato fatto
// (note e passi completati). I passi di un'esecuzione si completano IN ORDINE; completando il Finale
// l'esecuzione è conclusa e il progetto può essere riusato. Un progetto ha l'interruttore Attivo: se è
// spento non si possono avviare esecuzioni.
//
// Ogni funzione verifica che progetto/passo/esecuzione appartengano al profilo ($userId): nessun accesso
// ad altri profili. Usato da dashboard_marketing.php e da api_marketing.php (quindi dall'MCP).

const MARKETING_MAX_PROJECTS = 100;
const MARKETING_MAX_STEPS = 50;
const MARKETING_TEXT_MAX = 20000;

function marketingActor(string $by): string {
    return $by === 'ai' ? 'ai' : 'user';
}

function marketingText(?string $t): ?string {
    $t = $t !== null ? trim($t) : '';
    return $t !== '' ? mb_substr($t, 0, MARKETING_TEXT_MAX) : null;
}

// "Passo 2" / "Finale" per il passo $stepId dell'elenco ordinato $steps.
function marketingStepLabel(array $steps, int $stepId): string {
    $n = 0;
    foreach ($steps as $s) {
        if (!(int) $s['is_final']) {
            $n++;
        }
        if ((int) $s['id'] === $stepId) {
            return (int) $s['is_final'] ? 'Finale' : 'Passo ' . $n;
        }
    }
    return 'Passo';
}

// ---------------------------------------------------------------- progetti (modello)

// Progetto con i suoi passi (ordinati), o null se non esiste / non è del profilo.
function marketingGetProject(int $userId, int $projectId): ?array {
    $stmt = getDB()->prepare('SELECT * FROM marketing_projects WHERE id=? AND user_id=?');
    $stmt->execute([$projectId, $userId]);
    $p = $stmt->fetch();
    if (!$p) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM marketing_steps WHERE project_id=? AND user_id=? ORDER BY position ASC, id ASC');
    $stmt->execute([$projectId, $userId]);
    $p['steps'] = $stmt->fetchAll();
    $p['is_active'] = (int) ($p['is_active'] ?? 1);
    return $p;
}

// Tutti i progetti del profilo (più recenti per primi); $includeArchived=false li nasconde.
function marketingListProjects(int $userId, bool $includeArchived = false): array {
    $stmt = getDB()->prepare('SELECT id FROM marketing_projects WHERE user_id=?' . ($includeArchived ? '' : " AND status <> 'archived'") . ' ORDER BY id DESC');
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        if ($p = marketingGetProject($userId, (int) $r['id'])) {
            $out[] = $p;
        }
    }
    return $out;
}

// Crea un progetto. Ritorna ['id'=>n] oppure ['error'=>testo].
function marketingCreateProject(int $userId, string $title, ?string $description, string $by): array {
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 150) {
        return ['error' => 'Il titolo è obbligatorio (massimo 150 caratteri).'];
    }
    $stmt = getDB()->prepare('SELECT COUNT(*) FROM marketing_projects WHERE user_id=?');
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() >= MARKETING_MAX_PROJECTS) {
        return ['error' => 'Hai raggiunto il numero massimo di progetti (' . MARKETING_MAX_PROJECTS . ').'];
    }
    getDB()->prepare('INSERT INTO marketing_projects (user_id, title, description, created_by) VALUES (?,?,?,?)')
        ->execute([$userId, $title, marketingText($description), marketingActor($by)]);
    return ['id' => (int) getDB()->lastInsertId()];
}

// Campi accettati: title, description, archived (bool), active (bool: interruttore Attivo).
function marketingUpdateProject(int $userId, int $projectId, array $fields): ?string {
    if (!marketingGetProject($userId, $projectId)) {
        return 'Progetto non trovato.';
    }
    $sets = [];
    $params = [];
    if (array_key_exists('title', $fields)) {
        $t = trim((string) $fields['title']);
        if ($t === '' || mb_strlen($t) > 150) {
            return 'Il titolo è obbligatorio (massimo 150 caratteri).';
        }
        $sets[] = 'title=?';
        $params[] = $t;
    }
    if (array_key_exists('description', $fields)) {
        $sets[] = 'description=?';
        $params[] = marketingText((string) $fields['description']);
    }
    if (array_key_exists('active', $fields)) {
        $sets[] = 'is_active=?';
        $params[] = $fields['active'] ? 1 : 0;
    }
    if (array_key_exists('archived', $fields)) {
        $sets[] = 'status=?';
        $params[] = $fields['archived'] ? 'archived' : 'active';
        if ($fields['archived']) {
            // Un progetto archiviato non ha esecuzioni aperte.
            marketingInterruptRun($userId, $projectId, 'Progetto archiviato.', 'user');
        }
    }
    if ($sets) {
        $params[] = $projectId;
        $params[] = $userId;
        getDB()->prepare('UPDATE marketing_projects SET ' . implode(', ', $sets) . ' WHERE id=? AND user_id=?')->execute($params);
    }
    return null;
}

function marketingDeleteProject(int $userId, int $projectId): bool {
    $stmt = getDB()->prepare('DELETE FROM marketing_projects WHERE id=? AND user_id=?');
    $stmt->execute([$projectId, $userId]);
    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------- passi (modello)

// Aggiunge un passo in fondo; con $isFinal aggiunge il Finale (dopo il quale non si aggiungono altri passi).
// Ritorna ['id'=>n] oppure ['error'=>testo].
function marketingAddStep(int $userId, int $projectId, string $title, ?string $instructions, bool $isFinal, string $by): array {
    $p = marketingGetProject($userId, $projectId);
    if (!$p) {
        return ['error' => 'Progetto non trovato.'];
    }
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 150) {
        return ['error' => 'Il titolo del passo è obbligatorio (massimo 150 caratteri).'];
    }
    foreach ($p['steps'] as $s) {
        if ((int) $s['is_final']) {
            return ['error' => 'Il progetto ha già il Finale: elimina il Finale se vuoi aggiungere altri passi.'];
        }
    }
    if (count($p['steps']) >= MARKETING_MAX_STEPS) {
        return ['error' => 'Questo progetto ha già il numero massimo di passi (' . MARKETING_MAX_STEPS . ').'];
    }
    $last = $p['steps'] ? end($p['steps']) : null;
    $pos = $last ? ((int) $last['position'] + 1) : 1;
    getDB()->prepare('INSERT INTO marketing_steps (project_id, user_id, position, is_final, title, instructions, created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$projectId, $userId, $pos, $isFinal ? 1 : 0, $title, marketingText($instructions), marketingActor($by)]);
    return ['id' => (int) getDB()->lastInsertId()];
}

// Passo del profilo, o null.
function marketingGetStep(int $userId, int $stepId): ?array {
    $stmt = getDB()->prepare('SELECT * FROM marketing_steps WHERE id=? AND user_id=?');
    $stmt->execute([$stepId, $userId]);
    return $stmt->fetch() ?: null;
}

function marketingUpdateStep(int $userId, int $stepId, array $fields): ?string {
    if (!marketingGetStep($userId, $stepId)) {
        return 'Passo non trovato.';
    }
    $sets = [];
    $params = [];
    if (array_key_exists('title', $fields)) {
        $t = trim((string) $fields['title']);
        if ($t === '' || mb_strlen($t) > 150) {
            return 'Il titolo del passo è obbligatorio (massimo 150 caratteri).';
        }
        $sets[] = 'title=?';
        $params[] = $t;
    }
    if (array_key_exists('instructions', $fields)) {
        $sets[] = 'instructions=?';
        $params[] = marketingText((string) $fields['instructions']);
    }
    if ($sets) {
        $params[] = $stepId;
        $params[] = $userId;
        getDB()->prepare('UPDATE marketing_steps SET ' . implode(', ', $sets) . ' WHERE id=? AND user_id=?')->execute($params);
    }
    return null;
}

// Elimina un passo dal modello. Le cronologie passate restano leggibili (gli eventi conservano il nome del passo).
function marketingDeleteStep(int $userId, int $stepId): ?string {
    if (!marketingGetStep($userId, $stepId)) {
        return 'Passo non trovato.';
    }
    getDB()->prepare('DELETE FROM marketing_steps WHERE id=? AND user_id=?')->execute([$stepId, $userId]);
    return null;
}

// ---------------------------------------------------------------- esecuzioni e cronologia

function marketingAddEvent(int $runId, int $projectId, int $userId, string $kind, string $actor, ?int $stepId, ?string $stepLabel, ?string $body): void {
    getDB()->prepare('INSERT INTO marketing_run_events (run_id, project_id, user_id, step_id, step_label, kind, actor, body) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$runId, $projectId, $userId, $stepId, $stepLabel !== null ? mb_substr($stepLabel, 0, 200) : null, $kind, marketingActor($actor), marketingText($body)]);
}

// "Passo 2 — Titolo" per salvare nell'evento il nome del passo com'era in quel momento.
function marketingStepFullLabel(array $project, array $step): string {
    return marketingStepLabel($project['steps'], (int) $step['id']) . ' — ' . mb_substr((string) $step['title'], 0, 150);
}

// Esecuzione in corso del progetto, o null.
function marketingOpenRun(int $userId, int $projectId): ?array {
    $stmt = getDB()->prepare("SELECT * FROM marketing_runs WHERE project_id=? AND user_id=? AND status='running' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$projectId, $userId]);
    return $stmt->fetch() ?: null;
}

// Passi già completati in un'esecuzione: [step_id => evento step_done].
function marketingRunDone(int $userId, int $runId): array {
    $stmt = getDB()->prepare("SELECT step_id, actor, body, created_at FROM marketing_run_events WHERE run_id=? AND user_id=? AND kind='step_done' AND step_id IS NOT NULL ORDER BY id ASC");
    $stmt->execute([$runId, $userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $e) {
        $out[(int) $e['step_id']] = $e;
    }
    return $out;
}

// Primo passo del progetto non ancora completato nell'esecuzione (quello da fare adesso), o null.
function marketingRunNextStep(array $project, array $done): ?array {
    foreach ($project['steps'] as $s) {
        if (!isset($done[(int) $s['id']])) {
            return $s;
        }
    }
    return null;
}

// Esecuzione del profilo con cronologia e avanzamento, o null.
function marketingGetRun(int $userId, int $runId): ?array {
    $stmt = getDB()->prepare('SELECT * FROM marketing_runs WHERE id=? AND user_id=?');
    $stmt->execute([$runId, $userId]);
    $run = $stmt->fetch();
    if (!$run) {
        return null;
    }
    $project = marketingGetProject($userId, (int) $run['project_id']);
    $stmt = getDB()->prepare('SELECT * FROM marketing_run_events WHERE run_id=? AND user_id=? ORDER BY id ASC');
    $stmt->execute([$runId, $userId]);
    $run['events'] = $stmt->fetchAll();
    $run['done'] = marketingRunDone($userId, $runId);
    $run['project'] = $project;
    $run['next_step'] = ($run['status'] === 'running' && $project) ? marketingRunNextStep($project, $run['done']) : null;
    return $run;
}

// Esecuzioni del progetto, più recenti per primi, con il numero di passi completati.
function marketingListRuns(int $userId, int $projectId, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    $stmt = getDB()->prepare("SELECT r.*, (SELECT COUNT(*) FROM marketing_run_events e WHERE e.run_id=r.id AND e.kind='step_done') AS done_count
        FROM marketing_runs r WHERE r.project_id=? AND r.user_id=? ORDER BY r.id DESC LIMIT $limit");
    $stmt->execute([$projectId, $userId]);
    return $stmt->fetchAll();
}

// Avvia un'esecuzione (o riprende quella già in corso: ce n'è una sola per progetto alla volta).
// Ritorna ['run_id'=>n, 'resumed'=>bool] oppure ['error'=>testo].
function marketingStartRun(int $userId, int $projectId, string $by): array {
    $p = marketingGetProject($userId, $projectId);
    if (!$p) {
        return ['error' => 'Progetto non trovato.'];
    }
    if ($p['status'] === 'archived') {
        return ['error' => 'Il progetto è archiviato: ripristinalo prima di usarlo.'];
    }
    if (!$p['is_active']) {
        return ['error' => 'Il progetto è disattivato (interruttore Attivo spento): non si possono avviare esecuzioni.'];
    }
    if (!$p['steps']) {
        return ['error' => 'Il progetto non ha ancora passi: aggiungine almeno uno.'];
    }
    if ($open = marketingOpenRun($userId, $projectId)) {
        return ['run_id' => (int) $open['id'], 'resumed' => true];
    }
    getDB()->prepare('INSERT INTO marketing_runs (project_id, user_id, started_by, steps_total) VALUES (?,?,?,?)')
        ->execute([$projectId, $userId, marketingActor($by), count($p['steps'])]);
    $runId = (int) getDB()->lastInsertId();
    marketingAddEvent($runId, $projectId, $userId, 'started', $by, null, null, null);
    return ['run_id' => $runId, 'resumed' => false];
}

// Aggiunge una nota alla cronologia dell'esecuzione in corso (facoltativamente riferita a un passo).
function marketingAddNote(int $userId, int $projectId, string $text, ?int $stepId, string $by): ?string {
    $p = marketingGetProject($userId, $projectId);
    if (!$p) {
        return 'Progetto non trovato.';
    }
    $run = marketingOpenRun($userId, $projectId);
    if (!$run) {
        return 'Nessuna esecuzione in corso: avviane una prima di scrivere note.';
    }
    $text = marketingText($text);
    if ($text === null) {
        return 'La nota è vuota.';
    }
    $label = null;
    if ($stepId) {
        $step = null;
        foreach ($p['steps'] as $s) {
            if ((int) $s['id'] === $stepId) {
                $step = $s;
            }
        }
        if (!$step) {
            return 'Il passo indicato non appartiene a questo progetto.';
        }
        $label = marketingStepFullLabel($p, $step);
    }
    marketingAddEvent((int) $run['id'], $projectId, $userId, 'note', $by, $stepId ?: null, $label, $text);
    return null;
}

// Completa un passo nell'esecuzione in corso del suo progetto, SOLO se i precedenti sono completati.
// Completando il Finale l'esecuzione è conclusa.
function marketingCompleteStep(int $userId, int $stepId, ?string $note, string $by): ?string {
    $step = marketingGetStep($userId, $stepId);
    if (!$step) {
        return 'Passo non trovato.';
    }
    $p = marketingGetProject($userId, (int) $step['project_id']);
    if (!$p || $p['status'] === 'archived') {
        return 'Il progetto è archiviato: ripristinalo prima di usarlo.';
    }
    $run = marketingOpenRun($userId, (int) $p['id']);
    if (!$run) {
        return 'Nessuna esecuzione in corso: avviane una (start_marketing_run, o "Esegui ora" in dashboard) prima di completare i passi.';
    }
    $done = marketingRunDone($userId, (int) $run['id']);
    if (isset($done[$stepId])) {
        return 'Questo passo è già completato in questa esecuzione.';
    }
    foreach ($p['steps'] as $prev) {
        if ((int) $prev['id'] === $stepId) {
            break;
        }
        if (!isset($done[(int) $prev['id']])) {
            return 'Rispetta l\'ordine: completa prima ' . marketingStepFullLabel($p, $prev) . '.';
        }
    }
    marketingAddEvent((int) $run['id'], (int) $p['id'], $userId, 'step_done', $by, $stepId, marketingStepFullLabel($p, $step), $note);
    if ((int) $step['is_final']) {
        getDB()->prepare("UPDATE marketing_runs SET status='completed', finished_at=NOW(), steps_total=? WHERE id=? AND user_id=?")
            ->execute([count($p['steps']), (int) $run['id'], $userId]);
        marketingAddEvent((int) $run['id'], (int) $p['id'], $userId, 'finished', $by, null, null, 'Esecuzione completata.');
    }
    return null;
}

// Interrompe l'esecuzione in corso (con un motivo facoltativo).
function marketingInterruptRun(int $userId, int $projectId, ?string $reason, string $by): ?string {
    $run = marketingOpenRun($userId, $projectId);
    if (!$run) {
        return 'Nessuna esecuzione in corso.';
    }
    getDB()->prepare("UPDATE marketing_runs SET status='interrupted', finished_at=NOW() WHERE id=? AND user_id=?")->execute([(int) $run['id'], $userId]);
    marketingAddEvent((int) $run['id'], $projectId, $userId, 'interrupted', $by, null, null, $reason);
    return null;
}

// ---------------------------------------------------------------- serializzazione (API/MCP)

function marketingSerializeSteps(array $project): array {
    $out = [];
    foreach ($project['steps'] as $s) {
        $out[] = [
            'id' => (int) $s['id'],
            'position' => (int) $s['position'],
            'label' => marketingStepLabel($project['steps'], (int) $s['id']),
            'is_final' => (bool) (int) $s['is_final'],
            'title' => $s['title'],
            'instructions' => $s['instructions'],
        ];
    }
    return $out;
}

// Riga di un'esecuzione (senza cronologia) per elenchi e riepiloghi.
function marketingSerializeRunSummary(array $r, int $stepsNow = 0): array {
    $total = $r['status'] === 'running' ? $stepsNow : (int) $r['steps_total'];
    return [
        'id' => (int) $r['id'],
        'project_id' => (int) $r['project_id'],
        'status' => $r['status'],
        'started_by' => $r['started_by'],
        'started_at' => $r['started_at'],
        'finished_at' => $r['finished_at'],
        'steps_done' => (int) ($r['done_count'] ?? 0),
        'steps_total' => $total,
    ];
}

function marketingSerializeProject(array $p): array {
    $open = marketingOpenRun((int) $p['user_id'], (int) $p['id']);
    $openOut = null;
    if ($open) {
        $done = marketingRunDone((int) $p['user_id'], (int) $open['id']);
        $next = marketingRunNextStep($p, $done);
        $openOut = [
            'id' => (int) $open['id'],
            'started_by' => $open['started_by'],
            'started_at' => $open['started_at'],
            'steps_done' => count($done),
            'steps_total' => count($p['steps']),
            // Il passo da eseguire adesso: le IA lavorano solo su questo, mai saltare avanti.
            'next_step' => $next ? ['id' => (int) $next['id'], 'label' => marketingStepLabel($p['steps'], (int) $next['id']), 'title' => $next['title']] : null,
        ];
    }
    $hasFinal = false;
    foreach ($p['steps'] as $s) {
        $hasFinal = $hasFinal || (bool) (int) $s['is_final'];
    }
    $last = marketingListRuns((int) $p['user_id'], (int) $p['id'], 1);
    return [
        'id' => (int) $p['id'],
        'title' => $p['title'],
        'description' => $p['description'],
        'archived' => $p['status'] === 'archived',
        'active' => (bool) $p['is_active'],
        'created_by' => $p['created_by'],
        'created_at' => $p['created_at'],
        'has_final' => $hasFinal,
        'steps' => marketingSerializeSteps($p),
        'open_run' => $openOut,
        'last_run' => $last ? marketingSerializeRunSummary($last[0], count($p['steps'])) : null,
    ];
}

function marketingSerializeRun(array $run): array {
    $p = $run['project'];
    $steps = [];
    foreach ($p['steps'] ?? [] as $s) {
        $d = $run['done'][(int) $s['id']] ?? null;
        $steps[] = [
            'id' => (int) $s['id'],
            'label' => marketingStepLabel($p['steps'], (int) $s['id']),
            'title' => $s['title'],
            'instructions' => $s['instructions'],
            'done' => $d !== null,
            'completed_at' => $d['created_at'] ?? null,
            'completed_by' => $d['actor'] ?? null,
            'note' => $d['body'] ?? null,
        ];
    }
    $events = [];
    foreach ($run['events'] as $e) {
        $events[] = [
            'at' => $e['created_at'],
            'kind' => $e['kind'],
            'actor' => $e['actor'],
            'step' => $e['step_label'],
            'text' => $e['body'],
        ];
    }
    $next = $run['next_step'];
    return [
        'id' => (int) $run['id'],
        'project_id' => (int) $run['project_id'],
        'status' => $run['status'],
        'started_by' => $run['started_by'],
        'started_at' => $run['started_at'],
        'finished_at' => $run['finished_at'],
        'steps_done' => count($run['done']),
        'steps_total' => $run['status'] === 'running' ? count($p['steps'] ?? []) : (int) $run['steps_total'],
        'next_step' => $next ? ['id' => (int) $next['id'], 'label' => marketingStepLabel($p['steps'], (int) $next['id']), 'title' => $next['title'], 'instructions' => $next['instructions']] : null,
        'steps' => $steps,
        'history' => $events,
    ];
}

// Numero di esecuzioni in corso (per il pallino della voce Marketing); 0 se le tabelle non esistono ancora.
function marketingActiveCount(int $userId): int {
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM marketing_runs WHERE user_id=? AND status='running'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
