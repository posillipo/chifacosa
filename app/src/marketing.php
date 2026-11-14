<?php
// Modulo Marketing > Pianificate: un PROGETTO è una serie ordinata di PASSI (Passo 1, Passo 2, ... e
// Finale). I passi si aggiungono liberamente; "Inserisci Finale" chiude il progetto con l'ultimo passo.
// I passi vanno completati IN ORDINE (anche dalle IA collegate via MCP). Ogni funzione verifica che il
// progetto/passo appartenga al profilo ($userId): nessun accesso ad altri profili.
// Usato sia dalla dashboard (dashboard_marketing.php) sia dall'API (api_marketing.php) e quindi dall'MCP.

const MARKETING_MAX_PROJECTS = 100;
const MARKETING_MAX_STEPS = 50;

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

// Primo passo ancora da fare (quello da eseguire adesso), o null se tutti completati / nessun passo.
function marketingNextStep(array $project): ?array {
    foreach ($project['steps'] as $s) {
        if ($s['status'] !== 'done') {
            return $s;
        }
    }
    return null;
}

function marketingSerializeProject(array $p): array {
    $steps = $p['steps'] ?? [];
    $next = marketingNextStep($p);
    $hasFinal = false;
    $done = 0;
    $out = [];
    foreach ($steps as $s) {
        $hasFinal = $hasFinal || (bool) (int) $s['is_final'];
        $done += $s['status'] === 'done' ? 1 : 0;
        $out[] = [
            'id' => (int) $s['id'],
            'position' => (int) $s['position'],
            'label' => marketingStepLabel($steps, (int) $s['id']),
            'is_final' => (bool) (int) $s['is_final'],
            'title' => $s['title'],
            'instructions' => $s['instructions'],
            'status' => $s['status'],
            'result' => $s['result'],
            'completed_at' => $s['completed_at'],
            'completed_by' => $s['completed_by'],
        ];
    }
    return [
        'id' => (int) $p['id'],
        'title' => $p['title'],
        'description' => $p['description'],
        'status' => $p['status'],
        'created_by' => $p['created_by'],
        'created_at' => $p['created_at'],
        'completed_at' => $p['completed_at'],
        'has_final' => $hasFinal,
        'steps_total' => count($steps),
        'steps_done' => $done,
        // Il passo da eseguire adesso: le IA devono lavorare solo su questo, mai saltare avanti.
        'next_step' => $next ? ['id' => (int) $next['id'], 'label' => marketingStepLabel($steps, (int) $next['id']), 'title' => $next['title']] : null,
        'steps' => $out,
    ];
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
    $description = $description !== null ? trim($description) : null;
    getDB()->prepare('INSERT INTO marketing_projects (user_id, title, description, created_by) VALUES (?,?,?,?)')
        ->execute([$userId, $title, ($description !== null && $description !== '') ? mb_substr($description, 0, 20000) : null, $by === 'ai' ? 'ai' : 'user']);
    return ['id' => (int) getDB()->lastInsertId()];
}

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
        $d = trim((string) $fields['description']);
        $sets[] = 'description=?';
        $params[] = $d !== '' ? mb_substr($d, 0, 20000) : null;
    }
    if (array_key_exists('archived', $fields)) {
        // Archiviare/ripristinare: ripristinando, lo stato torna "completato" se il Finale è fatto, altrimenti "in corso".
        if ($fields['archived']) {
            $sets[] = "status='archived'";
        } else {
            marketingRefreshStatus($projectId, $userId, true);
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

// Ricalcola lo stato del progetto dal Finale: "completato" solo se il passo Finale esiste ed è fatto.
// Un progetto archiviato resta archiviato, salvo $unarchive.
function marketingRefreshStatus(int $projectId, int $userId, bool $unarchive = false): void {
    $p = marketingGetProject($userId, $projectId);
    if (!$p || ($p['status'] === 'archived' && !$unarchive)) {
        return;
    }
    $finalDone = false;
    foreach ($p['steps'] as $s) {
        if ((int) $s['is_final'] && $s['status'] === 'done') {
            $finalDone = true;
        }
    }
    getDB()->prepare('UPDATE marketing_projects SET status=?, completed_at=? WHERE id=? AND user_id=?')
        ->execute([$finalDone ? 'completed' : 'active', $finalDone ? ($p['completed_at'] ?: date('Y-m-d H:i:s')) : null, $projectId, $userId]);
}

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
    $pos = $p['steps'] ? ((int) end($p['steps'])['position'] + 1) : 1;
    $instructions = $instructions !== null ? trim($instructions) : null;
    getDB()->prepare('INSERT INTO marketing_steps (project_id, user_id, position, is_final, title, instructions, created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$projectId, $userId, $pos, $isFinal ? 1 : 0, $title, ($instructions !== null && $instructions !== '') ? mb_substr($instructions, 0, 20000) : null, $by === 'ai' ? 'ai' : 'user']);
    $id = (int) getDB()->lastInsertId();
    // Un progetto già completato che riceve un nuovo passo torna "in corso".
    marketingRefreshStatus($projectId, $userId);
    return ['id' => $id];
}

// Passo (con progetto) se è del profilo.
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
        $i = trim((string) $fields['instructions']);
        $sets[] = 'instructions=?';
        $params[] = $i !== '' ? mb_substr($i, 0, 20000) : null;
    }
    if ($sets) {
        $params[] = $stepId;
        $params[] = $userId;
        getDB()->prepare('UPDATE marketing_steps SET ' . implode(', ', $sets) . ' WHERE id=? AND user_id=?')->execute($params);
    }
    return null;
}

