<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
require_once __DIR__ . '/../src/telegram.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Metodo non permesso, usa POST.');
}

$auth = authenticateApiRequest();
$data = apiReadJsonBody();

$text = trim($data['text'] ?? '');
if ($text === '') {
    apiError(422, 'Il campo "text" è obbligatorio.', $auth['token_id'], $auth['user_id']);
}

if (!getTelegramBotToken()) {
    apiError(503, 'Telegram non è configurato su questo sito.', $auth['token_id'], $auth['user_id']);
}

$stmt = getDB()->prepare('SELECT telegram_chat_id, telegram_chat_title FROM profiles WHERE user_id = ?');
$stmt->execute([$auth['user_id']]);
$profile = $stmt->fetch();

if (!$profile || empty($profile['telegram_chat_id'])) {
    apiError(422, 'Nessun canale Telegram collegato a questo profilo.', $auth['token_id'], $auth['user_id']);
}

$parseMode = $data['parse_mode'] ?? 'HTML';
if (!in_array($parseMode, ['HTML', 'Markdown', 'MarkdownV2', null], true)) {
    $parseMode = 'HTML';
}

$result = telegramSendMessage($profile['telegram_chat_id'], $text, $parseMode);

if (!$result) {
    apiError(502, 'Invio su Telegram fallito. Verifica che il bot sia amministratore del canale.', $auth['token_id'], $auth['user_id']);
}

apiRespond(200, [
    'success' => true,
    'message' => 'Messaggio inviato su Telegram.',
    'chat_title' => $profile['telegram_chat_title'],
], $auth['token_id'], $auth['user_id']);
