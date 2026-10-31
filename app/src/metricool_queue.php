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

// ===== Registro delle pubblicazioni (social_queue come coda E come storico) =====
// Valori ammessi per i campi del registro. "status" copre anche l'esito: scheduled/failed/skipped
// descrivono cosa è successo a un'uscita già registrata, pending/processing sono la coda di lavoro.
const SOCIAL_REGISTRY_SCOPES = ['announcement', 'reminder', 'result', 'new', 'promo', 'release', 'reprise'];
const SOCIAL_REGISTRY_FORMATS = ['feed', 'story', 'reel'];
const SOCIAL_REGISTRY_STATUSES = ['pending', 'processing', 'scheduled', 'done', 'failed', 'skipped'];
// Stati con cui si può REGISTRARE un'uscita a cose fatte (non è lavoro da eseguire).
const SOCIAL_REGISTRY_FINAL_STATUSES = ['scheduled', 'done', 'failed', 'skipped'];

/**
 * Valida e normalizza i campi del registro presenti nel payload (scope, format, image_story_url,
 * channels, scheduler_ref). Ritorna ['error' => string|null, 'values' => [...]] con SOLO i campi
 * effettivamente forniti: chi non li usa continua a funzionare come prima.
 */
function socialRegistryValidate(array $body): array {
    $v = [];
    if (array_key_exists('scope', $body) && $body['scope'] !== null && $body['scope'] !== '') {
        if (!in_array($body['scope'], SOCIAL_REGISTRY_SCOPES, true)) {
            return ['error' => 'scope deve essere uno tra: ' . implode(', ', SOCIAL_REGISTRY_SCOPES) . '.', 'values' => []];
        }
        $v['scope'] = $body['scope'];
    }
    if (array_key_exists('format', $body) && $body['format'] !== null && $body['format'] !== '') {
        if (!in_array($body['format'], SOCIAL_REGISTRY_FORMATS, true)) {
            return ['error' => 'format deve essere uno tra: ' . implode(', ', SOCIAL_REGISTRY_FORMATS) . '.', 'values' => []];
        }
        $v['format'] = $body['format'];
    }
    if (!empty($body['image_story_url'])) {
        $u = trim((string) $body['image_story_url']);
        if (!preg_match('#^https?://#i', $u) || mb_strlen($u) > 500) {
            return ['error' => 'image_story_url non è un URL valido (max 500 caratteri).', 'values' => []];
        }
        $v['image_story_url'] = $u;
    }
    if (array_key_exists('channels', $body) && $body['channels'] !== null) {
        if (!is_array($body['channels']) || count($body['channels']) > 20) {
            return ['error' => 'channels deve essere un array di stringhe (es. ["FB","IG","Threads"]).', 'values' => []];
        }
        $clean = [];
        foreach ($body['channels'] as $ch) {
            if (!is_string($ch) || trim($ch) === '' || mb_strlen($ch) > 40) {
                return ['error' => 'channels deve contenere solo stringhe non vuote (max 40 caratteri).', 'values' => []];
            }
            $clean[] = trim($ch);
        }
        $v['channels'] = json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE);
    }
    if (!empty($body['scheduler_ref'])) {
        $r = trim((string) $body['scheduler_ref']);
        if (mb_strlen($r) > 120) {
            return ['error' => 'scheduler_ref è troppo lungo (max 120 caratteri).', 'values' => []];
        }
        $v['scheduler_ref'] = $r;
    }
    return ['error' => null, 'values' => $v];
}

/** Riga di social_queue nella forma esposta dalle API del registro. */
function socialRegistrySerialize(array $r): array {
    $channels = null;
    if (isset($r['channels']) && $r['channels'] !== null && $r['channels'] !== '') {
        $dec = json_decode((string) $r['channels'], true);
        $channels = is_array($dec) ? $dec : null;
    }
    return [
        'id'              => (int) $r['id'],
        'source_type'     => $r['content_type'],
        'source_id'       => (int) $r['content_id'],
        'scope'           => $r['scope'] ?? null,
        'format'          => $r['format'] ?? null,
        'title'           => $r['title'] ?? null,
        'public_url'      => $r['public_url'] ?? null,
        'image_url'       => $r['image_url'] ?? null,
        'image_story_url' => $r['image_story_url'] ?? null,
        'video_url'       => $r['media_url'] ?? null,
        'channels'        => $channels,
        'scheduler_ref'   => $r['scheduler_ref'] ?? null,
        'outcome'         => $r['status'],
        'publication_date' => !empty($r['scheduled_for']) ? apiFormatDateTimeRome($r['scheduled_for']) : null,
        'error_msg'       => $r['error_msg'] ?? null,
        'created_at'      => apiFormatDateTimeRome($r['created_at'] ?? null),
        'processed_at'    => !empty($r['processed_at']) ? apiFormatDateTimeRome($r['processed_at']) : null,
    ];
}

/**
 * Inserts an item into the social queue. Does NOT check if Metricool is enabled —
 * the caller is responsible for that check (use metricoolIsEnabled() first).
 */
function socialQueueEnqueue(int $userId, string $type, array $data, ?string $scheduledFor = null, array $registry = [], string $status = 'pending'): int {
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
    // Articolo con video di copertina: la coda porta il file (media_url) e l'anteprima come immagine.
    if ($type === 'blog' && empty($data['media_url']) && !empty($data['content_id'])) {
        try {
            $bs = getDB()->prepare('SELECT cover_video_path, cover_path FROM blog_posts WHERE id = ? AND user_id = ?');
            $bs->execute([(int) $data['content_id'], $userId]);
            if (($b = $bs->fetch()) && !empty($b['cover_video_path'])) {
                $data['media_url'] = siteUrl('/' . $b['cover_video_path']);
                if (empty($data['image_url']) && !empty($b['cover_path'])) {
                    $data['image_url'] = siteUrl('/' . $b['cover_path']);
                }
            }
        } catch (Throwable $e) {
            error_log('socialQueueEnqueue(blog): ' . $e->getMessage());
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
    // Campi del registro (colonne create dalla migrazione 2026_10_31): aggiunti all'INSERT solo se
    // forniti, così la coda funziona come prima anche prima che la migrazione sia stata applicata.
    foreach (['scope', 'format', 'image_story_url', 'channels', 'scheduler_ref'] as $k) {
        if (isset($registry[$k])) {
            $cols[] = ($k === 'format') ? '`format`' : $k;
            $vals[] = $registry[$k];
        }
    }
    // Registrazione a cose fatte (scheduled/done/failed/skipped): non è lavoro da eseguire, quindi
    // lo stato è quello finale e processed_at è subito valorizzato. Mai 'pending' per i registri.
    if ($status !== 'pending') {
        $cols[] = 'status';
        $vals[] = $status;
        $cols[] = 'processed_at';
        $vals[] = date('Y-m-d H:i:s');
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
