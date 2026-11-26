<?php
// Cruscotto (dashboard_cruscotto.php, solo amministratori): raccoglie in un'unica pagina i contatori e le voci dei menù
// admin (Social, Automazioni, Marketing) e la messaggistica, trasformandoli in dati per i grafici.
// Ogni interrogazione è protetta da try/catch: se una tabella manca (migrazione non applicata) quel grafico resta vuoto.
require_once __DIR__ . '/dash_right.php';
require_once __DIR__ . '/marketing.php';

/** Ultimi $n giorni (inclusi oggi) come ['Y-m-d' => 'd/m']. */
function cruscottoDays(int $n = 14): array {
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime("-$i day");
        $out[date('Y-m-d', $t)] = date('d/m', $t);
    }
    return $out;
}

/** Esegue una query «giorno => valori» e riempie i giorni mancanti con zero. $cols: colonne numeriche dopo `d`. */
function cruscottoSeries(string $sql, array $params, array $days, array $cols): array {
    $series = [];
    foreach ($cols as $c) { $series[$c] = array_fill_keys(array_keys($days), 0); }
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $r) {
            $d = (string) $r['d'];
            if (!isset($days[$d])) { continue; }
            foreach ($cols as $c) { $series[$c][$d] = (int) ($r[$c] ?? 0); }
        }
    } catch (Throwable $e) {
    }
    foreach ($series as $c => $v) { $series[$c] = array_values($v); }
    return $series;
}

/**
 * Tutti i dati del Cruscotto per il profilo attivo.
 * @return array{days: string[], inbox: array, queue: array, api: array, marketing: array, links: array}
 */
