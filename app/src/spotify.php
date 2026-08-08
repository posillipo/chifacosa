<?php
/**
 * Client minimale per l'API pubblica di catalogo di Spotify (Client Credentials Flow).
 * Nessuna dipendenza esterna: usa file_get_contents con stream context, come il resto del
 * progetto (vedi mailer.php per lo stesso approccio applicato all'SMTP).
 *
 * Questo flusso autentica la NOSTRA app (non l'utente): dà accesso solo a dati pubblici del
 * catalogo Spotify (ricerca artisti, album, brani), non richiede login dell'utente finale e
 * non è soggetto al limite "Development Mode" di 25 utenti che si applica invece al login OAuth
 * personale.
 */

// Esegue una richiesta HTTP generica (GET o POST) e restituisce il body come stringa, o null
// in caso di errore. Non lancia mai eccezioni: gli errori vanno controllati dal chiamante.
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): ?string {
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true, // permette di leggere anche il body di risposte 4xx/5xx
        ],
    ];
    $context = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        return null;
    }
    // Controlla il codice di stato HTTP dagli header di risposta ($http_response_header è
    // popolata automaticamente da PHP dopo una file_get_contents su http/https)
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
        if ($status >= 400) {
            error_log("[Spotify] HTTP {$status} per {$url}: " . substr($result, 0, 300));
        }
    }
    return $result;
}

// Ottiene (e mette in cache nel database) il token app-to-app di Spotify, valido circa 1 ora.
// Restituisce null se le credenziali non sono configurate o la richiesta fallisce.
function getSpotifyAppToken(): ?string {
    $clientId = getSiteSetting('spotify_client_id') ?: '';
    $clientSecret = getSiteSetting('spotify_client_secret') ?: '';
    if ($clientId === '' || $clientSecret === '') {
        return null;
    }

    $cachedToken = getSiteSetting('spotify_app_token') ?: '';
    $cachedExpires = getSiteSetting('spotify_app_token_expires') ?: '';
    if ($cachedToken !== '' && $cachedExpires !== '' && strtotime($cachedExpires) > time() + 30) {
        return $cachedToken;
    }

    $body = 'grant_type=client_credentials';
    $headers = [
        'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        'Content-Type: application/x-www-form-urlencoded',
    ];
    $response = httpRequest('POST', 'https://accounts.spotify.com/api/token', $headers, $body);
    if ($response === null) {
        return null;
    }
    $data = json_decode($response, true);
    if (!isset($data['access_token'])) {
        error_log('[Spotify] Impossibile ottenere il token: ' . substr($response, 0, 300));
        return null;
    }

    $expiresAt = date('Y-m-d H:i:s', time() + (int) ($data['expires_in'] ?? 3600) - 30);
    setSiteSetting('spotify_app_token', $data['access_token']);
    setSiteSetting('spotify_app_token_expires', $expiresAt);

    return $data['access_token'];
}

// Cerca artisti per nome. Restituisce un array di ['id','name','image','spotify_url','followers'].
function spotifySearchArtist(string $query): array {
    $token = getSpotifyAppToken();
    if (!$token || trim($query) === '') {
        return [];
    }
    $url = 'https://api.spotify.com/v1/search?type=artist&limit=10&q=' . urlencode($query);
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (($data['artists']['items'] ?? []) as $a) {
        $results[] = [
            'id' => $a['id'],
            'name' => $a['name'],
            'image' => $a['images'][2]['url'] ?? ($a['images'][0]['url'] ?? null),
            'spotify_url' => $a['external_urls']['spotify'] ?? null,
            'followers' => $a['followers']['total'] ?? 0,
        ];
    }
    return $results;
}

// Dettagli di un singolo artista (usata soprattutto per recuperare la sua immagine di profilo
// reale, es. per i meta tag og:image, senza dover riusare la copertina di un album a caso).
function spotifyGetArtist(string $artistId): ?array {
    $token = getSpotifyAppToken();
    if (!$token || trim($artistId) === '') {
        return null;
    }
    $url = 'https://api.spotify.com/v1/artists/' . urlencode($artistId);
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return null;
    }
    $a = json_decode($response, true);
    if (!$a || empty($a['id'])) {
        return null;
    }
    return [
        'id' => $a['id'],
        'name' => $a['name'] ?? '',
        'image' => $a['images'][0]['url'] ?? ($a['images'][1]['url'] ?? null),
        'spotify_url' => $a['external_urls']['spotify'] ?? null,
    ];
}

