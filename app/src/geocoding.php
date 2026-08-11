<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Geocodifica indirizzi e mappa incorporata tramite OpenStreetMap — completamente gratuito,
 * nessuna chiave API, nessun limite di fatturazione (a differenza di Google Maps). Stesso
 * approccio già usato per Spotify/YouTube: solo file_get_contents, nessuna libreria esterna.
 *
 * Nominatim (il servizio di ricerca indirizzi di OpenStreetMap) richiede, per la sua usage
 * policy pubblica, uno User-Agent identificativo e un uso "umano" non massivo — qui va bene:
 * è una singola ricerca manuale del band manager quando aggiunge la mappa dalla dashboard, non
 * una chiamata ad ogni visita della pagina pubblica.
 */

// Cerca un indirizzo testuale e restituisce ['lat','lng','display_name'], o null se non trovato
// o se il servizio non risponde.
function geocodeAddress(string $address): ?array {
    $address = trim($address);
    if ($address === '') {
        return null;
    }
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
    $headers = ['User-Agent: ChiFaCosaApp/1.0 (' . siteUrl('/') . ')'];
    $response = httpRequest('GET', $url, $headers);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
        return null;
    }
    return [
        'lat' => (float) $data[0]['lat'],
        'lng' => (float) $data[0]['lon'],
        'display_name' => $data[0]['display_name'] ?? $address,
    ];
}

// Mappa incorporata (iframe) centrata su lat/lng, con segnaposto — sempre OpenStreetMap, mai
// Google Maps, per restare gratis su qualsiasi volume di visite alla pagina pubblica.
function renderOsmEmbed(float $lat, float $lng): string {
    $span = 0.006; // ~600m di raggio visibile, sufficiente per un indirizzo puntuale
    $bbox = ($lng - $span) . ',' . ($lat - $span) . ',' . ($lng + $span) . ',' . ($lat + $span);
    $src = 'https://www.openstreetmap.org/export/embed.html?bbox=' . urlencode($bbox)
         . '&layer=mapnik&marker=' . urlencode($lat . ',' . $lng);
    $linkUrl = 'https://www.openstreetmap.org/?mlat=' . urlencode((string) $lat) . '&mlon=' . urlencode((string) $lng) . '#map=16/' . $lat . '/' . $lng;
    return '<div class="link-map-embed">'
         . '<iframe src="' . e($src) . '" loading="lazy" title="Mappa" style="border:0;width:100%;height:220px;border-radius:14px;display:block;"></iframe>'
         . '<a href="' . e($linkUrl) . '" target="_blank" rel="noopener" class="link-map-directions">Apri indicazioni stradali ↗</a>'
         . '</div>';
}
