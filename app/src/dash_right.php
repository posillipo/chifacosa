<?php
/**
 * Colonna destra della dashboard ("Attività"): contatori da gestire, Coda Social, attività dei
 * server MCP / API e prossimi eventi. Mostra SOLO dati del profilo attivo ($profileId, già
 * validato dalla pagina), mai di altri profili. Ogni blocco è isolato in try/catch: se una query
 * fallisce (tabella assente, migrazione mancante...) quel blocco sparisce ma la pagina resta intera.
 * I blocchi più pesanti sono tenuti in cache di sessione per 60 secondi (una dashboard fa molte
 * richieste ravvicinate: niente interrogazioni ripetute a ogni clic).
 */

// "5 min fa", "3 h fa", "2 g fa" — per mostrare da quanto tempo è successo qualcosa.
function dashRelTime(?string $datetime): string {
    if (!$datetime) {
        return 'mai';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return 'mai';
    }
    $d = max(0, time() - $ts);
    if ($d < 60) return 'adesso';
    if ($d < 3600) return floor($d / 60) . ' min fa';
    if ($d < 86400) return floor($d / 3600) . ' h fa';
    return floor($d / 86400) . ' g fa';
}

/** Conta con una query a un solo valore; 0 in caso di errore. */
function dashRightCount(string $sql, array $params): int {
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Raccoglie i dati dei blocchi "pesanti" (coda, attività API, eventi), con cache di sessione di
 * 60 secondi per profilo. I contatori non letti NON passano da qui: arrivano già freschi
 * dall'intestazione della dashboard.
 */
function dashRightHeavyData(int $profileId, bool $isBandOrLabel, bool $queueOn): array {
    $key = $profileId . ':' . ($isBandOrLabel ? 'b' : 'p') . ($queueOn ? 'q' : '-');
    if (isset($_SESSION['dash_right_cache'][$key]) && time() - $_SESSION['dash_right_cache'][$key]['t'] < 60) {
        return $_SESSION['dash_right_cache'][$key]['d'];
    }
    $data = ['queue' => null, 'api' => null, 'events' => []];
    $db = getDB();

    if ($queueOn) {
        try {
            $q = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'stuck' => 0, 'suspended' => 0];
            $st = $db->prepare("SELECT status, COUNT(*) n FROM social_queue
                                WHERE user_id = ? AND status IN ('pending','processing','failed')
                                  AND created_at >= NOW() - INTERVAL 30 DAY
                                GROUP BY status");
            $st->execute([$profileId]);
            foreach ($st->fetchAll() as $r) {
                $q[$r['status']] = (int) $r['n'];
            }
            // "Fermi": presi in carico da più di 2 ore e mai chiusi.
            $q['stuck'] = dashRightCount("SELECT COUNT(*) FROM social_queue WHERE user_id = ? AND status = 'processing' AND created_at < NOW() - INTERVAL 2 HOUR AND created_at >= NOW() - INTERVAL 30 DAY", [$profileId]);
            $q['suspended'] = dashRightCount("SELECT COUNT(*) FROM social_queue WHERE user_id = ? AND suspended = 1 AND status IN ('pending','processing')", [$profileId]);
            $data['queue'] = $q;
        } catch (Throwable $e) {
            $data['queue'] = null;
        }
    }

    try {
        $st = $db->prepare('SELECT id, label, mcp_name, last_used_at FROM api_tokens WHERE user_id = ? AND is_active = 1 ORDER BY last_used_at IS NULL, last_used_at DESC LIMIT 5');
        $st->execute([$profileId]);
        $tokens = $st->fetchAll();
        if ($tokens) {
            $ids = array_map('intval', array_column($tokens, 'id'));
            $in = implode(',', array_fill(0, count($ids), '?'));
            // Usa l'indice (api_token_id, created_at): niente scansione dell'intera tabella dei log.
            $st = $db->prepare("SELECT COUNT(*) total, COALESCE(SUM(status_code >= 400), 0) errors
                                FROM api_request_logs WHERE api_token_id IN ($in) AND created_at >= NOW() - INTERVAL 1 DAY");
            $st->execute($ids);
            $agg = $st->fetch();
            $data['api'] = [
                'tokens' => $tokens,
                'calls' => (int) ($agg['total'] ?? 0),
                'errors' => (int) ($agg['errors'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        $data['api'] = null;
    }

    if ($isBandOrLabel) {
        try {
            $st = $db->prepare('SELECT id, title, venue, city, event_date FROM events WHERE user_id = ? AND event_date >= NOW() ORDER BY event_date ASC LIMIT 3');
            $st->execute([$profileId]);
            $data['events'] = $st->fetchAll();
        } catch (Throwable $e) {
            $data['events'] = [];
        }
    }

    $_SESSION['dash_right_cache'][$key] = ['t' => time(), 'd' => $data];
    return $data;
}

/** Riga "etichetta … valore" con collegamento. */
function dashRightRow(string $label, int $value, string $url, string $tone = ''): string {
    $cls = 'dash-right-val' . ($tone !== '' ? ' ' . $tone : '') . ($value === 0 ? ' zero' : '');
    return '<a class="dash-right-row" href="' . e($url) . '"><span>' . e($label) . '</span><b class="' . e($cls) . '">' . (int) $value . '</b></a>';
}

/**
 * HTML completo della colonna destra (+ pannello per telefono/tablet).
 * $unread: contatori già calcolati dall'intestazione ['contacts'=>n, 'messages'=>n].
 */
function renderDashRight(array $ctx): string {
    $profileId = (int) $ctx['profile_id'];
    $isBandOrLabel = !empty($ctx['is_band_or_label']);
    $nav = $ctx['nav_visibility'] ?? [];
    $queueOn = !empty($ctx['queue_on']);
    $isAdmin = !empty($ctx['is_admin']);
    $tzProfile = $ctx['tz_profile'] ?? [];

    $heavy = dashRightHeavyData($profileId, $isBandOrLabel, $queueOn);

    // --- Da gestire ---
    $todo = '';
    $todo .= dashRightRow('Contatti non letti', (int) ($ctx['unread_contacts'] ?? 0), '/dashboard_contacts.php');
    $todo .= dashRightRow('Messaggi non letti', (int) ($ctx['unread_messages'] ?? 0), '/dashboard_messages.php');
    if ($nav['Servizi'] ?? 1) {
        $todo .= dashRightRow('Richieste di servizio', dashRightCount('SELECT COUNT(*) FROM service_inquiries WHERE user_id = ? AND is_read = 0', [$profileId]), '/dashboard_service_inquiries.php');
    }
    if ($isBandOrLabel && ($nav['Eventi'] ?? 1)) {
        $todo .= dashRightRow('Prenotazioni da confermare', dashRightCount("SELECT COUNT(*) FROM table_reservations WHERE user_id = ? AND status = 'pending'", [$profileId]), '/dashboard_reservations.php', 'warn');
    }

    $html = '<div class="dash-right-block"><div class="dash-right-title">Da gestire</div>' . $todo . '</div>';

    // --- Coda Social ---
    if ($queueOn && $heavy['queue'] !== null) {
        $q = $heavy['queue'];
        $b = dashRightRow('In attesa', $q['pending'], '/dashboard_social_queue.php?status=pending');
        $b .= dashRightRow('In elaborazione', $q['processing'], '/dashboard_social_queue.php?status=active');
        if ($q['stuck'] > 0) {
            $b .= dashRightRow('Ferme da oltre 2 ore', $q['stuck'], '/dashboard_social_queue.php?status=active', 'bad');
        }
        if ($q['suspended'] > 0) {
            $b .= dashRightRow('Sospese', $q['suspended'], '/dashboard_social_queue.php?status=suspended');
        }
        $b .= dashRightRow('In errore (30 giorni)', $q['failed'], '/dashboard_social_queue.php?status=failed', 'bad');
        $html .= '<div class="dash-right-block"><div class="dash-right-title">Coda Social</div>' . $b . '</div>';
    }

    // --- Automazioni e MCP ---
    $a = '';
    if ($heavy['api'] !== null) {
        $api = $heavy['api'];
        $a .= dashRightRow('Chiamate (24 ore)', $api['calls'], '/dashboard_api_tokens.php');
        $a .= dashRightRow('Errori (24 ore)', $api['errors'], '/dashboard_api_tokens.php', 'bad');
        $a .= '<div class="dash-right-sub">Ultimo utilizzo</div>';
        foreach ($api['tokens'] as $tk) {
            $name = $tk['mcp_name'] ?: $tk['label'];
            $a .= '<div class="dash-right-line"><span>' . e($name) . '</span><small>' . e(dashRelTime($tk['last_used_at'])) . '</small></div>';
        }
    } else {
        $a .= '<div class="dash-right-empty">Nessun token attivo.</div>';
    }
    $a .= '<a class="dash-right-link" href="/dashboard_api_tokens.php"><i class="fa-solid fa-code" aria-hidden="true"></i> Token e richieste</a>';
    if ($isAdmin) {
        // Solo amministratori del sito: la pagina è comunque protetta lato server da requireAdmin().
        $a .= '<a class="dash-right-link" href="/admin_mcp.php"><i class="fa-solid fa-server" aria-hidden="true"></i> Server MCP (Area Admin)</a>';
    }
    $html .= '<div class="dash-right-block"><div class="dash-right-title">Automazioni e MCP</div>' . $a . '</div>';

    // --- Integrazioni (voci prima nel menu account) ---
    if (!empty($ctx['integrations'])) {
        $in = '';
        foreach ($ctx['integrations'] as $it) {
            $isActive = ($ctx['active_tab'] ?? '') === $it['tab'];
            $in .= '<a class="dash-right-row' . ($isActive ? ' active' : '') . '" href="' . e($it['url']) . '">'
                 . '<span class="dash-right-int"><i class="' . e($it['icon']) . '" aria-hidden="true"></i>' . e($it['label']) . '</span>'
                 . ($it['on'] ? '<span class="dash-right-dot" title="Collegato" aria-label="Collegato"></span>' : '')
                 . '</a>';
        }
        $html .= '<div class="dash-right-block"><div class="dash-right-title">Integrazioni</div>' . $in . '</div>';
    }

    // --- Prossimi eventi ---
    if ($isBandOrLabel && ($nav['Eventi'] ?? 1)) {
        $ev = '';
        foreach ($heavy['events'] as $e1) {
            $where = trim(($e1['venue'] ?? '') . (!empty($e1['city']) ? ' · ' . $e1['city'] : ''), ' ·');
            $ev .= '<a class="dash-right-event" href="/dashboard_events.php"><b>' . e(formatLocalDateTime($e1['event_date'], $tzProfile, 'd/m H:i')) . '</b>'
                 . '<span>' . e($e1['title']) . '</span>' . ($where !== '' ? '<small>' . e($where) . '</small>' : '') . '</a>';
        }
        if ($ev === '') {
            $ev = '<div class="dash-right-empty">Nessun evento in programma.</div>';
        }
        $html .= '<div class="dash-right-block"><div class="dash-right-title">Prossimi eventi</div>' . $ev . '</div>';
    }

    return '<div id="dash-right-overlay" class="dash-right-overlay"></div>'
         . '<aside id="dash-right" class="dash-right" aria-label="Attività">'
         . '<div class="dash-right-head"><strong>Attività</strong><button type="button" id="dash-right-close" class="dash-side-close" aria-label="Chiudi">&times;</button></div>'
         . $html
         . '</aside>';
}
