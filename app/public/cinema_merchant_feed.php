<?php
// Feed prodotti (film in programmazione) per Google Merchant Center e Meta Commerce Manager —
// stesso JSON già configurato in Dashboard -> Cinema (formato 18tickets), letto al momento della
// richiesta: non dipende dalla sincronizzazione del modulo Link (syncCinemaFilms()), che tra
// l'altro non porta con sé descrizione/prezzo. Nessun login richiesto: è pensato per essere
// recuperato periodicamente dai bot di Google/Meta.
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Content-Type: application/xml; charset=UTF-8');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, p.display_name, p.cinema_films_json_url, p.cinema_ticket_price
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$profile = $stmt->fetch();

if (!$profile) {
    http_response_code(404);
    exit('<?xml version="1.0" encoding="UTF-8"?><error>Profilo non trovato.</error>');
}

$jsonUrl = trim($profile['cinema_films_json_url'] ?? '');
if ($jsonUrl === '') {
    http_response_code(404);
    exit('<?xml version="1.0" encoding="UTF-8"?><error>Nessun feed film configurato per questo profilo.</error>');
}

$raw = cinemaHttpGet($jsonUrl, 25);
$data = $raw !== null ? json_decode($raw, true) : null;
$films = (is_array($data) && isset($data['films']) && is_array($data['films'])) ? $data['films'] : [];

$priceValue = $profile['cinema_ticket_price'];
$priceText = $priceValue !== null ? number_format((float) $priceValue, 2, '.', '') . ' EUR' : null;

$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;

// DOMDocument::createElement($tag, $value) NON esegue l'escaping del testo (il secondo
// argomento va interpretato come contenuto XML grezzo) — un titolo con "&" o "<" (comunissimo
// nei film, es. "Tom & Jerry") produce un nodo vuoto o un errore silenzioso. createTextNode()
// invece lo tratta come testo puro ed esegue l'escaping corretto in fase di serializzazione.
$textEl = function (string $tag, string $text) use ($xml): DOMElement {
    $el = $xml->createElement($tag);
    $el->appendChild($xml->createTextNode($text));
    return $el;
};

$rss = $xml->createElement('rss');
$rss->setAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
$rss->setAttribute('version', '2.0');
$xml->appendChild($rss);

$channel = $xml->createElement('channel');
$rss->appendChild($channel);

$channel->appendChild($textEl('title', $profile['display_name'] ?? siteName()));
$channel->appendChild($textEl('link', siteUrl('/' . $slug)));
$channel->appendChild($textEl('description', 'Film in programmazione di ' . ($profile['display_name'] ?? '')));

foreach ($films as $film) {
    $ref = trim((string) ($film['id'] ?? ''));
    $title = trim((string) ($film['title'] ?? ''));
    $url = trim((string) ($film['film_url'] ?? '')) ?: trim((string) ($film['film_url_for_cinema'] ?? ''));
    $image = trim((string) ($film['playbill_path'] ?? ''));
    if ($ref === '' || $title === '' || $url === '') {
        continue;
    }

    $item = $xml->createElement('item');
    $item->appendChild($textEl('g:id', $ref));
    $item->appendChild($textEl('g:title', mb_substr($title, 0, 150)));

    $plot = trim((string) ($film['plot'] ?? ''));
    $item->appendChild($textEl('g:description', mb_substr($plot !== '' ? $plot : $title, 0, 5000)));

    $item->appendChild($textEl('g:link', $url));
    if ($image !== '') {
        $item->appendChild($textEl('g:image_link', $image));
    }
    $item->appendChild($textEl('g:brand', $profile['display_name'] ?? siteName()));
    $item->appendChild($textEl('g:condition', 'new'));
    $item->appendChild($textEl('g:availability', 'in stock'));
    if ($priceText !== null) {
        $item->appendChild($textEl('g:price', $priceText));
    }

    $channel->appendChild($item);
}

echo $xml->saveXML();
