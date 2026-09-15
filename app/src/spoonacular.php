<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

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
    return [
        'overview' => cleanSpoonacularSummary($r['summary'] ?? ''),
        'ready_in_minutes' => $r['readyInMinutes'] ?? null,
        'servings' => $r['servings'] ?? null,
        'ingredients' => $ingredients,
        'source_url' => $r['sourceUrl'] ?? null,
        'spoonacular_url' => 'https://spoonacular.com/recipes/-' . $r['id'],
    ];
}
