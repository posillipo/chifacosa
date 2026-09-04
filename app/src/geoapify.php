<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Client minimale per Geoapify — usato SOLO per generare una miniatura statica della mappa
 * (un'immagine vera, non un iframe interattivo) da usare come og:image di fallback nel modulo
 * Viaggi quando il proprietario non carica una propria foto del luogo. La ricerca dei luoghi
 * resta invece su OpenStreetMap Nominatim (geocodeAddressMultiple() in geocoding.php),
 * completamente gratuita: Geoapify serve solo per l'unica cosa che Nominatim non offre, cioè
 * un'immagine scaricabile. Piano gratuito: ~3000 richieste/giorno, nessuna carta richiesta.
 */

function getGeoapifyApiKey(): ?string {
    $key = getSiteSetting('geoapify_api_key');
    return $key !== '' ? $key : null;
}

// Scarica una miniatura statica (JPEG) della mappa centrata su lat/lng con un segnaposto, e la
// salva su disco nella stessa cartella/convenzione di handleCoverUpload(), così finisce servita
// come un file normale (nessuna chiave API esposta nell'HTML pubblico). Restituisce il percorso
// relativo salvato in DB, o null se la chiave non è configurata o il download fallisce.
function geoapifyGenerateStaticMap(float $lat, float $lng, string $slug): ?string {
    $apiKey = getGeoapifyApiKey();
    if (!$apiKey) {
        return null;
    }
    // 640x800 = proporzione 4:5 (come richiesto per la condivisione social), zoom leggermente più
    // stretto sul punto d'interesse rispetto a prima (16 invece di 15).
    $url = 'https://maps.geoapify.com/v1/staticmap?style=osm-bright&width=640&height=800'
         . '&center=lonlat:' . $lng . ',' . $lat . '&zoom=16'
         . '&marker=lonlat:' . $lng . ',' . $lat . ';color:%23ff3b30;size:large'
         . '&format=jpeg&apiKey=' . urlencode($apiKey);
    $image = httpRequest('GET', $url);
    if (!$image || strlen($image) < 500) { // risposta d'errore in JSON è sempre molto più corta di una vera immagine
        return null;
    }
    $fname = 'map_' . bin2hex(random_bytes(6)) . '.jpg';
    $dir = '/var/www/html/uploads/images/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (file_put_contents($dir . '/' . $fname, $image) === false) {
        return null;
    }
    return 'uploads/images/' . $slug . '/' . $fname;
}
