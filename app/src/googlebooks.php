<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Client minimale per la Google Books API — ricerca libri. Stesso approccio già usato per
 * Spotify/TMDb: solo httpRequest(), nessuna libreria esterna. Funziona anche gratis senza
 * chiave (con limiti più bassi), ma qui la chiave è sempre richiesta per restare nella soglia
 * generosa e stabile (1000 richieste/giorno gratis).
 */

function getGoogleBooksApiKey(): ?string {
    $key = getSiteSetting('google_books_api_key');
    return $key !== '' ? $key : null;
}

// Cerca un libro per titolo/autore. Restituisce fino a 10 risultati, scartando chi non ha
// nessuna copertina (stessa scelta già fatta per TMDb: senza immagine il pulsante è spoglio).
function googleBooksSearch(string $query): array {
    $apiKey = getGoogleBooksApiKey();
    if (!$apiKey || trim($query) === '') {
        return [];
    }
    $url = 'https://www.googleapis.com/books/v1/volumes?key=' . urlencode($apiKey)
         . '&maxResults=20&langRestrict=it&q=' . urlencode($query);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (($data['items'] ?? []) as $b) {
        $info = $b['volumeInfo'] ?? [];
        $image = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? null;
        if (!$image || empty($info['title'])) {
            continue;
        }
        $authors = !empty($info['authors']) ? implode(', ', $info['authors']) : null;
        $results[] = [
            'id' => $b['id'],
            'name' => $info['title'] . ($authors ? ' — ' . $authors : ''),
            'title' => $info['title'],
            'authors' => $authors,
            'image' => str_replace('http://', 'https://', $image),
            'google_books_url' => 'https://books.google.com/books?id=' . $b['id'],
        ];
        if (count($results) >= 10) {
            break;
        }
    }
    return $results;
}

// Dettagli di un libro (usata nella pagina dedicata di "Libri che amo" per mostrare
// trama/autore/editore oltre al titolo e alla copertina già salvati).
function googleBooksGetVolumeDetails(string $volumeId): ?array {
    $apiKey = getGoogleBooksApiKey();
    if (!$apiKey || trim($volumeId) === '') {
        return null;
    }
    $url = 'https://www.googleapis.com/books/v1/volumes/' . urlencode($volumeId) . '?key=' . urlencode($apiKey);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $b = json_decode($response, true);
    $info = $b['volumeInfo'] ?? null;
    if (!$info) {
        return null;
    }
    return [
        'overview' => trim($info['description'] ?? ''),
        'authors' => !empty($info['authors']) ? implode(', ', $info['authors']) : null,
        'publisher' => $info['publisher'] ?? null,
        'release_date' => $info['publishedDate'] ?? null,
        'genres' => $info['categories'] ?? [],
        'vote_average' => $info['averageRating'] ?? null,
        'google_books_url' => 'https://books.google.com/books?id=' . $volumeId,
    ];
}
