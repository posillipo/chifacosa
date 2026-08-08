<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Client minimale per YouTube Data API v3. Nessuna dipendenza esterna, stesso approccio già
 * usato per Spotify e per l'SMTP (file_get_contents + stream context).
 *
 * Serve solo una API Key (nessun login utente, nessun OAuth): accede esclusivamente a dati
 * pubblici di un canale (video caricati, titoli, miniature).
 */

function getYoutubeApiKey(): ?string {
    $key = getSiteSetting('youtube_api_key');
    return $key !== '' ? $key : null;
}

// Riconosce e risolve un link/ID canale incollato dal band manager in tre formati comuni:
// https://www.youtube.com/channel/UCxxxx, https://www.youtube.com/@handle,
// https://www.youtube.com/user/nomeutente (formato storico). Restituisce
// ['channel_id' => ..., 'channel_name' => ..., 'uploads_playlist_id' => ...] oppure null.
function youtubeResolveChannel(string $input): ?array {
    $apiKey = getYoutubeApiKey();
    if (!$apiKey) {
        return null;
    }
    $input = trim($input);

    $params = null;
    if (preg_match('#youtube\.com/channel/([a-zA-Z0-9_-]+)#', $input, $m)) {
        $params = 'id=' . urlencode($m[1]);
    } elseif (preg_match('#youtube\.com/@([a-zA-Z0-9_.-]+)#', $input, $m)) {
        $params = 'forHandle=' . urlencode('@' . $m[1]);
    } elseif (preg_match('#youtube\.com/user/([a-zA-Z0-9_-]+)#', $input, $m)) {
        $params = 'forUsername=' . urlencode($m[1]);
    } elseif (preg_match('#^UC[a-zA-Z0-9_-]{20,}$#', $input)) {
        // L'utente ha incollato direttamente l'ID canale (inizia sempre con "UC")
        $params = 'id=' . urlencode($input);
    } elseif (preg_match('#^@?[a-zA-Z0-9_.-]+$#', $input)) {
        // Solo un handle tipo "@nomeband" senza URL completo
        $params = 'forHandle=' . urlencode('@' . ltrim($input, '@'));
    } else {
        return null;
    }

    $url = 'https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails&' . $params . '&key=' . urlencode($apiKey);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    if (empty($data['items'][0])) {
        return null;
    }
    $channel = $data['items'][0];

    return [
        'channel_id' => $channel['id'],
        'channel_name' => $channel['snippet']['title'] ?? '',
        // La playlist "tutti i caricamenti" di un canale ha per convenzione lo stesso ID del
        // canale con "UC" sostituito da "UU" — evita una chiamata API aggiuntiva
        'uploads_playlist_id' => 'UU' . substr($channel['id'], 2),
    ];
}

// Ultimi video caricati sul canale (dalla sua playlist "uploads").
function youtubeGetChannelVideos(string $uploadsPlaylistId, int $max = 12): array {
    $apiKey = getYoutubeApiKey();
    if (!$apiKey) {
        return [];
    }
    $url = 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=' . $max
         . '&playlistId=' . urlencode($uploadsPlaylistId) . '&key=' . urlencode($apiKey);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $videos = [];
    foreach (($data['items'] ?? []) as $item) {
        $sn = $item['snippet'] ?? [];
        if (empty($sn['resourceId']['videoId'])) {
            continue;
        }
        $videos[] = [
            'video_id' => $sn['resourceId']['videoId'],
            'title' => $sn['title'] ?? '',
            'thumbnail' => $sn['thumbnails']['medium']['url'] ?? ($sn['thumbnails']['default']['url'] ?? null),
            'published_at' => $sn['publishedAt'] ?? null,
        ];
    }
    return $videos;
}
