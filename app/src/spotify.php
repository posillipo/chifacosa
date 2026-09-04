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
// market=US obbligatorio: senza un market esplicito (o senza utente loggato, come qui via Client
// Credentials) Spotify considera il catalogo "non disponibile" e restituisce una lista vuota —
// per le app create più di recente questa regola viene applicata in modo rigido, a differenza
// delle app più vecchie che restano compatibili anche senza (da qui il bug: stesso identico
// codice, ma funzionava sull'app Spotify di myband — più vecchia — non su quella di chifacosa).
function spotifySearchArtist(string $query): array {
    $token = getSpotifyAppToken();
    if (!$token || trim($query) === '') {
        return [];
    }
    $url = 'https://api.spotify.com/v1/search?type=artist&market=US&limit=10&q=' . urlencode($query);
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
        'genres' => $a['genres'] ?? [],
        'followers' => $a['followers']['total'] ?? null,
        'popularity' => $a['popularity'] ?? null,
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

// Cerca playlist pubbliche per nome. Stessa logica di spotifySearchArtist, ma su type=playlist.
function spotifySearchPlaylist(string $query): array {
    $token = getSpotifyAppToken();
    if (!$token || trim($query) === '') {
        return [];
    }
    $url = 'https://api.spotify.com/v1/search?type=playlist&market=US&limit=10&q=' . urlencode($query);
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (($data['playlists']['items'] ?? []) as $p) {
        // Con l'account Client Credentials può capitare qualche voce nulla nei risultati (playlist
        // rimosse/private nel frattempo): si scarta invece di far fallire tutto il ciclo.
        if (!$p || empty($p['id'])) {
            continue;
        }
        $owner = $p['owner']['display_name'] ?? '';
        $results[] = [
            'id' => $p['id'],
            'title' => $p['name'],
            'name' => $p['name'] . ($owner !== '' ? ' — di ' . $owner : ''),
            'owner' => $owner,
            'image' => $p['images'][0]['url'] ?? null,
            'spotify_url' => $p['external_urls']['spotify'] ?? null,
            'tracks_total' => $p['tracks']['total'] ?? 0,
        ];
    }
    return $results;
}

// Dettagli di una singola playlist (usata per la sua copertina reale, la descrizione e il
// numero di brani — es. per i meta tag og:image e la pagina di dettaglio).
function spotifyGetPlaylist(string $playlistId): ?array {
    $token = getSpotifyAppToken();
    if (!$token || trim($playlistId) === '') {
        return null;
    }
    $url = 'https://api.spotify.com/v1/playlists/' . urlencode($playlistId) . '?market=US&fields=id,name,description,images,owner,tracks.total,external_urls';
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return null;
    }
    $p = json_decode($response, true);
    if (!$p || empty($p['id'])) {
        return null;
    }
    return [
        'id' => $p['id'],
        'name' => $p['name'] ?? '',
        'description' => $p['description'] ?? '',
        'owner' => $p['owner']['display_name'] ?? '',
        'image' => $p['images'][0]['url'] ?? null,
        'spotify_url' => $p['external_urls']['spotify'] ?? null,
        'tracks_total' => $p['tracks']['total'] ?? 0,
    ];
}

// Cerca album per nome/artista. Stessa logica di spotifySearchArtist, ma su type=album.
function spotifySearchAlbum(string $query): array {
    $token = getSpotifyAppToken();
    if (!$token || trim($query) === '') {
        return [];
    }
    $url = 'https://api.spotify.com/v1/search?type=album&market=US&limit=10&q=' . urlencode($query);
    $response = httpRequest('GET', $url, ['Authorization: Bearer ' . $token]);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (($data['albums']['items'] ?? []) as $a) {
        $artistName = implode(', ', array_map(fn($ar) => $ar['name'], $a['artists'] ?? []));
        $results[] = [
            'id' => $a['id'],
            'title' => $a['name'],
            'name' => $a['name'] . ($artistName !== '' ? ' — ' . $artistName : ''),
            'artist_name' => $artistName,
            'image' => $a['images'][1]['url'] ?? ($a['images'][0]['url'] ?? null),
            'spotify_url' => $a['external_urls']['spotify'] ?? null,
            'release_date' => $a['release_date'] ?? null,
        ];
    }
    return $results;
}

// Dettagli di un singolo album (copertina ad alta risoluzione, generi dell'artista principale,
// numero di brani — usata per i meta tag og:image e la pagina di dettaglio).
function spotifyGetAlbum(string $albumId): ?array {
    $token = getSpotifyAppToken();
    if (!$token || trim($albumId) === '') {
        return null;
    }
    $url = 'https://api.spotify.com/v1/albums/' . urlencode($albumId) . '?market=US';
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
        'artist_name' => implode(', ', array_map(fn($ar) => $ar['name'], $a['artists'] ?? [])),
        'image' => $a['images'][0]['url'] ?? ($a['images'][1]['url'] ?? null),
        'spotify_url' => $a['external_urls']['spotify'] ?? null,
        'release_date' => $a['release_date'] ?? null,
        'genres' => $a['genres'] ?? [],
        'tracks_total' => $a['tracks']['total'] ?? ($a['total_tracks'] ?? 0),
    ];
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
