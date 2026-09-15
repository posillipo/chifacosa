<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Client minimale per l'API di TheSportsDB — ricerca squadre sportive. Stesso approccio già
 * usato per TMDb/Google Books/Spoonacular: solo httpRequest(), nessuna libreria esterna. La
 * chiave va nel percorso dell'URL (non come parametro) — "3" è la chiave di test pubblica
 * (limitata, va bene solo per provare), una personale si ottiene gratis su patreon.com/sportsdb.
 */

function getThesportsdbApiKey(): ?string {
    $key = getSiteSetting('thesportsdb_api_key');
    return $key !== '' ? $key : null;
}

// Cerca una squadra per nome. Restituisce fino a 10 risultati, scartando chi non ha nessuno
// stemma (stessa scelta già fatta per TMDb/Google Books/Spoonacular: senza immagine il pulsante
// è spoglio). Gli stemmi di TheSportsDB sono PNG trasparenti già grandi abbastanza per og:image.
function thesportsdbSearchTeam(string $query): array {
    $apiKey = getThesportsdbApiKey();
    if (!$apiKey || trim($query) === '') {
        return [];
    }
    $url = 'https://www.thesportsdb.com/api/v1/json/' . urlencode($apiKey) . '/searchteams.php?t=' . urlencode($query);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (array_slice($data['teams'] ?? [], 0, 10) as $t) {
        if (empty($t['strBadge'])) {
            continue;
        }
        $results[] = [
            'id' => (string) $t['idTeam'],
            'name' => $t['strTeam'] . (!empty($t['strLeague']) ? ' — ' . $t['strLeague'] : ''),
            'title' => $t['strTeam'],
            'image' => $t['strBadge'],
        ];
    }
    return $results;
}

// Dettagli di una squadra (usata nella pagina dedicata di "Squadre che amo" per mostrare
// campionato/paese/stadio/anno di fondazione e la prossima partita, oltre al nome e allo stemma
// già salvati).
function thesportsdbGetTeamDetails(string $teamId): ?array {
    $apiKey = getThesportsdbApiKey();
    if (!$apiKey || trim($teamId) === '') {
        return null;
    }
    $url = 'https://www.thesportsdb.com/api/v1/json/' . urlencode($apiKey) . '/lookupteam.php?id=' . urlencode($teamId);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    $t = ($data['teams'] ?? [])[0] ?? null;
    if (!$t) {
        return null;
    }

    $nextEvent = null;
    $eventsUrl = 'https://www.thesportsdb.com/api/v1/json/' . urlencode($apiKey) . '/eventsnext.php?id=' . urlencode($teamId);
    $eventsResponse = httpRequest('GET', $eventsUrl);
    if ($eventsResponse) {
        $eventsData = json_decode($eventsResponse, true);
        $ev = ($eventsData['events'] ?? [])[0] ?? null;
        if ($ev) {
            $nextEvent = trim(($ev['strEvent'] ?? '') . ' — ' . ($ev['dateEvent'] ?? '') . ' ' . ($ev['strTime'] ?? ''));
        }
    }

    // La descrizione in italiano non è sempre compilata per ogni squadra (a differenza
    // dell'inglese, quasi sempre presente): quando manca si ripiega sull'inglese piuttosto che
    // lasciare la scheda senza descrizione.
    $overview = trim($t['strDescriptionIT'] ?? '');
    if ($overview === '') {
        $overview = trim($t['strDescriptionEN'] ?? '');
    }

    return [
        'overview' => $overview,
        'league' => $t['strLeague'] ?? null,
        'country' => $t['strCountry'] ?? null,
        'stadium' => $t['strStadium'] ?? null,
        'founded_year' => $t['intFormedYear'] ?? null,
        'next_event' => $nextEvent,
        'thesportsdb_url' => 'https://www.thesportsdb.com/team/' . $teamId,
    ];
}

// Cerca un calciatore per nome. Restituisce fino a 10 risultati, scartando chi non ha nessuna
// foto. La chiave di risposta di searchplayers.php è "player" (singolare, a differenza di
// "teams") — un'incoerenza nota dell'API, per questo qui e sotto si controllano entrambe le
// varianti invece di fidarsi di una sola (vedi il bug già preso con strTeamBadge/strBadge).
function thesportsdbSearchPlayer(string $query): array {
    $apiKey = getThesportsdbApiKey();
    if (!$apiKey || trim($query) === '') {
        return [];
    }
    $url = 'https://www.thesportsdb.com/api/v1/json/' . urlencode($apiKey) . '/searchplayers.php?p=' . urlencode($query);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $list = $data['player'] ?? $data['players'] ?? [];
    $results = [];
    foreach (array_slice($list, 0, 10) as $p) {
        $image = $p['strThumb'] ?? $p['strCutout'] ?? null;
        if (!$image) {
            continue;
        }
        $results[] = [
            'id' => (string) $p['idPlayer'],
            'name' => $p['strPlayer'] . (!empty($p['strTeam']) ? ' — ' . $p['strTeam'] : ''),
            'title' => $p['strPlayer'],
            'image' => $image,
        ];
    }
    return $results;
}