// Album e singoli pubblicati dall'artista (esclude le compilation di altri).
function spotifyGetArtistAlbums(string $artistId): array {
    $token = getSpotifyAppToken();
    if (!$token) {
        return [];
    }
    $url = 'https://api.spotify.com/v1/artists/' . urlencode($artistId) . '/albums?include_groups=album,single&market=US&limit=20';
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $albums = [];
    $seenNames = [];
    foreach (($data['items'] ?? []) as $a) {
        // Spotify spesso elenca più edizioni/mercati dello stesso album: teniamo solo la prima
        $key = strtolower($a['name']);
        if (isset($seenNames[$key])) continue;
        $seenNames[$key] = true;
        $albums[] = [
            'id' => $a['id'],
            'name' => $a['name'],
            'image' => $a['images'][1]['url'] ?? ($a['images'][0]['url'] ?? null),
            'release_date' => $a['release_date'] ?? null,
            'spotify_url' => $a['external_urls']['spotify'] ?? null,
            'type' => $a['album_type'] ?? 'album',
        ];
    }
    return $albums;
}

// I brani più popolari dell'artista (top tracks).
function spotifyGetArtistTopTracks(string $artistId): array {
    $token = getSpotifyAppToken();
    if (!$token) {
        return [];
    }
    $url = 'https://api.spotify.com/v1/artists/' . urlencode($artistId) . '/top-tracks?market=US';
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $tracks = [];
    foreach (($data['tracks'] ?? []) as $t) {
        $tracks[] = [
            'id' => $t['id'],
            'name' => $t['name'],
            'album_name' => $t['album']['name'] ?? '',
            'image' => $t['album']['images'][2]['url'] ?? ($t['album']['images'][0]['url'] ?? null),
            'spotify_url' => $t['external_urls']['spotify'] ?? null,
            'preview_url' => $t['preview_url'] ?? null,
        ];
    }
    return $tracks;
}

// Cerca singoli brani per titolo/artista (usato dal modulo Brani, disponibile per ogni tipo di profilo).
function spotifySearchTrack(string $query): array {
    $token = getSpotifyAppToken();
    if (!$token || trim($query) === '') {
        return [];
    }
    $url = 'https://api.spotify.com/v1/search?type=track&market=US&limit=10&q=' . urlencode($query);
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (($data['tracks']['items'] ?? []) as $t) {
        $results[] = [
            'id' => $t['id'],
            'name' => $t['name'],
            'artist_name' => implode(', ', array_map(fn($a) => $a['name'], $t['artists'] ?? [])),
            'image' => $t['album']['images'][1]['url'] ?? ($t['album']['images'][0]['url'] ?? null),
            'spotify_url' => $t['external_urls']['spotify'] ?? null,
        ];
    }
    return $results;
}

// Cerca podcast (show) per nome. Stessa logica di spotifySearchArtist, ma su type=show.
function spotifySearchShow(string $query): array {
    $token = getSpotifyAppToken();
    if (!$token || trim($query) === '') {
        return [];
    }
    $url = 'https://api.spotify.com/v1/search?type=show&market=US&limit=10&q=' . urlencode($query);
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (($data['shows']['items'] ?? []) as $s) {
        $results[] = [
            'id' => $s['id'],
            'name' => $s['name'],
            'publisher' => $s['publisher'] ?? '',
            'image' => $s['images'][1]['url'] ?? ($s['images'][0]['url'] ?? null),
            'spotify_url' => $s['external_urls']['spotify'] ?? null,
            'description' => $s['description'] ?? '',
        ];
    }
    return $results;
}

// Dettagli di un singolo show/podcast (usata soprattutto per la sua copertina reale, es. per
// i meta tag og:image, invece di riusare l'immagine di un singolo episodio).
function spotifyGetShow(string $showId): ?array {
    $token = getSpotifyAppToken();
    if (!$token || trim($showId) === '') {
        return null;
    }
    $url = 'https://api.spotify.com/v1/shows/' . urlencode($showId) . '?market=US';
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return null;
    }
    $s = json_decode($response, true);
    if (!$s || empty($s['id'])) {
        return null;
    }
    return [
        'id' => $s['id'],
        'name' => $s['name'] ?? '',
        'image' => $s['images'][0]['url'] ?? ($s['images'][1]['url'] ?? null),
        'spotify_url' => $s['external_urls']['spotify'] ?? null,
    ];
}

// Episodi più recenti di un podcast (show).
function spotifyGetShowEpisodes(string $showId, int $max = 10): array {
    $token = getSpotifyAppToken();
    if (!$token) {
        return [];
    }
    $url = 'https://api.spotify.com/v1/shows/' . urlencode($showId) . '/episodes?market=US&limit=' . $max;
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $episodes = [];
    foreach (($data['items'] ?? []) as $ep) {
        $episodes[] = [
            'id' => $ep['id'],
            'name' => $ep['name'],
            'description' => textExcerpt($ep['description'] ?? '', 160),
            'image' => $ep['images'][1]['url'] ?? ($ep['images'][0]['url'] ?? null),
            'release_date' => $ep['release_date'] ?? null,
            'spotify_url' => $ep['external_urls']['spotify'] ?? null,
            'duration_ms' => $ep['duration_ms'] ?? 0,
        ];
    }
    return $episodes;
}
