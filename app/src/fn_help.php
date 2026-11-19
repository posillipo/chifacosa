<?php
// Guide delle pagine: il "?" accanto al titolo apre un popup con un testo breve; "Approfondisci" porta
// all'approfondimento (guida.php, oppure un indirizzo esterno impostato da Admin). I testi stanno nella
// tabella page_help (modificabili da Admin → Guide delle pagine), non nel codice delle pagine.
// REGOLA: quando cambi il comportamento di una pagina, aggiorna anche il suo testo di guida
// (nuova migrazione "INSERT ... ON DUPLICATE KEY UPDATE" oppure Admin → Guide delle pagine) e la documentazione.

// Segnaposto usabili nei testi: {site_name}, {slug}, {album_max_photos}, {service_max_photos}, {max_video_mb}.
function pageHelpPlaceholders(): array {
    $slug = '';
    try {
        $u = function_exists('currentUser') ? currentUser() : null;
        $p = $u && function_exists('getActingProfile') ? getActingProfile($u) : null;
        $slug = (string) ($p['slug'] ?? ($u['slug'] ?? ''));
    } catch (Throwable $e) {
    }
    return [
        '{site_name}'          => siteName(),
        '{slug}'               => $slug,
        '{album_max_photos}'   => defined('ALBUM_MAX_PHOTOS') ? (string) ALBUM_MAX_PHOTOS : '50',
        '{service_max_photos}' => defined('SERVICE_MAX_PHOTOS') ? (string) SERVICE_MAX_PHOTOS : '20',
        '{max_video_mb}'       => defined('VIDEO_MAX_BYTES') ? (string) (int) (VIDEO_MAX_BYTES / 1048576) : '200',
    ];
}

// Testo → HTML sicuro: paragrafi separati da riga vuota, **grassetto**, `codice`, a capo semplice con <br>.
function pageHelpFormat(?string $text): string {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    $text = strtr($text, pageHelpPlaceholders());
    $out = '';
    foreach (preg_split('/\n\s*\n/', $text) as $para) {
        $h = e(trim($para));
        $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
        $h = preg_replace('/`([^`]+)`/', '<code>$1</code>', $h);
        $out .= '<p>' . nl2br($h, false) . '</p>';
    }
    return $out;
}

// Riga della guida per una pagina (null se manca o se la tabella non esiste ancora: la pagina funziona lo stesso).
function pageHelpGet(string $key): ?array {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $st = getDB()->prepare('SELECT page_key, title, body, more, doc_url FROM page_help WHERE page_key = ?');
        $st->execute([$key]);
        $cache[$key] = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $cache[$key] = null;
    }
    return $cache[$key];
}

function pageHelpAll(): array {
    try {
        return getDB()->query('SELECT page_key, title, body, more, doc_url FROM page_help ORDER BY sort_order, title')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// Indirizzo di "Approfondisci": quello impostato da Admin, altrimenti la pagina guida interna (solo se c'è un approfondimento).
function pageHelpMoreUrl(array $h): ?string {
    $u = trim((string) ($h['doc_url'] ?? ''));
    if ($u !== '' && (preg_match('#^https?://#i', $u) || ($u[0] === '/' && substr($u, 0, 2) !== '//'))) {
        return $u;
    }
    return trim((string) ($h['more'] ?? '')) !== '' ? '/guida.php?p=' . rawurlencode($h['page_key']) : null;
}

// Guida di una pagina, sempre disponibile: se manca il testo (o la tabella), mostra un messaggio generico.
function pageHelpEntryOrFallback(string $key, ?string $fallbackTitle): array {
    $h = pageHelpGet($key);
    if ($h && trim((string) $h['body']) !== '') {
        return $h;
    }
    $isAdmin = false;
    try {
        $u = function_exists('currentUser') ? currentUser() : null;
        $isAdmin = !empty($u['is_admin']);
    } catch (Throwable $e) {
    }
    return [
        'page_key' => $key,
        'title'    => $fallbackTitle ?: 'Guida',
        'body'     => $isAdmin
            ? 'Per questa pagina non c\'è ancora una guida. Puoi scriverla da Admin → Guide delle pagine (chiave: `' . $key . '`).'
            : 'La guida di questa pagina è in preparazione.',
        'more'     => null,
        'doc_url'  => null,
    ];
}

// Pulsante "?" con il suo popup (da mettere accanto a un titolo già presente nella pagina).
function renderPageHelpButton(?string $key = null, ?string $fallbackTitle = null): string {
    $key = $key ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    $h = pageHelpEntryOrFallback($key, $fallbackTitle);
    $title = ($h['title'] ?? '') !== '' ? $h['title'] : ($fallbackTitle ?: 'Guida');
    $more = pageHelpMoreUrl($h);
    $id = 'ph-' . preg_replace('/[^a-z0-9_]/i', '', $key);
    $html = '<button type="button" class="page-help-btn" aria-label="Aiuto su questa pagina" aria-haspopup="dialog" data-help-open="' . $id . '">?</button>'
          . '<dialog class="page-help-dialog" id="' . $id . '" aria-labelledby="' . $id . '-t">'
          . '<div class="page-help-inner"><h2 id="' . $id . '-t">' . e($title) . '</h2>' . pageHelpFormat($h['body'])
          . '<div class="page-help-actions">'
          . ($more ? '<a class="page-help-more" href="' . e($more) . '"' . (preg_match('#^https?://#i', $more) ? ' target="_blank" rel="noopener"' : '') . '>Approfondisci →</a>' : '<span></span>')
          . '<button type="button" class="btn secondary" data-help-close>Chiudi</button></div></div></dialog>';
    static $js = false;
    if (!$js) {
        $js = true;
        $html .= '<script>(function(){document.addEventListener("click",function(ev){var o=ev.target.closest("[data-help-open]");'
               . 'if(o){var d=document.getElementById(o.getAttribute("data-help-open"));if(d&&d.showModal){d.showModal();}return;}'
               . 'if(ev.target.closest("[data-help-close]")){var dd=ev.target.closest("dialog");if(dd)dd.close();return;}'
               . 'if(ev.target.tagName==="DIALOG"){ev.target.close();}});})();</script>';
    }
    return $html;
}

// Intestazione di pagina: titolo + "?". Compare sempre, tranne nelle pagine che hanno già un proprio titolo
// con il pulsante (dichiarano $pageHelpInline = true prima di includere _dash_header.php).
function renderPageHelpHead(?string $key = null, ?string $fallbackTitle = null): string {
    if (!empty($GLOBALS['pageHelpInline'])) {
        return '';
    }
    $key = $key ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    $h = pageHelpEntryOrFallback($key, $fallbackTitle);
    $title = $fallbackTitle ?: $h['title'];
    return '<div class="page-head"><h1 class="page-head-title">' . e($title) . '</h1>' . renderPageHelpButton($key, $title) . '</div>';
}
