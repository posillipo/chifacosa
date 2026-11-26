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
 * Tasti della barra in alto per gli amministratori (Social, Automazioni, Marketing) con le voci della barra secondaria.
 * Sono i blocchi «Coda Social», «Automazioni e MCP» e «Marketing» che prima stavano nella colonna destra / nel menu.
 * Ogni voce: ['label', 'url', 'icon', 'value' => int|null, 'tone' => '', 'text' => string|null (voce solo testo)].
 */
function dashTopGroups(array $ctx): array {
    if (empty($ctx['is_admin'])) {
        return [];
    }
    $profileId = (int) $ctx['profile_id'];
    $queueOn = !empty($ctx['queue_on']);
    $heavy = dashRightHeavyData($profileId, !empty($ctx['is_band_or_label']), $queueOn);
    $it = fn (string $label, string $url, string $icon, ?int $value = null, string $tone = '') => ['label' => $label, 'url' => $url, 'icon' => $icon, 'value' => $value, 'tone' => $tone, 'text' => null];

    $social = [];
    if ($queueOn && $heavy['queue'] !== null) {
        $q = $heavy['queue'];
        $social[] = $it('Coda Social', '/dashboard_social_queue.php?status=all', 'fa-solid fa-share-nodes');
        $social[] = $it('In attesa', '/dashboard_social_queue.php?status=pending', 'fa-regular fa-hourglass-half', $q['pending']);
        $social[] = $it('In elaborazione', '/dashboard_social_queue.php?status=active', 'fa-solid fa-gear', $q['processing']);
        if ($q['stuck'] > 0) {
            $social[] = $it('Ferme da oltre 2 ore', '/dashboard_social_queue.php?status=active', 'fa-solid fa-triangle-exclamation', $q['stuck'], 'bad');
        }
        if ($q['suspended'] > 0) {
            $social[] = $it('Sospese', '/dashboard_social_queue.php?status=suspended', 'fa-solid fa-pause', $q['suspended']);
        }
        $social[] = $it('In errore (30 giorni)', '/dashboard_social_queue.php?status=failed', 'fa-solid fa-circle-xmark', $q['failed'], 'bad');
    } else {
        $social[] = $it('Coda Social', '/dashboard_social_queue.php?status=all', 'fa-solid fa-share-nodes');
    }

    $auto = [];
    if ($heavy['api'] !== null) {
        $auto[] = $it('Chiamate (24 ore)', '/dashboard_api_tokens.php', 'fa-solid fa-arrow-right-arrow-left', $heavy['api']['calls']);
        $auto[] = $it('Errori (24 ore)', '/dashboard_api_tokens.php', 'fa-solid fa-circle-xmark', $heavy['api']['errors'], 'bad');
    }
    $auto[] = $it('Token e richieste', '/dashboard_api_tokens.php', 'fa-solid fa-code');
    $auto[] = $it('Server MCP', '/admin_mcp.php', 'fa-solid fa-server');
    if ($heavy['api'] !== null) {
        foreach ($heavy['api']['tokens'] as $tk) {
            $auto[] = ['label' => '', 'url' => '', 'icon' => '', 'value' => null, 'tone' => '', 'text' => ($tk['mcp_name'] ?: $tk['label']) . ' · ' . dashRelTime($tk['last_used_at'])];
        }
    }

    require_once __DIR__ . '/marketing.php';
    $marketing = [$it('Marketing', '/dashboard_marketing.php', 'fa-solid fa-bullhorn', marketingActiveCount($profileId))];

    return [
        'social' => ['label' => 'Social', 'icon' => 'fa-solid fa-share-nodes', 'tabs' => ['social_queue'], 'items' => $social],
        'automazioni' => ['label' => 'Automazioni', 'icon' => 'fa-solid fa-robot', 'tabs' => ['api_tokens', 'mcp'], 'items' => $auto],
        'marketing' => ['label' => 'Marketing', 'icon' => 'fa-solid fa-bullhorn', 'tabs' => ['marketing'], 'items' => $marketing],
    ];
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

    // I dati "pesanti" (coda, API) servono solo agli amministratori.
    $heavy = $isAdmin ? dashRightHeavyData($profileId, $isBandOrLabel, $queueOn) : ['queue' => null, 'api' => null, 'events' => []];

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

    // Community per tutti; "Da gestire" (i contatori sono anche nel menu di sinistra e nella barra in alto) e gli strumenti solo per gli amministratori.
    $html = renderDashCommunity($profileId);
    if ($isAdmin) {
        $html .= '<div class="dash-right-block"><div class="dash-right-title">Da gestire</div>' . $todo . '</div>';
    }

    // --- Coda Social: ora è il tasto «Social» della barra in alto (vedi dashTopGroups()) ---
    if (false && $isAdmin && $queueOn && $heavy['queue'] !== null) {
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

    // --- Automazioni e MCP: ora è il tasto «Automazioni» della barra in alto (vedi dashTopGroups()) ---
    if (false && $isAdmin) {
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
    }

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

    return '<div id="dash-right-overlay" class="dash-right-overlay"></div>'
         . '<aside id="dash-right" class="dash-right" aria-label="Attività">'
         . '<div class="dash-right-head"><strong>' . ($isAdmin ? 'Attività' : 'Community') . '</strong><button type="button" id="dash-right-close" class="dash-side-close" aria-label="Chiudi">&times;</button></div>'
         . $html
         . '</aside>';
}


/**
 * Blocco "Prossimi eventi" per il menu laterale sinistro (solo profili con la sezione Eventi): i prossimi 3 eventi.
 */
function renderDashSideEvents(int $profileId, array $tzProfile): string {
    $rows = [];
    try {
        $st = getDB()->prepare('SELECT id, title, venue, city, event_date FROM events WHERE user_id = ? AND event_date >= NOW() ORDER BY event_date ASC LIMIT 3');
        $st->execute([$profileId]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    $html = '<div class="dash-side-title">Prossimi eventi</div>';
    foreach ($rows as $e1) {
        $where = trim(($e1['venue'] ?? '') . (!empty($e1['city']) ? ' · ' . $e1['city'] : ''), ' ·');
        $html .= '<a class="dash-side-event" href="/dashboard_events.php"><b>' . e(formatLocalDateTime($e1['event_date'], $tzProfile, 'd/m H:i')) . '</b>'
               . '<span>' . e($e1['title']) . '</span>' . ($where !== '' ? '<small>' . e($where) . '</small>' : '') . '</a>';
    }
    if (!$rows) {
        $html .= '<div class="dash-side-empty">Nessun evento in programma.</div>';
    }
    return $html;
}


/**
 * Dati del blocco Community: conteggi e ultimi 10 follower / seguiti del profilo attivo.
 * Follower = profili che ti seguono + iscritti via email (verificati); Seguiti = profili che segui.
 */
function dashCommunityData(int $profileId): array {
    $db = getDB();
    $out = ['followers_n' => 0, 'following_n' => 0, 'followers' => [], 'following' => []];
    try {
        $accN = dashRightCount('SELECT COUNT(*) FROM account_follows WHERE followed_user_id = ?', [$profileId]);
        $mailN = dashRightCount('SELECT COUNT(*) FROM followers WHERE user_id = ? AND verified = 1', [$profileId]);
        $out['followers_n'] = $accN + $mailN;
        $out['following_n'] = dashRightCount('SELECT COUNT(*) FROM account_follows WHERE follower_user_id = ?', [$profileId]);

        $st = $db->prepare('SELECT u.id, u.slug, p.display_name, p.avatar_path, af.created_at d,
                (SELECT COUNT(*) FROM account_follows b WHERE b.follower_user_id = ? AND b.followed_user_id = u.id) AS i_follow
            FROM account_follows af JOIN users u ON u.id = af.follower_user_id JOIN profiles p ON p.user_id = u.id
            WHERE af.followed_user_id = ? ORDER BY af.created_at DESC, af.id DESC LIMIT 10');
        $st->execute([$profileId, $profileId]);
        foreach ($st->fetchAll() as $r) {
            $out['followers'][] = ['kind' => 'profile', 'id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['display_name'] ?: $r['slug'],
                'avatar' => $r['avatar_path'], 'd' => $r['d'], 'i_follow' => (int) $r['i_follow'] > 0];
        }
        $st = $db->prepare('SELECT email, first_name, last_name, created_at d FROM followers WHERE user_id = ? AND verified = 1 ORDER BY created_at DESC LIMIT 10');
        $st->execute([$profileId]);
        foreach ($st->fetchAll() as $r) {
            $nm = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $out['followers'][] = ['kind' => 'email', 'name' => $nm !== '' ? $nm : $r['email'], 'sub' => $nm !== '' ? $r['email'] : 'iscritto via email', 'd' => $r['d']];
        }
        usort($out['followers'], fn($a, $b) => strcmp((string) $b['d'], (string) $a['d']));
        $out['followers'] = array_slice($out['followers'], 0, 10);

        $st = $db->prepare('SELECT u.id, u.slug, p.display_name, p.avatar_path, af.created_at d
            FROM account_follows af JOIN users u ON u.id = af.followed_user_id JOIN profiles p ON p.user_id = u.id
            WHERE af.follower_user_id = ? ORDER BY af.created_at DESC, af.id DESC LIMIT 10');
        $st->execute([$profileId]);
        foreach ($st->fetchAll() as $r) {
            $out['following'][] = ['kind' => 'profile', 'id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['display_name'] ?: $r['slug'],
                'avatar' => $r['avatar_path'], 'd' => $r['d']];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

function dashCommunityAvatar(array $r): string {
    if (!empty($r['avatar'])) {
        return '<img class="dash-comm-av" src="/' . e(ltrim($r['avatar'], '/')) . '" alt="" loading="lazy">';
    }
    return '<span class="dash-comm-av dash-comm-ph">' . e(mb_strtoupper(mb_substr((string) $r['name'], 0, 1))) . '</span>';
}

function dashCommunityList(array $rows, string $emptyText, bool $withFollowBack): string {
    if (!$rows) {
        return '<div class="dash-right-empty">' . e($emptyText) . '</div>';
    }
    $html = '';
    foreach ($rows as $r) {
        $inner = dashCommunityAvatar($r) . '<span class="dash-comm-txt"><b>' . e($r['name']) . '</b><small>'
               . e(($r['kind'] === 'profile' ? '@' . $r['slug'] : ($r['sub'] ?? '')) . ' · ' . dashRelTime($r['d'])) . '</small></span>';
        $html .= '<div class="dash-comm-row">';
        $html .= $r['kind'] === 'profile'
            ? '<a class="dash-comm-link" href="/dashboard_profile_view.php?slug=' . e(rawurlencode($r['slug'])) . '">' . $inner . '</a>'
            : '<div class="dash-comm-link">' . $inner . '</div>';
        if ($withFollowBack && $r['kind'] === 'profile' && empty($r['i_follow'])) {
            $html .= '<form method="post" action="/follow_account.php" class="dash-comm-follow">' . csrfField()
                   . '<input type="hidden" name="user_id" value="' . (int) $r['id'] . '"><input type="hidden" name="action" value="follow">'
                   . '<input type="hidden" name="redirect" value="' . e((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard.php')) . '">'
                   . '<button type="submit" title="Segui anche tu" aria-label="Segui ' . e($r['name']) . '"><i class="fa-solid fa-user-plus"></i></button></form>';
        }
        $html .= '</div>';
    }
    return $html;
}

/** Blocco "Community" della colonna destra: scorciatoie, ultimi 10 follower e ultimi 10 seguiti, con schede. */
function renderDashCommunity(int $profileId): string {
    $c = dashCommunityData($profileId);
    $html = '<div class="dash-right-block dash-comm" data-comm-box><div class="dash-right-title">Community</div>'
          . '<div class="dash-comm-tiles">'
          . '<a href="/dashboard_followers.php" class="dash-comm-tile"><i class="fa-solid fa-users"></i><b>' . (int) $c['followers_n'] . '</b><span>Follower</span></a>'
          . '<a href="/dashboard_following.php" class="dash-comm-tile"><i class="fa-solid fa-user-check"></i><b>' . (int) $c['following_n'] . '</b><span>Seguiti</span></a>'
          . '<a href="/dashboard_invite.php" class="dash-comm-tile dash-comm-invite"><i class="fa-solid fa-user-plus"></i><b>+</b><span>Invita</span></a>'
          . '<a href="/dashboard_directory.php" class="dash-comm-tile"><i class="fa-solid fa-map-location-dot"></i><b><i class="fa-solid fa-magnifying-glass"></i></b><span>Directory</span></a>'
          . '</div>'
          . '<div class="dash-comm-tabs" role="tablist">'
          . '<button type="button" class="active" role="tab" aria-selected="true" data-comm-tab="followers">Ultimi follower</button>'
          . '<button type="button" role="tab" aria-selected="false" data-comm-tab="following">Ultimi seguiti</button></div>'
          . '<div class="dash-comm-body" data-comm-body>'
          . '<div class="dash-comm-panel" data-comm-panel="followers">' . dashCommunityList($c['followers'], 'Ancora nessun follower: condividi il tuo link con Invita.', true)
          . '<a class="dash-right-link" href="/dashboard_followers.php">Vedi tutti i follower</a></div>'
          . '<div class="dash-comm-panel" data-comm-panel="following" hidden>' . dashCommunityList($c['following'], 'Non segui ancora nessun profilo: usa la ricerca in alto.', false)
          . '<a class="dash-right-link" href="/dashboard_following.php">Vedi tutti i seguiti</a></div>'
          . '</div>'
          . '<div class="dash-comm-grip" data-comm-grip role="separator" aria-orientation="horizontal" tabindex="0" '
          . 'title="Trascina per alzare o abbassare l\'elenco (doppio clic: altezza iniziale)" aria-label="Cambia l\'altezza dell\'elenco"><span></span></div>'
          . '</div>';
    static $js = false;
    if (!$js) {
        $js = true;
        $html .= <<<'JS'
<script>
(function () {
  var TAB_KEY = 'dashCommTab', H_KEY = 'dashCommH', DEF_H = 300, MIN_H = 120, MAX_H = 640;
  function get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function put(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
  function showTab(box, k) {
    box.querySelectorAll('[data-comm-tab]').forEach(function (x) {
      var on = x.getAttribute('data-comm-tab') === k;
      x.classList.toggle('active', on);
      x.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    box.querySelectorAll('[data-comm-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-comm-panel') !== k; });
  }
  function setH(body, h, save) {
    h = Math.max(MIN_H, Math.min(MAX_H, Math.round(h)));
    body.style.maxHeight = h + 'px'; // altezza massima: con pochi elementi l'elenco resta compatto
    if (save) put(H_KEY, String(h));
    return h;
  }
  document.querySelectorAll('.dash-comm[data-comm-box]').forEach(function (box) {
    // La scheda scelta (Follower / Seguiti) resta attiva anche dopo aver aperto un profilo e tornato indietro.
    var saved = get(TAB_KEY);
    if (saved === 'followers' || saved === 'following') showTab(box, saved);
    var body = box.querySelector('[data-comm-body]'), grip = box.querySelector('[data-comm-grip]');
    if (!body || !grip) return;
    var h0 = parseInt(get(H_KEY), 10);
    setH(body, h0 > 0 ? h0 : DEF_H, false);
    var startY = 0, startH = 0;
    grip.addEventListener('pointerdown', function (ev) {
      startY = ev.clientY; startH = parseInt(body.style.maxHeight, 10) || DEF_H;
      grip.setPointerCapture(ev.pointerId); grip.classList.add('drag'); ev.preventDefault();
    });
    grip.addEventListener('pointermove', function (ev) {
      if (!grip.classList.contains('drag')) return;
      setH(body, startH + (ev.clientY - startY), false);
    });
    function end() { if (grip.classList.contains('drag')) { grip.classList.remove('drag'); put(H_KEY, String(parseInt(body.style.maxHeight, 10) || DEF_H)); } }
    grip.addEventListener('pointerup', end); grip.addEventListener('pointercancel', end);
    grip.addEventListener('dblclick', function () { setH(body, DEF_H, true); });
    grip.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { ev.preventDefault(); setH(body, (parseInt(body.style.maxHeight, 10) || DEF_H) + (ev.key === 'ArrowDown' ? 40 : -40), true); }
    });
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-comm-tab]');
    if (!b) return;
    var k = b.getAttribute('data-comm-tab');
    showTab(b.closest('.dash-comm'), k);
    put(TAB_KEY, k);
  });
})();
</script>
JS;
    }
    return $html;
}
