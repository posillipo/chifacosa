<?php
/**
 * Metricool social queue: enqueues published content for AI-powered social publishing.
 * A Claude Routine reads the queue hourly, generates copy per network, and
 * schedules posts via Metricool MCP.
 */

function metricoolIsEnabled(int $userId): bool {
    $stmt = getDB()->prepare('SELECT metricool_auto_social, metricool_blog_id FROM profiles WHERE user_id=?');
    $stmt->execute([$userId]);
    $p = $stmt->fetch();
    return $p && !empty($p['metricool_auto_social']) && !empty($p['metricool_blog_id']);
}

/**
 * Builds the `sq` payload for the JSON response after a publish action.
 * Returns an array if Metricool is active and the item should be shared, null otherwise.
 */
function socialQueueBuildSq(int $userId, string $type, array $data, bool $isPublic, ?string $publishAt): ?array {
    if (!$isPublic || $publishAt) {
        return null;
    }
    if (!metricoolIsEnabled($userId)) {
        return null;
    }
    return [
        'suggest' => true,
        'type'    => $type,
        'id'      => (int) ($data['content_id'] ?? 0),
        'title'   => $data['title'] ?? '',
        'img'     => $data['image_url'] ?? null,
        'url'     => $data['public_url'] ?? '',
    ];
}

/**
 * Inserts an item into the social queue. Does NOT check if Metricool is enabled —
 * the caller is responsible for that check (use metricoolIsEnabled() first).
 */
function socialQueueEnqueue(int $userId, string $type, array $data, ?string $scheduledFor = null): int {
    $ins = getDB()->prepare(
        'INSERT INTO social_queue (user_id, content_type, content_id, title, body, image_url, public_url, scheduled_for)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $ins->execute([
        $userId,
        $type,
        (int) ($data['content_id'] ?? 0),
        $data['title'] ?? null,
        $data['body'] ?? null,
        $data['image_url'] ?? null,
        $data['public_url'] ?? '',
        $scheduledFor ?: null,
    ]);
    return (int) getDB()->lastInsertId();
}

/**
 * @deprecated Use socialQueueBuildSq() + the JS modal for user-controlled queueing.
 * Kept for any callers that haven't been updated yet.
 */
function socialQueueEnqueueIfEnabled(int $userId, string $type, array $data): void {
    if (!metricoolIsEnabled($userId)) {
        return;
    }
    socialQueueEnqueue($userId, $type, $data);
}

/**
 * Elenco dinamico dei brand Metricool dell'account (id => etichetta), letto dall'API Metricool.
 * Richiede le variabili d'ambiente METRICOOL_USER_TOKEN e METRICOOL_USER_ID (mai nel codice).
 * Ritorna null se non configurato o se la chiamata fallisce. Cache su file di 10 minuti.
 */
function metricoolListBrands(): ?array {
    $token = getenv('METRICOOL_USER_TOKEN') ?: '';
    $mcUser = getenv('METRICOOL_USER_ID') ?: '';
    if ($token === '' || $mcUser === '') {
        return null;
    }
    $cache = sys_get_temp_dir() . '/metricool_brands_' . md5($mcUser) . '.json';
    if (is_file($cache) && filemtime($cache) > time() - 600) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached)) {
            return array_combine(array_map('intval', array_keys($cached)), array_values($cached));
        }
    }
    $ch = curl_init('https://app.metricool.com/api/admin/simpleProfiles?userId=' . urlencode($mcUser));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['X-Mc-Auth: ' . $token, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = $body !== false && $code === 200 ? json_decode($body, true) : null;
    if (!is_array($data)) {
        return null;
    }
    $brands = [];
    foreach ($data as $b) {
        $id = (int) ($b['id'] ?? $b['blogId'] ?? 0);
        $label = trim((string) ($b['label'] ?? $b['title'] ?? $b['name'] ?? ''));
        if ($id > 0) {
            $brands[$id] = $label !== '' ? $label : ('Brand ' . $id);
        }
    }
    if ($brands) {
        @file_put_contents($cache, json_encode($brands), LOCK_EX);
    }
    return $brands ?: null;
}

