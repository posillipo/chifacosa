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
