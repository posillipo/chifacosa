<?php
// Nuova interfaccia della dashboard ("Studio"): una veste grafica parallela, mobile first, che riusa login, database e funzioni
// dell'attuale dashboard senza modificarla. Percorso non collegato dal sito e escluso dai motori di ricerca.
// Per ora: Panoramica (index.php) e Feed (feed.php). Le altre voci del menu portano alle pagine della dashboard attuale.
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/dash_right.php';
require_once __DIR__ . '/../../src/metricool_queue.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const STUDIO_PAGE_SIZE = 20;

// Contesto della pagina: utente, profilo attivo e contatori.
function studioBoot(): array {
    $user = requireLogin();
    $profile = getActingProfile($user);
    $pid = (int) $profile['id'];
    $db = getDB();
    $one = function (string $sql, array $p) use ($db): int {
        try { $st = $db->prepare($sql); $st->execute($p); return (int) $st->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    $isBandOrLabel = in_array($profile['account_type'] ?? 'band', ['band', 'label'], true);
    $nav = array_column(getAllProfileNavigationMenu($pid, $profile['slug']), 'is_visible', 'name');
    foreach (getSiteDisabledNavKeys() as $k) {
        foreach (PUBLIC_NAV_ITEM_KEYS as $name => $key) { if ($key === $k) { $nav[$name] = 0; } }
    }
    $tasks = [
        'contacts'  => $one('SELECT COUNT(*) FROM contact_requests WHERE user_id = ? AND is_read = 0', [$pid]),
        'messages'  => $one('SELECT COUNT(*) FROM direct_messages WHERE recipient_id = ? AND read_at IS NULL', [$pid]),
        'inquiries' => ($nav['Servizi'] ?? 1) ? $one('SELECT COUNT(*) FROM service_inquiries WHERE user_id = ? AND is_read = 0', [$pid]) : 0,
        'bookings'  => ($isBandOrLabel && ($nav['Eventi'] ?? 1)) ? $one("SELECT COUNT(*) FROM table_reservations WHERE user_id = ? AND status = 'pending'", [$pid]) : 0,
    ];
    $isAdmin = !empty($user['is_admin']);
    $heavy = $isAdmin ? dashRightHeavyData($pid, $isBandOrLabel, metricoolIsEnabled($pid)) : ['queue' => null, 'api' => null];
    return compact('user', 'profile', 'pid', 'isBandOrLabel', 'nav', 'tasks', 'isAdmin', 'heavy');
}

function studioAvatar(?string $path, string $name, int $size = 32): string {
    if ($path) {
        return '<img class="st-av" style="width:' . $size . 'px;height:' . $size . 'px" src="/' . e(ltrim($path, '/')) . '" alt="" loading="lazy">';
    }
    return '<span class="st-av st-ph" style="width:' . $size . 'px;height:' . $size . 'px">' . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
}

// Voci del menu laterale: gruppi come da progetto, con le stesse regole di visibilità della dashboard attuale.
function studioMenu(array $c): array {
    $p = $c['profile'];
    $nav = $c['nav'];
    $band = $c['isBandOrLabel'];
    $show = fn(string $k) => (bool) ($nav[$k] ?? 1);
    $g = [];
    $g['Panoramica'] = [['Dashboard', '/studio/', 'fa-gauge-high', 'home']];
    $content = [['Feed', '/studio/feed.php', 'fa-list', 'feed']];
    if (siteToolEnabled('programmati')) { $content[] = ['Programmati', '/dashboard_schedule.php', 'fa-hourglass-half', null]; }
    $content[] = ['Libreria', '/dashboard_albums.php', 'fa-images', null];
    $g['Contenuti'] = $content;
    $pub = [['Community', '/dashboard_followers.php', 'fa-users', null, 0], ['Messaggi', '/dashboard_messages.php', 'fa-comment-dots', null, $c['tasks']['messages']]];
    if ($show('Servizi')) { $pub[] = ['Richieste', '/dashboard_service_inquiries.php', 'fa-inbox', null, $c['tasks']['inquiries']]; }
    $pub[] = ['Contatti Web', '/dashboard_contacts.php', 'fa-address-book', null, $c['tasks']['contacts']];
    $g['Pubblico'] = $pub;
    $biz = [];
    if ($band && $show('Eventi')) { $biz[] = ['Prenotazioni', '/dashboard_reservations.php', 'fa-calendar-check', null, $c['tasks']['bookings']]; }
    if ($show('Offerte')) { $biz[] = ['Offerte', '/dashboard_offers.php', 'fa-percent', null, 0]; }
    if ($show('Servizi')) { $biz[] = ['Servizi', '/dashboard_services.php', 'fa-briefcase', null, 0]; }
    if ($show('Menù')) { $biz[] = ['Menu', '/dashboard_menu.php', 'fa-utensils', null, 0]; }
    if ($biz) { $g['Business'] = $biz; }
    $int = [];
    if ($band) {
        $int[] = ['Spotify', '/dashboard_spotify.php', 'fa-brands fa-spotify', null, 0, !empty($p['spotify_artist_id'])];
        $int[] = ['YouTube', '/dashboard_youtube.php', 'fa-brands fa-youtube', null, 0, !empty($p['youtube_channel_id'])];
        $int[] = ['Podcast', '/dashboard_podcast.php', 'fa-solid fa-microphone', null, 0, !empty($p['spotify_show_id'])];
    }
    if ($c['isAdmin']) { $int[] = ['Telegram', '/dashboard_telegram.php', 'fa-brands fa-telegram', null, 0, !empty($p['telegram_chat_id'])]; }
    if ($int) { $g['Integrazioni'] = $int; }
    $g['Impostazioni'] = [['Profilo', '/dashboard_profile.php', 'fa-id-card', null], ['Impostazioni', '/dashboard_nav_menu.php', 'fa-sliders', null]];
    return $g;
}

function studioHead(array $c, string $title, string $active): void {
    $p = $c['profile'];
    $brand = strtoupper(siteName());
    ?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#7C5CFF">
<title><?= e($title) ?> — <?= e(siteName()) ?> Studio</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= assetUrl('/studio/assets/studio.css') ?>">
</head>
<body class="st-body">
<header class="st-top">
  <button type="button" class="st-iconbtn st-burger" id="st-burger" aria-label="Apri il menu" aria-controls="st-side" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
  <a class="st-logo" href="/studio/"><?= e($brand) ?></a>
  <div class="st-search" id="st-search">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="search" id="st-search-input" placeholder="Cerca profili…" autocomplete="off" aria-label="Cerca profili" maxlength="60">
    <div class="st-search-list" id="st-search-list" hidden></div>
  </div>
  <nav class="st-topright">
    <a class="st-iconbtn" href="/dashboard_contacts.php" aria-label="Notifiche" title="Contatti Web"><i class="fa-solid fa-bell"></i><?= $c['tasks']['contacts'] > 0 ? '<b class="st-badge">' . ($c['tasks']['contacts'] > 9 ? '9+' : $c['tasks']['contacts']) . '</b>' : '' ?></a>
    <a class="st-iconbtn" href="/dashboard_messages.php" aria-label="Messaggi" title="Messaggi"><i class="fa-solid fa-comment-dots"></i><?= $c['tasks']['messages'] > 0 ? '<b class="st-badge">' . ($c['tasks']['messages'] > 9 ? '9+' : $c['tasks']['messages']) . '</b>' : '' ?></a>
    <a class="st-me" href="/<?= e($p['slug']) ?>" target="_blank" rel="noopener" title="Il tuo sito"><?= studioAvatar($p['avatar_path'] ?? null, (string) ($p['display_name'] ?? '?'), 34) ?></a>
    <a class="st-btn st-btn-ghost st-exit" href="/logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Esci</span></a>
  </nav>
</header>
<div class="st-overlay" id="st-overlay"></div>
<aside class="st-side" id="st-side" aria-label="Menu">
  <nav>
  <?php foreach (studioMenu($c) as $group => $items): ?>
    <div class="st-group"><?= e($group) ?></div>
    <?php foreach ($items as $it): [$label, $url, $icon, $key] = $it; $badge = (int) ($it[4] ?? 0); $dot = array_key_exists(5, $it) ? $it[5] : null; ?>
      <a class="st-link<?= $key === $active ? ' active' : '' ?>" href="<?= e($url) ?>" title="<?= e($label) ?>">
        <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span>
        <?php if ($badge > 0): ?><b class="st-badge st-badge-in"><?= $badge ?></b><?php endif; ?>
        <?php if ($dot !== null): ?><i class="st-dot<?= $dot ? ' on' : '' ?>" title="<?= $dot ? 'Connesso' : 'Non connesso' ?>"></i><?php endif; ?>
      </a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </nav>
  <div class="st-sidefoot">
    <a class="st-link" href="/dashboard_timeline.php"><i class="fa-solid fa-table-columns"></i><span>Dashboard classica</span></a>
    <button type="button" class="st-link st-collapse" id="st-collapse" aria-label="Riduci il menu"><i class="fa-solid fa-angles-left"></i><span>Riduci</span></button>
  </div>
</aside>
<div class="st-layout">
<main class="st-main" id="st-main">
<?php
}

// Colonna destra: Community e Da fare.
function studioAside(array $c): string {
    $comm = dashCommunityData($c['pid']);
    $t = $c['tasks'];
    $row = fn(string $label, int $n, string $url, bool $danger = false) =>
        '<a class="st-todo' . ($n > 0 ? ' on' : '') . '" href="' . e($url) . '"><span>' . e($label) . '</span><b class="' . ($n > 0 && $danger ? 'danger' : '') . '">' . $n . '</b></a>';
    $h = '<aside class="st-aside">';
    $h .= '<section class="st-card"><h3>Community</h3><div class="st-kpis">'
        . '<a href="/dashboard_followers.php"><b>' . (int) $comm['followers_n'] . '</b><span>Follower</span></a>'
        . '<a href="/dashboard_following.php"><b>' . (int) $comm['following_n'] . '</b><span>Seguiti</span></a></div>';
    foreach (array_slice($comm['followers'], 0, 4) as $f) {
        $inner = studioAvatar($f['avatar'] ?? null, (string) $f['name'], 32) . '<span><b>' . e($f['name']) . '</b><small>' . e(($f['kind'] === 'profile' ? '@' . $f['slug'] : ($f['sub'] ?? '')) . ' · ' . dashRelTime($f['d'])) . '</small></span>';
        $h .= $f['kind'] === 'profile' ? '<a class="st-person" href="/dashboard_profile_view.php?slug=' . e(rawurlencode($f['slug'])) . '">' . $inner . '</a>' : '<div class="st-person">' . $inner . '</div>';
    }
    if (!$comm['followers']) { $h .= '<p class="st-muted">Ancora nessun follower: condividi il tuo link.</p>'; }
    $h .= '<a class="st-more" href="/dashboard_followers.php">Vedi tutti i follower →</a></section>';
    $h .= '<section class="st-card"><h3>Da fare</h3>'
        . $row('Contatti non letti', $t['contacts'], '/dashboard_contacts.php')
        . $row('Messaggi non letti', $t['messages'], '/dashboard_messages.php')
        . $row('Richieste di servizio', $t['inquiries'], '/dashboard_service_inquiries.php')
        . $row('Prenotazioni da confermare', $t['bookings'], '/dashboard_reservations.php');
    if ($c['isAdmin'] && $c['heavy']['api'] !== null) {
        $h .= $row('Chiamate (24 ore)', (int) $c['heavy']['api']['calls'], '/dashboard_api_tokens.php')
            . $row('Errori (24 ore)', (int) $c['heavy']['api']['errors'], '/dashboard_api_tokens.php', true);
    }
    return $h . '</section></aside>';
}

function studioFoot(string $aside = ''): void {
    ?>
</main>
<?= $aside ?>
</div>
<div class="st-toast" id="st-toast" role="status" aria-live="polite"></div>
<script src="<?= assetUrl('/studio/assets/studio.js') ?>"></script>
</body>
</html>
<?php
}

// Tipo del contenuto per badge e filtri.
function studioType(string $tipo): array {
    return match ($tipo) {
        'blog'     => ['articolo', 'Articolo', 'violet'],
        'video'    => ['video', 'Video', 'blue'],
        'pensiero' => ['aggiornamento', 'Aggiornamento', 'grey'],
        'evento'   => ['altro', 'Evento', 'grey'],
        'album_foto' => ['altro', 'Album', 'grey'],
        'servizio' => ['altro', 'Servizio', 'grey'],
        'brano'    => ['altro', 'Brano', 'grey'],
        default    => ['altro', str_ends_with($tipo, '_favorita') || str_ends_with($tipo, '_favorito') ? 'Che amo' : 'Contenuto', 'grey'],
    };
}

// Stato: Errore (coda social in errore) / Programmato / Solo io / Pubblicato.
function studioStatus(array $item): array {
    $q = $item['x_queue'] ?? null;
    if ($q && ($q['status'] ?? '') === 'failed') { return ['Errore', 'danger']; }
    if (!empty($item['data']) && strtotime($item['data']) > time()) {
        return ['Programmato · ' . formatLocalDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null], 'd/m/Y H:i'), 'warn'];
    }
    if (isset($item['x_pub']) && (int) $item['x_pub'] === 0) { return ['Solo io', 'grey']; }
    return ['Pubblicato', 'ok'];
}

