<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì
require_once __DIR__ . '/gemini.php'; // per tradurre sommario/ingredienti in italiano, vedi sotto

/**
 * Client minimale per l'API di Spoonacular — ricerca ricette. Stesso approccio già usato per
 * TMDb/Google Books: solo httpRequest(), nessuna libreria esterna.
 */

function getSpoonacularApiKey(): ?string {
    $key = getSiteSetting('spoonacular_api_key');
    return $key !== '' ? $key : null;
}

// Il campo "summary" di Spoonacular è HTML (grassetto, link) pensato per un sito che lo
// renderizza come tale — qui invece va mostrato come testo semplice (nl2br + e()), quindi va
// ripulito: stessa logica già usata per la descrizione di Google Books.
function cleanSpoonacularSummary(string $html): string {
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = preg_replace('#</p>#i', "\n\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

// Cerca una ricetta per nome. Restituisce fino a 10 risultati, scartando chi non ha nessuna foto
// (stessa scelta già fatta per TMDb/Google Books: senza immagine il pulsante è spoglio). Le
// immagini di Spoonacular sono già abbastanza grandi (312x231 di default) per og:image.
function spoonacularSearchRecipe(string $query): array {
    $apiKey = getSpoonacularApiKey();
    if (!$apiKey || trim($query) === '') {
        return [];
    }
    $url = 'https://api.spoonacular.com/recipes/complexSearch?apiKey=' . urlencode($apiKey)
         . '&number=10&query=' . urlencode($query);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (($data['results'] ?? []) as $r) {
        if (empty($r['image'])) {
            continue;
        }
        $results[] = [
            'id' => (string) $r['id'],
            'name' => $r['title'],
            'image' => $r['image'],
            'spoonacular_url' => 'https://spoonacular.com/recipes/-' . $r['id'],
        ];
    }
    return $results;
}

// A differenza di TMDb/TheSportsDB, Spoonacular non ha contenuti multilingua: sommario e
// ingredienti arrivano quasi sempre in inglese, presi così come sono dal sito di origine della
// ricetta. Qui si traducono in italiano con Gemini (se configurato in Area Admin → Assistente
// AI) — un'unica chiamata per entrambi i testi, per non raddoppiare i tempi di caricamento della
// pagina. Se Gemini non è configurato, o la traduzione fallisce o torna un numero di ingredienti
// diverso dall'originale (segno che il modello non ha seguito il formato atteso), si tiene il
// testo inglese originale piuttosto che rischiare una traduzione incompleta o disallineata.
function spoonacularTranslateToItalian(string $overview, array $ingredients): array {
    $original = ['overview' => $overview, 'ingredients' => $ingredients];
    if (!getGeminiApiKey() || ($overview === '' && !$ingredients)) {
        return $original;
    }
    $prompt = "Traduci in italiano naturale, senza aggiungere né togliere informazioni, la seguente descrizione di una ricetta di cucina e il suo elenco di ingredienti. Rispondi SOLO nel formato esatto qui sotto, senza nessun altro testo prima o dopo:\n\n"
        . "DESCRIZIONE:\n<qui la descrizione tradotta>\n\nINGREDIENTI:\n<un ingrediente tradotto per riga, esattamente lo stesso numero di righe dell'elenco originale>\n\n"
        . "--- TESTO ORIGINALE ---\nDescrizione:\n" . $overview . "\n\nIngredienti:\n" . implode("\n", $ingredients);

    $response = geminiGenerateText($prompt);
    if (!$response || !preg_match('/DESCRIZIONE:\s*(.*?)\s*INGREDIENTI:\s*(.*)/is', $response, $m)) {
        return $original;
    }
    $translatedOverview = trim($m[1]);
    $translatedIngredients = array_values(array_filter(array_map('trim', explode("\n", $m[2]))));
    if ($translatedOverview === '' || count($translatedIngredients) !== count($ingredients)) {
        return $original;
    }
    return ['overview' => $translatedOverview, 'ingredients' => $translatedIngredients];
}

// Dettagli di una ricetta (usata nella pagina dedicata di "Ricette che amo" per mostrare tempo di
// preparazione, porzioni, ingredienti e link alla ricetta completa oltre al titolo e alla foto già
// salvati).
function spoonacularGetRecipeDetails(string $recipeId): ?array {
    $apiKey = getSpoonacularApiKey();
    if (!$apiKey || trim($recipeId) === '') {
        return null;
    }
    $url = 'https://api.spoonacular.com/recipes/' . urlencode($recipeId) . '/information?apiKey=' . urlencode($apiKey) . '&includeNutrition=false';
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $r = json_decode($response, true);
    if (!$r || empty($r['id'])) {
        return null;
    }
    $ingredients = [];
    foreach (($r['extendedIngredients'] ?? []) as $ing) {
        if (!empty($ing['original'])) {
            $ingredients[] = $ing['original'];
        }
    }
    $overview = cleanSpoonacularSummary($r['summary'] ?? '');
    $translated = spoonacularTranslateToItalian($overview, $ingredients);

    return [
        'overview' => $translated['overview'],
        'ready_in_minutes' => $r['readyInMinutes'] ?? null,
        'servings' => $r['servings'] ?? null,
        'ingredients' => $translated['ingredients'],
        'source_url' => $r['sourceUrl'] ?? null,
        'spoonacular_url' => 'https://spoonacular.com/recipes/-' . $r['id'],
    ];
}
