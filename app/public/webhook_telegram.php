<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/telegram.php';

$secret = getSiteSetting('telegram_webhook_secret') ?: '';
if ($secret !== '') {
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($secret, $header)) {
        http_response_code(403);
        exit;
    }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    exit;
}

if (isset($input['callback_query'])) {
    handleCallbackQuery($input['callback_query']);
    exit;
}

$message = $input['message'] ?? null;
if (!$message) {
    http_response_code(200);
    exit;
}

$chatId = (string) $message['chat']['id'];
$telegramUserId = (int) $message['from']['id'];
$text = trim($message['text'] ?? $message['caption'] ?? '');

// Solo chat private col bot
if (($message['chat']['type'] ?? '') !== 'private') {
    http_response_code(200);
    exit;
}

// /start CODE — collegamento account
if (preg_match('#^/start\s+([A-F0-9]{8})$#i', $text, $m)) {
    $code = strtoupper($m[1]);
    $profile = telegramFindProfileByLinkCode($code);
    if (!$profile) {
        telegramSendMessage($chatId, '❌ Codice non valido o scaduto. Generane uno nuovo dalla dashboard.');
        exit;
    }
    getDB()->prepare('UPDATE profiles SET telegram_user_id=? WHERE user_id=?')
        ->execute([$telegramUserId, $profile['user_id']]);
    telegramSendMessage($chatId, '✅ Account collegato a <b>' . telegramEscapeHtml($profile['display_name']) . '</b>!' . "\n\nOra puoi inviarmi una foto (con o senza testo) e sceglierai se pubblicarla sulla Timeline o sul Blog.");
    exit;
}

// /start senza codice
if ($text === '/start') {
    telegramSendMessage($chatId, "Ciao! Per collegarmi al tuo profilo, vai nella dashboard → Integrazioni → Telegram e clicca \"Genera codice\". Poi inviami:\n\n<code>/start IL_CODICE</code>");
    exit;
}

// /scollega — rimuovi il collegamento
if ($text === '/scollega' || $text === '/unlink') {
    $profile = telegramFindProfileByTelegramUserId($telegramUserId);
    if ($profile) {
        getDB()->prepare('UPDATE profiles SET telegram_user_id=NULL WHERE user_id=?')
            ->execute([$profile['user_id']]);
        telegramSendMessage($chatId, '✅ Account scollegato.');
    } else {
        telegramSendMessage($chatId, 'Nessun account collegato.');
    }
    exit;
}

// Contenuto da pubblicare: serve un account collegato
$profile = telegramFindProfileByTelegramUserId($telegramUserId);
if (!$profile) {
    telegramSendMessage($chatId, "Non hai ancora collegato il tuo account. Vai nella dashboard → Integrazioni → Telegram e genera un codice.");
    exit;
}

// Foto ricevuta
$photo = $message['photo'] ?? null;
$fileId = null;
if ($photo) {
    // Telegram invia più risoluzioni; prendi la più grande
    $fileId = end($photo)['file_id'];
}

if (!$fileId && $text === '') {
    telegramSendMessage($chatId, "Inviami una foto (con o senza testo) per pubblicarla, oppure solo un testo per un post in Timeline.");
    exit;
}

// Salva nella tabella pending e chiedi dove pubblicare
$stmt = getDB()->prepare('INSERT INTO telegram_pending_posts (telegram_user_id, profile_user_id, file_id, caption) VALUES (?,?,?,?)');
$stmt->execute([$telegramUserId, $profile['user_id'], $fileId, $text ?: null]);
$pendingId = (int) getDB()->lastInsertId();

$preview = $fileId ? '📷 Foto' : '📝 Testo';
if ($text !== '') {
    $short = mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    $preview .= ': <i>' . telegramEscapeHtml($short) . '</i>';
}

telegramSendMessageWithKeyboard($chatId, $preview . "\n\nDove vuoi pubblicare?", [
    [
        ['text' => '📱 Timeline', 'callback_data' => 'pub:tl:' . $pendingId],
        ['text' => '📝 Blog', 'callback_data' => 'pub:bl:' . $pendingId],
    ],
    [
        ['text' => '❌ Annulla', 'callback_data' => 'pub:no:' . $pendingId],
    ],
]);

// Pulizia pending vecchi (> 1 ora)
getDB()->exec('DELETE FROM telegram_pending_posts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)');