function studioCard(array $item, string $viewerSlug): string {
    [$ftype, $tlabel, $tone] = studioType((string) $item['tipo']);
    [$slabel, $stone] = studioStatus($item);
    $mine = $item['user_slug'] === $viewerSlug;
    $cover = $item['cover_thumb'] ?? $item['cover'] ?? null;
    $coverSrc = $cover ? (str_starts_with($cover, 'http') ? $cover : '/' . ltrim($cover, '/')) : null;
    $href = $mine ? ($item['edit_url'] ?? $item['url']) : $item['url'];
    $title = (string) $item['titolo'];
    $when = !empty($item['data']) ? italianLongDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null]) : '';
    $thumb = $coverSrc
        ? '<img src="' . e($coverSrc) . '" alt="" loading="lazy">'
        : '<span class="st-thumb-ph">' . e(mb_strtoupper(mb_substr($tlabel, 0, 1))) . '</span>';
    $share = e(json_encode(['t' => $title, 'u' => siteUrl((string) $item['url'])], JSON_UNESCAPED_UNICODE));
    $h = '<article class="st-item" data-type="' . e($ftype) . '" data-q="' . e(mb_strtolower($title . ' ' . $item['display_name'])) . '">';
    $h .= '<a class="st-thumb" href="' . e($href) . '"' . ($mine ? '' : ' target="_blank" rel="noopener"') . '>' . $thumb . '</a>';
    $h .= '<div class="st-itembody"><div class="st-meta1"><span class="st-type st-' . $tone . '">' . e($tlabel) . '</span>'
        . '<span class="st-status st-s-' . $stone . '">' . e($slabel) . '</span></div>';
    $h .= '<h4 class="st-title"><a href="' . e($href) . '"' . ($mine ? '' : ' target="_blank" rel="noopener"') . '>' . e($title) . '</a></h4>';
    $h .= '<div class="st-meta2">' . e($item['display_name']) . ($mine ? ' (tu)' : '') . ($when !== '' ? ' · ' . e($when) : '') . '</div>';
    $h .= '<div class="st-actions">';
    if ($mine) { $h .= '<a class="st-act primary" href="' . e((string) ($item['edit_url'] ?? $item['url'])) . '"><i class="fa-solid fa-pen"></i><span>Modifica</span></a>'; }
    $h .= '<a class="st-act" href="' . e((string) $item['url']) . '" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>Pagina pubblica</span></a>';
    $h .= '<button type="button" class="st-act" data-share="' . $share . '"><i class="fa-solid fa-share-nodes"></i><span>Condividi</span></button>';
    $h .= '</div></div></article>';
    return $h;
}

// Elementi del Feed (propri e dei profili seguiti) a partire da $offset.
function studioFeed(array $c, int $offset): array {
    $followed = getFollowedUserIds($c['pid']);
    $items = getTimelineFeedForUsers(array_merge($followed, [$c['pid']]), STUDIO_PAGE_SIZE, $offset, $c['pid']);
    return dashboardFeedEnrich($items, $c['pid'], $c['profile']['slug']);
}
