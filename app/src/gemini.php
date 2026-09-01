<?php
/**
 * Client minimale per l'API di Google Gemini (generazione testo), via cURL — vedi il commento
 * su geminiGenerateText() per il perché non riusa httpRequest() come Spotify/YouTube. Richiede
 * che functions.php (per getSiteSetting()) sia già stato incluso dal chiamante.
 */

function getGeminiApiKey(): ?string {
    $key = getSiteSetting('gemini_api_key');
    return $key !== '' ? $key : null;
}

// Genera un breve testo a partire da un prompt. Restituisce null se la chiave non è
// configurata o la richiesta fallisce (l'errore resta nei log).
//
// Usa cURL direttamente invece di httpRequest() (che usa file_get_contents): su alcune
// infrastrutture, la POST HTTPS verso generativelanguage.googleapis.com fallisce con
// file_get_contents ("HTTP request failed!", nessun dettaglio) pur funzionando perfettamente
// con cURL — probabile incompatibilità con un livello di rete/proxy in mezzo.
function geminiGenerateText(string $prompt): ?string {
    $apiKey = getGeminiApiKey();
    if (!$apiKey) {
        return null;
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . urlencode($apiKey);
    $body = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        error_log("[Gemini] richiesta fallita (HTTP {$status}): " . ($curlError ?: substr((string) $response, 0, 300)));
        return null;
    }

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    return $text !== null ? trim($text) : null;
}