function handleCallbackQuery(array $cq): void {
    $data = $cq['data'] ?? '';
    $callbackId = $cq['id'];
    $chatId = (string) ($cq['message']['chat']['id'] ?? '');
    $messageId = (int) ($cq['message']['message_id'] ?? 0);
    $telegramUserId = (int) ($cq['from']['id'] ?? 0);

    if (!preg_match('#^pub:(tl|bl|no):(\d+)$#', $data, $m)) {
        telegramAnswerCallbackQuery($callbackId, 'Azione non riconosciuta.');
        return;
    }

    $action = $m[1];
    $pendingId = (int) $m[2];

    $stmt = getDB()->prepare('SELECT * FROM telegram_pending_posts WHERE id=? AND telegram_user_id=?');
    $stmt->execute([$pendingId, $telegramUserId]);
    $pending = $stmt->fetch();

    if (!$pending) {
        telegramAnswerCallbackQuery($callbackId, 'Richiesta scaduta.');
        if ($chatId && $messageId) {
            telegramEditMessageText($chatId, $messageId, '⏰ Richiesta scaduta. Invia di nuovo la foto.');
        }
        return;
    }

    // Annulla
    if ($action === 'no') {
        getDB()->prepare('DELETE FROM telegram_pending_posts WHERE id=?')->execute([$pendingId]);
        telegramAnswerCallbackQuery($callbackId, 'Annullato.');
        if ($chatId && $messageId) {
            telegramEditMessageText($chatId, $messageId, '❌ Pubblicazione annullata.');
        }
        return;
    }

    $profile = telegramFindProfileByTelegramUserId($telegramUserId);
    if (!$profile) {
        telegramAnswerCallbackQuery($callbackId, 'Account non più collegato.');
        getDB()->prepare('DELETE FROM telegram_pending_posts WHERE id=?')->execute([$pendingId]);
        return;
    }

    // Scarica la foto se presente
    $imagePath = null;
    if ($pending['file_id']) {
        $imagePath = telegramSaveIncomingPhoto($pending['file_id'], $profile['slug']);
        if (!$imagePath) {
            telegramAnswerCallbackQuery($callbackId, 'Errore nel download della foto.');
            if ($chatId && $messageId) {
                telegramEditMessageText($chatId, $messageId, '❌ Non sono riuscito a scaricare la foto. Riprova.');
            }
            getDB()->prepare('DELETE FROM telegram_pending_posts WHERE id=?')->execute([$pendingId]);
            return;
        }
    }

    $caption = $pending['caption'] ?: '';

    if ($action === 'tl') {
        // Crea post Timeline
        $stmt = getDB()->prepare('INSERT INTO timeline_posts (user_id, testo, image_path, visibility, in_feed, source) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$profile['user_id'], $caption ?: null, $imagePath, 'public', 1, 'telegram']);
        $result = '📱 Pubblicato in Timeline!';
        $postId = (int) getDB()->lastInsertId();
        $url = siteUrl('/' . $profile['slug'] . '/timeline/' . $postId);
    } else {
        // Crea articolo Blog
        $title = $caption !== '' ? mb_substr($caption, 0, 120) : 'Da Telegram';
        $content = $caption ?: '';
        $excerpt = textExcerpt($content, 200);
        $slug = generateUniquePostSlug((int) $profile['user_id'], $title);
        $stmt = getDB()->prepare('INSERT INTO blog_posts (user_id, title, slug, excerpt, content, cover_path, published_at, in_feed) VALUES (?,?,?,?,?,?,NOW(),?)');
        $stmt->execute([$profile['user_id'], $title, $slug, $excerpt, $content, $imagePath, 1]);
        $postId = (int) getDB()->lastInsertId();
        $publishedAt = date('Y-m-d H:i:s');
        $url = siteUrl(blogPostUrl($profile['slug'], ['published_at' => $publishedAt, 'slug' => $slug]));
        $result = '📝 Pubblicato sul Blog!';
    }

    getDB()->prepare('DELETE FROM telegram_pending_posts WHERE id=?')->execute([$pendingId]);

    telegramAnswerCallbackQuery($callbackId, $action === 'tl' ? 'Post in Timeline!' : 'Articolo sul Blog!');
    if ($chatId && $messageId) {
        telegramEditMessageText($chatId, $messageId, '✅ ' . $result . "\n\n<a href=\"" . telegramEscapeHtml($url) . "\">Vedi ↗</a>");
    }
}
