<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì
require_once __DIR__ . '/gemini.php'; // per tradurre istruzioni/ingredienti in italiano, vedi sotto

/**
 * Client minimale per l'API di TheMealDB — ricerca ricette. Stesso approccio già usato per
 * TMDb/Google Books/TheSportsDB: solo httpRequest(), nessuna libreria esterna. "1" è la chiave di
 * test pubblica, sempre valida per l'uso base (nessuna registrazione richiesta per iniziare) — una
 * personale si ottiene gratis su patreon.com/themealdb, se in futuro "1" venisse mai limitata
 * (come già successo con la "3" di TheSportsDB).
 */

function getThemealdbApiKey(): string {
    $key = getSiteSetting('themealdb_api_key');
    return $key ? $key : '1';
}

// Le istruzioni di TheMealDB sono testo semplice (non HTML come il "summary" di Spoonacular), ma
// spesso con interruzioni di riga incoerenti — solo normalizzate, non serve ripulire tag.
function cleanThemealdbInstructions(string $text): string {
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

// TheMealDB non ha un campo "ingredienti" unico: sono 20 coppie ingrediente/quantità a colonne
// fisse (strIngredient1..20 / strMeasure1..20), quasi sempre con molte colonne vuote da scartare.
function themealdbExtractIngredients(array $meal): array {
    $ingredients = [];
    for ($i = 1; $i <= 20; $i++) {
        $name = trim($meal["strIngredient{$i}"] ?? '');
        if ($name === '') {
            continue;
        }
        $measure = trim($meal["strMeasure{$i}"] ?? '');
        $ingredients[] = trim($measure . ' ' . $name);
    }
    return $ingredients;
}

// Traduce istruzioni e ingredienti in italiano via Gemini (se configurato) — TheMealDB, come
// Spoonacular, non ha contenuti multilingua: arrivano in inglese così come sono. Un'unica
// chiamata per entrambi i testi, per non raddoppiare i tempi di caricamento della pagina. Se
// Gemini non è configurato, o la traduzione non torna un numero di ingredienti coerente con
// l'originale, resta il testo inglese invece di rischiare una traduzione incompleta o
// disallineata.
function themealdbTranslateToItalian(string $overview, array $ingredients): array {
    $original = ['overview' => $overview, 'ingredients' => $ingredients];
    if (!getGeminiApiKey() || ($overview === '' && !$ingredients)) {
        return $original;
    }
    $prompt = "Traduci in italiano naturale, senza aggiungere né togliere informazioni, la seguente procedura di preparazione di una ricetta di cucina e il suo elenco di ingredienti. Rispondi SOLO nel formato esatto qui sotto, senza nessun altro testo prima o dopo:\n\n"
        . "DESCRIZIONE:\n<qui la procedura tradotta>\n\nINGREDIENTI:\n<un ingrediente tradotto per riga, esattamente lo stesso numero di righe dell'elenco originale>\n\n"
        . "--- TESTO ORIGINALE ---\nProcedura:\n" . $overview . "\n\nIngredienti:\n" . implode("\n", $ingredients);

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

// Cerca una ricetta per nome. Restituisce fino a 10 risultati, scartando chi non ha nessuna foto
// (stessa scelta già fatta per TMDb/Google Books: senza immagine il pulsante è spoglio). Le foto
// di TheMealDB sono già abbastanza grandi per og:image.
function themealdbSearchRecipe(string $query): array {
    if (trim($query) === '') {
        return [];
    }
    $url = 'https://www.themealdb.com/api/json/v1/' . urlencode(getThemealdbApiKey()) . '/search.php?s=' . urlencode($query);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return [];
    }
    $data = json_decode($response, true);
    $results = [];
    foreach (array_slice($data['meals'] ?? [], 0, 10) as $m) {
        if (empty($m['strMealThumb'])) {
            continue;
        }
        $results[] = [
            'id' => (string) $m['idMeal'],
            'name' => $m['strMeal'] . (!empty($m['strArea']) ? ' — ' . $m['strArea'] : ''),
            'title' => $m['strMeal'],
            'image' => $m['strMealThumb'],
        ];
    }
    return $results;
}

// Dettagli di una ricetta (usata nella pagina dedicata di "Ricette che amo" per mostrare
// categoria, cucina di provenienza e ingredienti, oltre al titolo e alla foto già salvati).
function themealdbGetRecipeDetails(string $mealId): ?array {
    if (trim($mealId) === '') {
        return null;
    }
    $url = 'https://www.themealdb.com/api/json/v1/' . urlencode(getThemealdbApiKey()) . '/lookup.php?i=' . urlencode($mealId);
    $response = httpRequest('GET', $url);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    $m = ($data['meals'] ?? [])[0] ?? null;
    if (!$m) {
        return null;
    }

    $overview = cleanThemealdbInstructions($m['strInstructions'] ?? '');
    $ingredients = themealdbExtractIngredients($m);
    $translated = themealdbTranslateToItalian($overview, $ingredients);

    return [
        'overview' => $translated['overview'],
        'ingredients' => $translated['ingredients'],
        'category' => $m['strCategory'] ?? null,
        'area' => $m['strArea'] ?? null,
        'source_url' => $m['strSource'] ?? null,
        'themealdb_url' => 'https://www.themealdb.com/meal/' . $mealId,
    ];
}
