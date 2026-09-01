<?php
require_once __DIR__ . '/spotify.php'; // riusa la funzione httpRequest() già scritta lì

/**
 * Client minimale per l'API di Google Gemini (generazione testo). Stesso approccio già usato
 * per Spotify/YouTube: nessuna libreria esterna, solo httpRequest() + una API Key gratuita
 * ottenuta da aistudio.google.com.
 */

function getGeminiApiKey(): ?string {
    $key = getSiteSetting('gemini_api_key');
    return $key !== '' ? $key : null;
}

// Genera un breve testo a partire da un prompt. Restituisce null se la chiave non è
// configurata o la richiesta fallisce (l'errore resta nei log via httpRequest()).
function geminiGenerateText(string $prompt): ?string {
    $apiKey = getGeminiApiKey();
    if (!$apiKey) {
        return null;
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
    $body = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
    ]);
    $response = httpRequest('POST', $url, ['Content-Type: application/json'], $body);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    return $text !== null ? trim($text) : null;
}