// Dettagli di un calciatore (usata nella pagina dedicata di "Calciatori che amo" per mostrare
// squadra/ruolo/nazionalità/data di nascita, oltre al nome e alla foto già salvati).
function thesportsdbGetPlayerDetails(string $playerId): ?array {
    $apiKey = getThesportsdbApiKey();
    if (!$apiKey || trim($playerId) === '') {
        return null;
    }
    $url = 'https://www.thesportsdb.com/api/v1/json/' . urlencode($apiKey) . '/lookupplayer.php?id=' . urlencode($playerId);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    $list = $data['players'] ?? $data['player'] ?? [];
    $p = $list[0] ?? null;
    if (!$p) {
        return null;
    }

    $overview = trim($p['strDescriptionIT'] ?? '');
    if ($overview === '') {
        $overview = trim($p['strDescriptionEN'] ?? '');
    }

    return [
        'overview' => $overview,
        'team' => $p['strTeam'] ?? null,
        'position' => $p['strPosition'] ?? null,
        'nationality' => $p['strNationality'] ?? null,
        'born' => $p['dateBorn'] ?? null,
        'thesportsdb_url' => 'https://www.thesportsdb.com/player/' . $playerId,
    ];
}

// Prossime partite (in programma) e ultime giocate di una squadra, per farle scegliere in
// dashboard quando si aggiunge una "Partita che amo" — non è possibile cercare una partita per
// nome su TheSportsDB, va sempre scelta a partire dal calendario di una squadra. Le prossime
// partite vengono prima (più probabile aggiungerle rispetto a una già giocata).
function thesportsdbGetTeamEvents(string $teamId): array {
    $apiKey = getThesportsdbApiKey();
    if (!$apiKey || trim($teamId) === '') {
        return [];
    }
    $events = [];
    foreach (['eventsnext.php', 'eventslast.php'] as $endpoint) {
        $url = 'https://www.thesportsdb.com/api/v1/json/' . urlencode($apiKey) . '/' . $endpoint . '?id=' . urlencode($teamId);
        $response = httpRequest('GET', $url);
        if (!$response) {
            continue;
        }
        $data = json_decode($response, true);
        foreach (($data['events'] ?? []) as $ev) {
            if (empty($ev['idEvent'])) {
                continue;
            }
            $events[] = [
                'id' => (string) $ev['idEvent'],
                'title' => trim(($ev['strHomeTeam'] ?? '') . ' vs ' . ($ev['strAwayTeam'] ?? '')),
                'date' => $ev['dateEvent'] ?? '',
                'time' => substr($ev['strTime'] ?? '', 0, 5),
                'image' => $ev['strThumb'] ?? null,
            ];
        }
    }
    return $events;
}

// Dettagli di una partita (usata nella pagina dedicata di "Partite che amo" per mostrare
// risultato, campionato, stadio e data/ora, oltre al titolo e alla foto già salvati).
function thesportsdbGetEventDetails(string $eventId): ?array {
    $apiKey = getThesportsdbApiKey();
    if (!$apiKey || trim($eventId) === '') {
        return null;
    }
    $url = 'https://www.thesportsdb.com/api/v1/json/' . urlencode($apiKey) . '/lookupevent.php?id=' . urlencode($eventId);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    $ev = ($data['events'] ?? [])[0] ?? null;
    if (!$ev) {
        return null;
    }

    $homeScore = $ev['intHomeScore'] ?? null;
    $awayScore = $ev['intAwayScore'] ?? null;
    $result = ($homeScore !== null && $awayScore !== null) ? "{$homeScore} - {$awayScore}" : null;

    $overview = trim($ev['strDescriptionEN'] ?? '');

    return [
        'overview' => $overview,
        'home_team' => $ev['strHomeTeam'] ?? null,
        'away_team' => $ev['strAwayTeam'] ?? null,
        'result' => $result,
        'league' => $ev['strLeague'] ?? null,
        'venue' => $ev['strVenue'] ?? null,
        'match_date' => trim(($ev['dateEvent'] ?? '') . ' ' . substr($ev['strTime'] ?? '', 0, 5)),
        'thesportsdb_url' => 'https://www.thesportsdb.com/event/' . $eventId,
    ];
}
