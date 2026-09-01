<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Client minimale per l'API di TMDb (The Movie Database) — ricerca persone/attori. Stesso
 * approccio già usato per Spotify/YouTube: solo httpRequest(), nessuna libreria esterna. Usa
 * la "API Key (v3 auth)", passata come parametro nell'URL (non il token v4, più complesso).
 */

const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w185';

function getTmdbApiKey(): ?string {
    $key = getSiteSetting('tmdb_api_key');
    return $key !== '' ? $key : null;
}

// Cerca una persona (attore/attrice) per nome. Restituisce fino a 10 risultati, i più rilevanti
// per popolarità (ordine già dato dall'API), scartando chi non ha nessuna foto profilo.
function tmdbSearchPerson(string $query): array {
    $apiKey = getTmdbApiKey();
    if (!$apiKey || trim($query) === '') {
        return [];
    }
    $url = 'https://api.themoviedb.org/3/search/person?api_key=' . urlencode($apiKey)
         . '&language=it-IT&include_adult=false&query=' . urlencode($query);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (array_slice($data['results'] ?? [], 0, 10) as $p) {
        $results[] = [
            'id' => (string) $p['id'],
            'name' => $p['name'],
            'image' => !empty($p['profile_path']) ? (TMDB_IMAGE_BASE . $p['profile_path']) : null,
            'tmdb_url' => 'https://www.themoviedb.org/person/' . $p['id'],
        ];
    }
    return $results;
}