function marketingDeleteStep(int $userId, int $stepId): ?string {
    $s = marketingGetStep($userId, $stepId);
    if (!$s) {
        return 'Passo non trovato.';
    }
    getDB()->prepare('DELETE FROM marketing_steps WHERE id=? AND user_id=?')->execute([$stepId, $userId]);
    marketingRefreshStatus((int) $s['project_id'], $userId);
    return null;
}

// Segna un passo come completato, SOLO se tutti i precedenti lo sono (ordine obbligatorio).
function marketingCompleteStep(int $userId, int $stepId, ?string $result, string $by): ?string {
    $s = marketingGetStep($userId, $stepId);
    if (!$s) {
        return 'Passo non trovato.';
    }
    if ($s['status'] === 'done') {
        return 'Questo passo è già completato.';
    }
    $p = marketingGetProject($userId, (int) $s['project_id']);
    if (!$p || $p['status'] === 'archived') {
        return 'Il progetto è archiviato: ripristinalo prima di completare i passi.';
    }
    foreach ($p['steps'] as $prev) {
        if ((int) $prev['position'] >= (int) $s['position']) {
            break;
        }
        if ($prev['status'] !== 'done') {
            return 'Rispetta l\'ordine: completa prima ' . marketingStepLabel($p['steps'], (int) $prev['id']) . ' — ' . $prev['title'] . '.';
        }
    }
    $result = $result !== null ? trim($result) : null;
    getDB()->prepare("UPDATE marketing_steps SET status='done', result=?, completed_at=NOW(), completed_by=? WHERE id=? AND user_id=?")
        ->execute([($result !== null && $result !== '') ? mb_substr($result, 0, 20000) : null, $by === 'ai' ? 'ai' : 'user', $stepId, $userId]);
    marketingRefreshStatus((int) $s['project_id'], $userId);
    return null;
}

// Riapre un passo (e tutti i successivi, per non rompere l'ordine). Solo dalla dashboard.
function marketingReopenStep(int $userId, int $stepId): ?string {
    $s = marketingGetStep($userId, $stepId);
    if (!$s) {
        return 'Passo non trovato.';
    }
    getDB()->prepare("UPDATE marketing_steps SET status='todo', result=NULL, completed_at=NULL, completed_by=NULL WHERE project_id=? AND user_id=? AND position >= ?")
        ->execute([(int) $s['project_id'], $userId, (int) $s['position']]);
    marketingRefreshStatus((int) $s['project_id'], $userId);
    return null;
}

// Numero di progetti in corso (per il pallino della voce Marketing); 0 se le tabelle non esistono ancora.
function marketingActiveCount(int $userId): int {
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM marketing_projects WHERE user_id=? AND status='active'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
