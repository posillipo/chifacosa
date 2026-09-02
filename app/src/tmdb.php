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

// Cerca un film per titolo. Stessa forma di tmdbSearchPerson(), immagine = locandina invece
// della foto profilo.
function tmdbSearchMovie(string $query): array {
    $apiKey = getTmdbApiKey();
    if (!$apiKey || trim($query) === '') {
        return [];
    }
    $url = 'https://api.themoviedb.org/3/search/movie?api_key=' . urlencode($apiKey)
         . '&language=it-IT&include_adult=false&query=' . urlencode($query);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (array_slice($data['results'] ?? [], 0, 10) as $m) {
        $results[] = [
            'id' => (string) $m['id'],
            'name' => $m['title'],
            'image' => !empty($m['poster_path']) ? (TMDB_IMAGE_BASE . $m['poster_path']) : null,
            'tmdb_url' => 'https://www.themoviedb.org/movie/' . $m['id'],
        ];
    }
    return $results;
}

// Dettagli di una persona (usata nella pagina dedicata di "Attori che amo" per mostrare
// biografia/professione oltre al nome e alla foto già salvati).
function tmdbGetPersonDetails(string $personId): ?array {
    $apiKey = getTmdbApiKey();
    if (!$apiKey || trim($personId) === '') {
        return null;
    }
    $url = 'https://api.themoviedb.org/3/person/' . urlencode($personId) . '?api_key=' . urlencode($apiKey) . '&language=it-IT';
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $p = json_decode($response, true);
    if (!$p || empty($p['id'])) {
        return null;
    }
    return [
        'biography' => trim($p['biography'] ?? ''),
        'known_for_department' => $p['known_for_department'] ?? null,
        'birthday' => $p['birthday'] ?? null,
        'place_of_birth' => $p['place_of_birth'] ?? null,
        'tmdb_url' => 'https://www.themoviedb.org/person/' . $p['id'],
    ];
}

// Dettagli di un film (usata nella pagina dedicata di "Film che amo").
function tmdbGetMovieDetails(string $movieId): ?array {
    $apiKey = getTmdbApiKey();
    if (!$apiKey || trim($movieId) === '') {
        return null;
    }
    $url = 'https://api.themoviedb.org/3/movie/' . urlencode($movieId) . '?api_key=' . urlencode($apiKey) . '&language=it-IT';
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $m = json_decode($response, true);
    if (!$m || empty($m['id'])) {
        return null;
    }
    return [
        'overview' => trim($m['overview'] ?? ''),
        'release_date' => $m['release_date'] ?? null,
        'genres' => array_column($m['genres'] ?? [], 'name'),
        'vote_average' => $m['vote_average'] ?? null,
        'tmdb_url' => 'https://www.themoviedb.org/movie/' . $m['id'],
    ];
}
