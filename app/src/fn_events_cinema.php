<?php
// Eventi (calendario) e sincronizzazione Cinema.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Etichetta breve per un evento perpetuo/ricorrente (nessuna data di fine specifica) — usata
// ovunque un evento compare: pagina pubblica, elenco pubblico, dashboard, Timeline. Null se
// l'evento ha semplicemente una data singola (comportamento di sempre, nessuna etichetta).
function eventScheduleLabel(string $recurrence, bool $isPerpetual): ?string {
    if ($recurrence === 'weekdays') {
        return 'Dal Lunedì al Venerdì';
    }
    if ($recurrence === 'weekend') {
        return 'Solo Weekend';
    }
    if ($isPerpetual) {
        return 'Sempre attivo';
    }
    return null;
}


// Link/markup "Aggiungi al calendario" per la pagina pubblica di un evento (Colorful + AdminLTE).
// Esclusi gli eventi perpetui/ricorrenti (is_perpetual, recurrence != none): non hanno una data
// di fine, "aggiungerli al calendario" non avrebbe un significato univoco. $btnClass permette di
// riusare lo stesso markup con le classi dei pulsanti dei due temi.
function renderAddToCalendarLinks(array $event, string $slug, array $profile, string $btnClass): string {
    if (!empty($event['is_perpetual']) || ($event['recurrence'] ?? 'none') !== 'none') {
        return '';
    }
    $start = eventCalendarUtcTimestamp($event['event_date'], $profile);
    $end = eventCalendarUtcTimestamp($event['event_date'], $profile, EVENT_CALENDAR_DEFAULT_DURATION_HOURS);
    $location = trim(($event['venue'] ?: '') . ($event['venue'] && $event['city'] ? ', ' : '') . ($event['city'] ?: ''));
    $googleUrl = 'https://www.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => $event['title'],
        'dates' => $start . '/' . $end,
        'details' => $event['description'] ?? '',
        'location' => $location,
    ]);
    $icsUrl = '/event_ics.php?' . http_build_query(['slug' => $slug, 'id' => (int) $event['id']]);
    ob_start();
    ?>
    <a class="<?= e($btnClass) ?>" href="<?= e($googleUrl) ?>" target="_blank" rel="noopener">📅 Google Calendar</a>
    <a class="<?= e($btnClass) ?>" href="<?= e($icsUrl) ?>">📅 Apple/Outlook (.ics)</a>
    <?php
    return ob_get_clean();
}


// ===== Cinema: sincronizzazione film in programmazione (modulo Link) =====
// Funzionalità dedicata ai profili "cinema": un JSON esterno (formato 18tickets,
// {"films": [{id, title, film_url, playbill_path, ...}]}) diventa una serie di pulsanti nel
// modulo Link (link_type='film'), uno per film, con titolo/immagine/link — vera
// sincronizzazione: aggiunge i film nuovi, aggiorna quelli già presenti, rimuove quelli non più
// in programmazione. Configurabile da Dashboard → menu hamburger → Cinema
// (dashboard_cinema.php), sincronizzabile a mano o via cron (cron_cinema_sync.php).

// Token segreto per autenticare le chiamate cron automatiche (generato una sola volta, salvato
// in site_settings come le altre chiavi API del sito).
function getCinemaSyncCronToken(): string {
    $token = getSiteSetting('cinema_sync_cron_token');
    if (!$token) {
        $token = bin2hex(random_bytes(24));
        setSiteSetting('cinema_sync_cron_token', $token);
    }
    return $token;
}


// Scarica un URL generico con timeout, senza dipendenze esterne (stesso approccio di
// spotify.php/mailer.php: file_get_contents con stream context). Restituisce null in caso di
// errore (URL non pubblico incluso), senza mai lanciare eccezioni. follow_location disattivato
// apposta: un redirect verso un indirizzo interno aggirerebbe altrimenti il controllo qui sopra,
// che vale solo per l'URL di partenza.
function cinemaHttpGet(string $url, int $timeout = 20): ?string {
    if (!isSafePublicUrl($url)) {
        return null;
    }
    $opts = [
        'http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0],
        'https' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0],
    ];
    $context = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);
    return $result === false ? null : $result;
}