function cruscottoData(array $user, array $profile, array $nav): array {
    $pid = (int) $profile['id'];
    $isBand = in_array($profile['account_type'] ?? 'band', ['band', 'label'], true);
    $db = getDB();
    $daysMap = cruscottoDays(14);
    $days = array_values($daysMap);
    $since = 'CURDATE() - INTERVAL 13 DAY';

    // --- Messaggistica: da leggere adesso + arrivi per giorno ---
    $cards = dashInboxCards($pid, $isBand, $nav);
    $inboxNow = [];
    foreach ($cards as $key => [$label, $icon, $url, $n, $desc]) {
        $inboxNow[] = ['key' => $key, 'label' => $label, 'value' => (int) $n, 'url' => $url, 'icon' => $icon];
    }
    $inboxDaily = [
        'Messaggi' => cruscottoSeries("SELECT DATE(created_at) d, COUNT(*) n FROM direct_messages WHERE recipient_id = ? AND created_at >= $since GROUP BY d", [$pid], $daysMap, ['n'])['n'],
        'Contatti' => cruscottoSeries("SELECT DATE(created_at) d, COUNT(*) n FROM contact_requests WHERE user_id = ? AND created_at >= $since GROUP BY d", [$pid], $daysMap, ['n'])['n'],
    ];
    if (isset($cards['inquiries'])) {
        $inboxDaily['Richieste'] = cruscottoSeries("SELECT DATE(created_at) d, COUNT(*) n FROM service_inquiries WHERE user_id = ? AND created_at >= $since GROUP BY d", [$pid], $daysMap, ['n'])['n'];
    }
    if (isset($cards['reservations'])) {
        $inboxDaily['Prenotazioni'] = cruscottoSeries("SELECT DATE(created_at) d, COUNT(*) n FROM table_reservations WHERE user_id = ? AND created_at >= $since GROUP BY d", [$pid], $daysMap, ['n'])['n'];
    }

    // --- Coda Social ---
    $queueOn = !empty($profile['metricool_auto_social']);
    $queue = ['on' => $queueOn, 'status' => [], 'daily' => [], 'stuck' => 0];
    try {
        $st = $db->prepare("SELECT status, COUNT(*) n FROM social_queue WHERE user_id = ? AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY status");
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $r) { $queue['status'][$r['status']] = (int) $r['n']; }
        $queue['suspended'] = dashRightCount("SELECT COUNT(*) FROM social_queue WHERE user_id = ? AND suspended = 1 AND status IN ('pending','processing')", [$pid]);
        $queue['stuck'] = dashRightCount("SELECT COUNT(*) FROM social_queue WHERE user_id = ? AND status = 'processing' AND created_at < NOW() - INTERVAL 2 HOUR AND created_at >= NOW() - INTERVAL 30 DAY", [$pid]);
    } catch (Throwable $e) {
    }
    $qd = [];
    foreach (['pending' => 'In coda', 'scheduled' => 'Programmati', 'done' => 'Pubblicati', 'failed' => 'Errori'] as $stt => $lbl) {
        $inList = $stt === 'pending' ? "'pending','processing'" : "'$stt'";
        $qd[$lbl] = cruscottoSeries("SELECT DATE(created_at) d, COUNT(*) n FROM social_queue WHERE user_id = ? AND status IN ($inList) AND created_at >= $since GROUP BY d", [$pid], $daysMap, ['n'])['n'];
    }
    $queue['daily'] = $qd;
    $scheduled = 0;
    try { $scheduled = dashRightCount("SELECT COUNT(*) FROM social_queue WHERE user_id = ? AND status = 'scheduled'", [$pid]); } catch (Throwable $e) {}
    $queue['scheduled'] = $scheduled;

    // --- API / MCP ---
    $api = ['tokens' => [], 'calls' => 0, 'errors' => 0, 'daily_calls' => array_fill(0, 14, 0), 'daily_errors' => array_fill(0, 14, 0)];
    try {
        $st = $db->prepare('SELECT id, label, mcp_name, last_used_at FROM api_tokens WHERE user_id = ? AND is_active = 1 ORDER BY last_used_at IS NULL, last_used_at DESC LIMIT 8');
        $st->execute([$pid]);
        $api['tokens'] = $st->fetchAll();
        if ($api['tokens']) {
            $ids = array_map('intval', array_column($api['tokens'], 'id'));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = cruscottoSeries("SELECT DATE(created_at) d, COUNT(*) n, COALESCE(SUM(status_code >= 400), 0) e FROM api_request_logs WHERE api_token_id IN ($in) AND created_at >= $since GROUP BY d", $ids, $daysMap, ['n', 'e']);
            $api['daily_calls'] = $s['n'];
            $api['daily_errors'] = $s['e'];
            $api['calls'] = (int) end($s['n']);
            $api['errors'] = (int) end($s['e']);
            $st = $db->prepare("SELECT COUNT(*) total, COALESCE(SUM(status_code >= 400), 0) errors FROM api_request_logs WHERE api_token_id IN ($in) AND created_at >= NOW() - INTERVAL 1 DAY");
            $st->execute($ids);
            $agg = $st->fetch();
            $api['calls'] = (int) ($agg['total'] ?? 0);
            $api['errors'] = (int) ($agg['errors'] ?? 0);
        }
    } catch (Throwable $e) {
    }

    // --- Marketing ---
    $mk = ['runs' => ['running' => 0, 'completed' => 0, 'interrupted' => 0], 'projects' => [], 'active_projects' => 0];
    try {
        $st = $db->prepare("SELECT status, COUNT(*) n FROM marketing_runs WHERE user_id = ? AND started_at >= NOW() - INTERVAL 30 DAY GROUP BY status");
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $r) { $mk['runs'][$r['status']] = (int) $r['n']; }
        $st = $db->prepare("SELECT p.id, p.title, COUNT(r.id) n FROM marketing_projects p LEFT JOIN marketing_runs r ON r.project_id = p.id AND r.started_at >= NOW() - INTERVAL 30 DAY
                            WHERE p.user_id = ? AND p.status <> 'archived' GROUP BY p.id, p.title ORDER BY n DESC, p.id DESC LIMIT 6");
        $st->execute([$pid]);
        $mk['projects'] = $st->fetchAll();
        $mk['active_projects'] = dashRightCount("SELECT COUNT(*) FROM marketing_projects WHERE user_id = ? AND status = 'active' AND is_active = 1", [$pid]);
    } catch (Throwable $e) {
    }

    // --- Collegamenti: tutte le voci dei menù admin, con uno stato ---
    $links = [
        ['group' => 'Social', 'label' => 'Coda Social', 'url' => '/dashboard_social_queue.php?status=all', 'icon' => 'fa-solid fa-share-nodes', 'badge' => (int) (($queue['status']['pending'] ?? 0) + ($queue['status']['processing'] ?? 0)), 'tone' => 'info', 'on' => $queueOn],
        ['group' => 'Social', 'label' => 'Calendario', 'url' => '/dashboard_schedule_admin.php', 'icon' => 'fa-solid fa-calendar-days', 'badge' => $scheduled, 'tone' => 'info', 'on' => null],
    ];
    if ($isBand) {
        $links[] = ['group' => 'Social', 'label' => 'Social Automatico', 'url' => '/dashboard_metricool.php', 'icon' => 'fa-solid fa-robot', 'badge' => 0, 'tone' => '', 'on' => $queueOn];
        $links[] = ['group' => 'Automazioni', 'label' => 'Canale Telegram', 'url' => '/dashboard_telegram.php', 'icon' => 'fa-brands fa-telegram', 'badge' => 0, 'tone' => '', 'on' => !empty($user['telegram_chat_id'])];
    }
    $links[] = ['group' => 'Automazioni', 'label' => 'Token e richieste', 'url' => '/dashboard_api_tokens.php', 'icon' => 'fa-solid fa-code', 'badge' => (int) $api['errors'], 'tone' => 'bad', 'on' => count($api['tokens']) > 0];
    $links[] = ['group' => 'Automazioni', 'label' => 'Server MCP', 'url' => '/admin_mcp.php', 'icon' => 'fa-solid fa-server', 'badge' => 0, 'tone' => '', 'on' => null];
    $links[] = ['group' => 'Marketing', 'label' => 'Marketing', 'url' => '/dashboard_marketing.php', 'icon' => 'fa-solid fa-bullhorn', 'badge' => (int) $mk['runs']['running'], 'tone' => 'info', 'on' => $mk['active_projects'] > 0];
    $links[] = ['group' => 'Amministrazione', 'label' => 'Area Admin', 'url' => '/admin_dashboard.php', 'icon' => 'fa-solid fa-shield-halved', 'badge' => 0, 'tone' => '', 'on' => null];
    $links[] = ['group' => 'Amministrazione', 'label' => 'Dashboard classica', 'url' => '/classic_entry.php', 'icon' => 'fa-solid fa-clock-rotate-left', 'badge' => 0, 'tone' => '', 'on' => null];

    return ['days' => $days, 'inbox' => ['now' => $inboxNow, 'daily' => $inboxDaily], 'queue' => $queue, 'api' => $api, 'marketing' => $mk, 'links' => $links];
}
