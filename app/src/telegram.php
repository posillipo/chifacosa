<?php
require_once __DIR__ . '/spotify.php'; // riusa httpRequest()

/**
 * Client minimale per Telegram Bot API. Stesso approccio di YouTube/Spotify:
 * nessuna dipendenza esterna, solo httpRequest() con file_get_contents.
 *
 * Serve un Bot Token (creato tramite @BotFather su Telegram). Ogni profilo
 * collega il proprio canale/gruppo indicando il chat_id numerico o l'@username.
 */

function getTelegramBotToken(): ?string {
    $token = getSiteSetting('telegram_bot_token');
    return ($token !== '' && $token !== null) ? $token : null;
}

function telegramApiCall(string $method, array $params = []): ?array {
    $token = getTelegramBotToken();
    if (!$token) {
        return null;
    }
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $body = json_encode($params);
    $headers = ['Content-Type: application/json'];
    $response = httpRequest('POST', $url, $headers, $body);
    if (!$response) {
        return null;
    }
    $data = json_decode($response, true);
    if (empty($data['ok'])) {
        $desc = $data['description'] ?? 'Errore sconosciuto';
        error_log("[Telegram] API {$method} fallita: {$desc}");
        return null;
    }
    return $data['result'] ?? [];
}

function telegramGetMe(): ?array {
    return telegramApiCall('getMe');
}

/**
 * Risolve un input (chat_id numerico, @username di canale, link t.me/...)
 * e verifica che il bot abbia accesso al canale restituendo le info.
 */
function telegramResolveChat(string $input): ?array {
    $input = trim($input);

    // t.me/nomecanale → @nomecanale
    if (preg_match('#(?:t\.me|telegram\.me)/([a-zA-Z][a-zA-Z0-9_]{3,})$#', $input, $m)) {
        $input = '@' . $m[1];
    }
    // Se è un numero (con eventuale -100 prefix per supergroup/canali), usalo direttamente
    if (preg_match('/^-?\d+$/', $input)) {
        $chatId = $input;
    } elseif (preg_match('/^@?([a-zA-Z][a-zA-Z0-9_]{3,})$/', $input, $m)) {
        $chatId = '@' . $m[1];
    } else {
        return null;
    }

    return telegramApiCall('getChat', ['chat_id' => $chatId]);
}

function telegramSendMessage(string $chatId, string $text, ?string $parseMode = 'HTML', bool $disablePreview = false): ?array {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
    ];
    if ($parseMode) {
        $params['parse_mode'] = $parseMode;
    }
    if ($disablePreview) {
        $params['disable_web_page_preview'] = true;
    }
    return telegramApiCall('sendMessage', $params);
}

function telegramSendPhoto(string $chatId, string $photoUrl, ?string $caption = null): ?array {
    $params = [
        'chat_id' => $chatId,
        'photo' => $photoUrl,
    ];
    if ($caption) {
        $params['caption'] = $caption;
        $params['parse_mode'] = 'HTML';
    }
    return telegramApiCall('sendPhoto', $params);
}

/**
 * Pubblica un contenuto (timeline post o blog) su un canale Telegram.
 * Costruisce un messaggio formattato con link alla pagina pubblica.
 */
function telegramPublishContent(array $profile, array $content): ?array {
    $chatId = $profile['telegram_chat_id'] ?? null;
    if (!$chatId || !getTelegramBotToken()) {
        return null;
    }

    $siteUrl = rtrim(siteUrl(), '/');
    $slug = $profile['slug'];
    $type = $content['type'] ?? 'post';

    $title = $content['title'] ?? '';
    $body = $content['body'] ?? '';
    $imageUrl = $content['image_url'] ?? null;
    $publicUrl = $content['public_url'] ?? null;

    // Costruisci il testo del messaggio
    $lines = [];
    if ($title) {
        $lines[] = '<b>' . telegramEscapeHtml($title) . '</b>';
    }
    if ($body) {
        $excerpt = mb_substr(strip_tags($body), 0, 300);
        if (mb_strlen(strip_tags($body)) > 300) {
            $excerpt .= '…';
        }
        $lines[] = telegramEscapeHtml($excerpt);
    }
    if ($publicUrl) {
        $lines[] = '';
        $label = $type === 'blog' ? '📖 Leggi tutto' : '👉 Vedi';
        $lines[] = '<a href="' . telegramEscapeHtml($publicUrl) . '">' . $label . '</a>';
    }

    $text = implode("\n", $lines);

    if ($imageUrl && str_starts_with($imageUrl, '/')) {
        $imageUrl = $siteUrl . $imageUrl;
    }

    if ($imageUrl) {
        return telegramSendPhoto($chatId, $imageUrl, $text);
    }
    return telegramSendMessage($chatId, $text);
}

