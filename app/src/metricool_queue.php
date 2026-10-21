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
    // Per i video la coda deve portare il FILE (media_url): se chi accoda non lo indica (pulsanti
    // della dashboard, enqueue_social_queue senza media_url) lo si ricava dal video stesso.
    if ($type === 'video' && empty($data['media_url']) && !empty($data['content_id'])) {
        try {
            $vs = getDB()->prepare('SELECT v.video_path, v.thumb_path, v.title, v.description FROM videos v WHERE v.id = ? AND v.user_id = ?');
            $vs->execute([(int) $data['content_id'], $userId]);
            if ($v = $vs->fetch()) {
                $data['media_url'] = siteUrl('/' . $v['video_path']);
                if (empty($data['image_url']) && !empty($v['thumb_path'])) {
                    $data['image_url'] = siteUrl('/' . $v['thumb_path']);
                }
                if (empty($data['body'])) {
                    $data['body'] = $v['description'] ?? '';
                }
                if (empty($data['title'])) {
                    $data['title'] = $v['title'];
                }
            }
        } catch (Throwable $e) {
            error_log('socialQueueEnqueue(video): ' . $e->getMessage());
        }
    }
    $cols = ['user_id', 'content_type', 'content_id', 'title', 'body', 'image_url', 'public_url', 'scheduled_for'];
    $vals = [
        $userId,
        $type,
        (int) ($data['content_id'] ?? 0),
        $data['title'] ?? null,
        $data['body'] ?? null,
        $data['image_url'] ?? null,
        $data['public_url'] ?? '',
        $scheduledFor ?: null,
    ];
    // media_url (file video) solo per i contenuti che ce l'hanno: gli altri non dipendono dalla colonna.
    if (!empty($data['media_url'])) {
        $cols[] = 'media_url';
        $vals[] = $data['media_url'];
    }
    $ins = getDB()->prepare(
        'INSERT INTO social_queue (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')'
    );
    $ins->execute($vals);
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
