<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Client minimale per la CrossRef REST API — ricerca pubblicazioni scientifiche/accademiche
 * (articoli di riviste, atti di convegni...) tramite DOI. Nessuna chiave richiesta: è un servizio
 * pubblico e gratuito. Se in ADMIN -> CrossRef è impostata un'email di contatto, viene inviata
 * come parametro "mailto" — CrossRef la chiama "polite pool": non autentica nulla, ma dà priorità
 * e limiti di frequenza migliori rispetto al pool anonimo. Stesso approccio già usato per
 * Spotify/TMDb/Google Books: solo httpRequest(), nessuna libreria esterna.
 */

function getCrossrefContactEmail(): ?string {
    $email = getSiteSetting('crossref_contact_email');
    return $email !== '' ? $email : null;
}

function crossrefPoliteParam(): string {
    $email = getCrossrefContactEmail();
    return $email ? '&mailto=' . urlencode($email) : '';
}

// L'abstract CrossRef (quando presente) è marcato con tag in stile JATS (<jats:p>...</jats:p>) —
// va ripulito allo stesso modo della descrizione di Google Books: tag rimossi, entità decodificate.
function cleanCrossrefAbstract(string $html): string {
    $html = preg_replace('#</?jats:p[^>]*>#i', "\n\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

// Formatta un array "date-parts" di CrossRef (es. [2023, 6, 14] o solo [2023]) come stringa
// YYYY-MM-DD — solo l'anno viene poi mostrato dal template pubblico (stessa logica di
// googleBooksGetVolumeDetails), ma tenerla completa non costa nulla.
function crossrefFormatDateParts(?array $parts): ?string {
    if (!$parts || empty($parts[0])) {
        return null;
    }
    $y = (int) $parts[0];
    $m = isset($parts[1]) ? (int) $parts[1] : 1;
    $d = isset($parts[2]) ? (int) $parts[2] : 1;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function crossrefFormatAuthors(array $authors, int $limit = 6): ?string {
    if (!$authors) {
        return null;
    }
    $names = [];
    foreach ($authors as $a) {
        $name = trim(($a['given'] ?? '') . ' ' . ($a['family'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    if (!$names) {
        return null;
    }
    if (count($names) > $limit) {
        $names = array_slice($names, 0, $limit);
        $names[] = 'et al.';
    }
    return implode(', ', $names);
}

// Cerca una pubblicazione per titolo/autore/parole chiave. Restituisce fino a 10 risultati — a
// differenza di Spotify/TMDb/Google Books, CrossRef non fornisce mai una copertina: 'image' resta
// sempre null (gestito già correttamente da tutti i template esistenti, che mostrano l'immagine
// solo se presente).
function crossrefSearch(string $query): array {
    if (trim($query) === '') {
        return [];
    }
    $url = 'https://api.crossref.org/works?rows=10&select=DOI,title,author,container-title,published'
         . '&query=' . urlencode($query) . crossrefPoliteParam();
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (($data['message']['items'] ?? []) as $w) {
        $title = $w['title'][0] ?? null;
        $doi = $w['DOI'] ?? null;
        if (!$title || !$doi) {
            continue;
        }
        $authors = crossrefFormatAuthors($w['author'] ?? []);
        $results[] = [
            'id' => $doi,
            'name' => $title . ($authors ? ' — ' . $authors : ''),
            'title' => $title,
            'authors' => $authors,
            'image' => null,
        ];
        if (count($results) >= 10) {
            break;
        }
    }
    return $results;
}

// Dettagli di una pubblicazione (usata nella pagina dedicata di "Pubblicazioni che amo" per
// mostrare autori/rivista/abstract/anno oltre al titolo già salvato).
function crossrefGetWorkDetails(string $doi): ?array {
    if (trim($doi) === '') {
        return null;
    }
    $url = 'https://api.crossref.org/works/' . urlencode($doi) . '?' . ltrim(crossrefPoliteParam(), '&');
    $response = httpRequest('GET', rtrim($url, '?'));
    if (!$response) {
        return null;
    }
    $w = json_decode($response, true)['message'] ?? null;
    if (!$w) {
        return null;
    }
    $abstract = !empty($w['abstract']) ? cleanCrossrefAbstract($w['abstract']) : null;
    $releaseDate = crossrefFormatDateParts($w['published']['date-parts'][0] ?? null);
    return [
        'overview' => $abstract,
        'authors' => crossrefFormatAuthors($w['author'] ?? []),
        'journal' => $w['container-title'][0] ?? null,
        'release_date' => $releaseDate,
        'genres' => $w['subject'] ?? [],
    ];
}