/**
 * Invia automaticamente un contenuto su Telegram se il profilo ha l'auto-publish attivo.
 * Chiamato dopo la creazione di un post Timeline o articolo Blog (solo se immediatamente visibile).
 */
function telegramAutoPublishIfEnabled(int $userId, string $type, array $contentData): void {
    if (!getTelegramBotToken()) {
        return;
    }
    $stmt = getDB()->prepare('SELECT p.*, u.slug FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    if (!$profile || empty($profile['telegram_chat_id']) || empty($profile['telegram_auto_publish'])) {
        return;
    }
    telegramPublishContent($profile, array_merge(['type' => $type], $contentData));
}

function telegramEscapeHtml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function telegramSetWebhook(string $url, ?string $secretToken = null): ?array {
    $params = ['url' => $url, 'allowed_updates' => ['message', 'callback_query']];
    if ($secretToken) {
        $params['secret_token'] = $secretToken;
    }
    return telegramApiCall('setWebhook', $params);
}

function telegramDeleteWebhook(): ?array {
    return telegramApiCall('deleteWebhook');
}

function telegramGetWebhookInfo(): ?array {
    return telegramApiCall('getWebhookInfo');
}

function telegramSendMessageWithKeyboard(string $chatId, string $text, array $inlineKeyboard): ?array {
    return telegramApiCall('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => ['inline_keyboard' => $inlineKeyboard],
    ]);
}

function telegramAnswerCallbackQuery(string $callbackQueryId, ?string $text = null): ?array {
    $params = ['callback_query_id' => $callbackQueryId];
    if ($text) {
        $params['text'] = $text;
    }
    return telegramApiCall('answerCallbackQuery', $params);
}

function telegramEditMessageText(string $chatId, int $messageId, string $text): ?array {
    return telegramApiCall('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ]);
}

function telegramGetFile(string $fileId): ?array {
    return telegramApiCall('getFile', ['file_id' => $fileId]);
}

function telegramDownloadFile(string $filePath): ?string {
    $token = getTelegramBotToken();
    if (!$token) return null;
    $url = 'https://api.telegram.org/file/bot' . $token . '/' . $filePath;
    $data = @file_get_contents($url);
    return $data !== false ? $data : null;
}

function telegramGenerateLinkCode(int $profileUserId): string {
    $code = strtoupper(bin2hex(random_bytes(4)));
    $stmt = getDB()->prepare('UPDATE profiles SET telegram_link_code=?, telegram_link_expires=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE user_id=?');
    $stmt->execute([$code, $profileUserId]);
    return $code;
}

function telegramFindProfileByLinkCode(string $code): ?array {
    $stmt = getDB()->prepare('SELECT p.*, u.slug FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.telegram_link_code=? AND p.telegram_link_expires > NOW()');
    $stmt->execute([$code]);
    $profile = $stmt->fetch();
    if (!$profile) return null;
    // Codice monouso
    getDB()->prepare('UPDATE profiles SET telegram_link_code=NULL, telegram_link_expires=NULL WHERE user_id=?')->execute([$profile['user_id']]);
    return $profile;
}

function telegramFindProfileByTelegramUserId(int $telegramUserId): ?array {
    $stmt = getDB()->prepare('SELECT p.*, u.slug FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.telegram_user_id=?');
    $stmt->execute([$telegramUserId]);
    return $stmt->fetch() ?: null;
}

function telegramSaveIncomingPhoto(string $fileId, string $slug): ?string {
    $file = telegramGetFile($fileId);
    if (!$file || empty($file['file_path'])) return null;
    $data = telegramDownloadFile($file['file_path']);
    if (!$data) return null;
    $jpeg = compressImageToJpeg($data);
    if (!$jpeg) return null;
    $fname = 'tg_' . bin2hex(random_bytes(6)) . '.jpg';
    $dir = '/var/www/html/uploads/images/' . $slug;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (file_put_contents($dir . '/' . $fname, $jpeg) === false) return null;
    return 'uploads/images/' . $slug . '/' . $fname;
}
