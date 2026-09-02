<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/gemini.php';
header('Content-Type: application/json; charset=UTF-8');

$user = requireLogin();
$profile = getActingProfile($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non permesso.']);
    exit;
}

checkCsrf();

if (!getGeminiApiKey()) {
    echo json_encode(['ok' => false, 'error' => 'L\'assistente AI non è ancora configurato su questo sito.']);
    exit;
}

$keywords = trim($_POST['keywords'] ?? '');
if ($keywords === '') {
    echo json_encode(['ok' => false, 'error' => 'Scrivi almeno qualche parola chiave.']);
    exit;
}

$context = 'Sei l\'assistente social di "' . $profile['display_name'] . '"'
    . (!empty($profile['genere']) ? ' (' . $profile['genere'] . ')' : '')
    . ' su ' . siteName() . ', un sito dove chiunque (band, attori, locali, appassionati di ogni genere) ha una propria pagina.';

$prompt = $context . " Scrivi un breve post per i social (massimo 2-3 frasi, tono naturale e coinvolgente, "
    . "niente hashtag a meno che non siano espliciti nelle parole chiave, nessun emoji eccessivo) "
    . "a partire da queste parole chiave/istruzioni: \"" . $keywords . "\". "
    . "Rispondi SOLO con il testo del post, senza virgolette, senza titoli, senza commenti.";

$text = geminiGenerateText($prompt);

if ($text === null) {
    echo json_encode(['ok' => false, 'error' => 'Generazione non riuscita. Riprova tra poco.']);
    exit;
}

echo json_encode(['ok' => true, 'text' => $text]);