// Scarica un'immagine da URL esterno e la salva in uploads/images/{slug}, come le altre cover
// caricate nel sito — evita di hotlinkare l'immagine del gestionale cinema esterno.
function cinemaDownloadPoster(string $imageUrl, string $slug): ?string {
    $data = cinemaHttpGet($imageUrl, 20);
    if ($data === null || strlen($data) < 100 || strlen($data) > 8 * 1024 * 1024) {
        return null;
    }
    $ext = strtolower(pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $ext = 'jpg';
    }
    $fname = bin2hex(random_bytes(6)) . '.' . $ext;
    $dir = '/var/www/html/uploads/images/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (file_put_contents($dir . '/' . $fname, $data) === false) {
        return null;
    }
    return 'uploads/images/' . $slug . '/' . $fname;
}


// Sincronizza i film in programmazione di UN profilo dal suo JSON configurato nel modulo Link
// (link_type='film', external_ref=film.id): aggiunge i nuovi, aggiorna label/url dei già
// presenti (senza riscaricare il poster, già in cache), rimuove chi non è più nel JSON. In caso
// di errore (URL irraggiungibile, JSON non valido) non tocca nulla — non svuota mai i link
// esistenti per un problema temporaneo del feed esterno.
function syncCinemaFilms(array $profile): array {
    $jsonUrl = trim($profile['cinema_films_json_url'] ?? '');
    if ($jsonUrl === '') {
        return ['ok' => false, 'error' => 'Nessun URL JSON configurato.'];
    }

    $raw = cinemaHttpGet($jsonUrl, 25);
    if ($raw === null) {
        return ['ok' => false, 'error' => 'Impossibile raggiungere l\'URL del JSON.'];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['films']) || !is_array($data['films'])) {
        return ['ok' => false, 'error' => 'Il JSON non ha il formato atteso (manca "films").'];
    }

    $db = getDB();
    $userId = (int) $profile['id'];
    $slug = $profile['slug'];

    $stmt = $db->prepare("SELECT id, external_ref, cover_path, label, url FROM links WHERE user_id=? AND link_type='film'");
    $stmt->execute([$userId]);
    $existing = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['external_ref']) {
            $existing[$row['external_ref']] = $row;
        }
    }

    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order),0) AS m FROM links WHERE user_id=?');
    $stmt->execute([$userId]);
    $nextSort = (int) $stmt->fetch()['m'] + 1;

    $seenRefs = [];
    $added = 0;
    $updated = 0;

    foreach ($data['films'] as $film) {
        $ref = trim((string) ($film['id'] ?? ''));
        $title = mb_substr(trim((string) ($film['title'] ?? '')), 0, 120);
        $url = trim((string) ($film['film_url'] ?? '')) ?: trim((string) ($film['film_url_for_cinema'] ?? ''));
        if ($ref === '' || $title === '' || $url === '') {
            continue;
        }
        $seenRefs[$ref] = true;

        if (isset($existing[$ref])) {
            $row = $existing[$ref];
            if ($row['label'] !== $title || $row['url'] !== $url) {
                $stmt = $db->prepare('UPDATE links SET label=?, url=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $url, $row['id'], $userId]);
                $updated++;
            }
        } else {
            $coverPath = null;
            $playbill = trim((string) ($film['playbill_path'] ?? ''));
            if ($playbill !== '') {
                $coverPath = cinemaDownloadPoster($playbill, $slug);
            }
            $stmt = $db->prepare("INSERT INTO links (user_id, label, url, cover_path, sort_order, link_type, external_ref) VALUES (?,?,?,?,?,'film',?)");
            $stmt->execute([$userId, $title, $url, $coverPath, $nextSort, $ref]);
            $nextSort++;
            $added++;
        }
    }

    $removed = 0;
    foreach ($existing as $ref => $row) {
        if (!isset($seenRefs[$ref])) {
            deleteCoverFile($row['cover_path']);
            $stmt = $db->prepare('DELETE FROM links WHERE id=? AND user_id=?');
            $stmt->execute([$row['id'], $userId]);
            $removed++;
        }
    }

    $stmt = $db->prepare('UPDATE profiles SET cinema_films_synced_at=NOW() WHERE user_id=?');
    $stmt->execute([$userId]);

    return ['ok' => true, 'added' => $added, 'updated' => $updated, 'removed' => $removed, 'total' => count($seenRefs)];
}
