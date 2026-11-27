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
    if (!$isPublic) {
        return null;
    }
    if (!metricoolIsEnabled($userId)) {
        return null;
    }
    if ($publishAt) {
        // Con una data di pubblicazione: se è nel futuro l'uscita si accoda subito con quella data (come
        // per articoli ed eventi); se è già passata il contenuto è già pubblico e non si propone nulla.
        if (strtotime($publishAt) > time()) {
            socialQueueEnqueueScheduledOnce($userId, $type, $data, $publishAt);
        }
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
 * Accoda un'uscita con data ma senza duplicati: se lo stesso contenuto è già in coda (in attesa, in
 * elaborazione o sospeso) ne aggiorna solo la data; se è già stato programmato o pubblicato per la
 * stessa data non fa nulla. Pensata per i moduli che salvano più volte lo stesso elemento.
 */
function socialQueueEnqueueScheduledOnce(int $userId, string $type, array $data, string $scheduledFor): void {
    $contentId = (int) ($data['content_id'] ?? 0);
    if ($contentId > 0) {
        $q = getDB()->prepare("SELECT id, status, scheduled_for FROM social_queue WHERE user_id=? AND content_type=? AND content_id=? ORDER BY id DESC");
        $q->execute([$userId, $type, $contentId]);
        foreach ($q->fetchAll() as $row) {
            if (in_array($row['status'], ['pending', 'processing'], true)) {
                getDB()->prepare('UPDATE social_queue SET scheduled_for=? WHERE id=?')->execute([$scheduledFor, $row['id']]);
                return;
            }
            if (in_array($row['status'], ['scheduled', 'done'], true) && !empty($row['scheduled_for']) && strtotime($row['scheduled_for']) === strtotime($scheduledFor)) {
                return;
            }
        }
    }
    socialQueueEnqueue($userId, $type, $data, $scheduledFor);
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

/** Numero massimo di immagini che una uscita social può portare (carosello/galleria). */
const SOCIAL_QUEUE_MAX_IMAGES = 10;

/**
 * Normalizza un elenco di immagini: solo URL http(s) o percorsi del sito (resi assoluti), senza
 * duplicati, nell'ordine dato, al massimo SOCIAL_QUEUE_MAX_IMAGES.
 */
function socialQueueCleanImageUrls(array $urls): array {
    $out = [];
    foreach ($urls as $u) {
        if (!is_string($u)) {
            continue;
        }
        $u = trim($u);
        if ($u === '' || strlen($u) > 500) {
            continue;
        }
        if ($u[0] === '/' && !str_starts_with($u, '//')) {
            $u = siteUrl($u);
        } elseif (!preg_match('#^https?://#i', $u)) {
            continue;
        }
        if (!in_array($u, $out, true)) {
            $out[] = $u;
        }
        if (count($out) >= SOCIAL_QUEUE_MAX_IMAGES) {
            break;
        }
    }
    return $out;
}

/** True se la tabella ha la colonna image_urls (migrazione 2026_11_35): senza, la coda funziona come prima. */
function socialQueueHasImageUrlsColumn(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) getDB()->query("SHOW COLUMNS FROM social_queue LIKE 'image_urls'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Foto di un post Timeline (la principale + le altre, nell'ordine), come URL assoluti, al massimo 10. */
function socialQueueTimelineImageUrls(int $userId, int $postId): array {
    try {
        $st = getDB()->prepare('SELECT image_path FROM timeline_posts WHERE id = ? AND user_id = ?');
        $st->execute([$postId, $userId]);
        $paths = [];
        if ($main = $st->fetchColumn()) {
            $paths[] = $main;
        }
        foreach (getTimelinePostPhotos($postId) as $extra) {
            $paths[] = $extra;
        }
        return socialQueueCleanImageUrls(array_map(fn($p) => '/' . ltrim((string) $p, '/'), $paths));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Elenco delle immagini di una riga della coda (sempre una lista, anche con una sola immagine):
 * quello salvato esplicitamente, altrimenti — per i post Timeline — le foto lette dal post al
 * momento (così riflette aggiunte e rimozioni fatte dopo l'accodamento), altrimenti image_url.
 */
function socialQueueImageUrls(array $row): array {
    if (!empty($row['image_urls'])) {
        $dec = json_decode((string) $row['image_urls'], true);
        if (is_array($dec) && $dec) {
            return socialQueueCleanImageUrls($dec);
        }
    }
    if (($row['content_type'] ?? '') === 'timeline' && (int) ($row['content_id'] ?? 0) > 0) {
        $live = socialQueueTimelineImageUrls((int) $row['user_id'], (int) $row['content_id']);
        if ($live) {
            return $live;
        }
    }
    return !empty($row['image_url']) ? [(string) $row['image_url']] : [];
}

/** Aggiunge 'image_urls' (lista, max 10) a ogni riga della coda. */
function socialQueueAttachImageUrls(array $rows): array {
    foreach ($rows as &$r) {
        $r['image_urls'] = socialQueueImageUrls($r);
    }
    unset($r);
    return $rows;
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
        'image_urls'      => socialQueueImageUrls($r),
        'image_story_url' => $r['image_story_url'] ?? null,
        'video_url'       => $r['media_url'] ?? null,
        'channels'        => $channels,
        'scheduler_ref'   => $r['scheduler_ref'] ?? null,
        'outcome'         => $r['status'],
        'author'          => $r['created_by'] ?? null,        // chi ha accodato l'uscita ("Accodato da")
        'source_author'   => $r['source_author'] ?? null,     // chi ha scritto il contenuto di origine ("Scritto da")
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
    // Più immagini (fino a 10): la prima resta anche in image_url, così chi legge solo quel campo continua a funzionare.
    $imageList = isset($data['image_urls']) && is_array($data['image_urls']) ? socialQueueCleanImageUrls($data['image_urls']) : [];
    if ($imageList && empty($data['image_url'])) {
        $data['image_url'] = $imageList[0];
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
    // L'elenco si salva solo con più di un'immagine e se la colonna esiste (migrazione 2026_11_35).
    if (count($imageList) > 1 && socialQueueHasImageUrlsColumn()) {
        $cols[] = 'image_urls';
        $vals[] = json_encode($imageList, JSON_UNESCAPED_SLASHES);
    }
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
function socialQueueEnqueueIfEnabled(int $userId, string $type, array $data, ?string $scheduledFor = null): void {
    if (!metricoolIsEnabled($userId)) {
        return;
    }
    // $scheduledFor: contenuto con pubblicazione programmata sul sito. L'uscita social si accoda subito con
    // quella data (scheduled_for): la routine la programma su Metricool alla stessa data/ora, quando il
    // contenuto diventa pubblico. Senza data (NULL) l'uscita è immediata, come sempre.
    socialQueueEnqueue($userId, $type, $data, $scheduledFor);
}

/**
 * Modifica di un aggiornamento Timeline: se è pubblico, la Coda Social è attiva e c'è una data «Pubblica sui social dal»,
 * l'uscita si accoda (o, se è già in coda, ne cambia la data) senza duplicati. Senza data non fa nulla: l'uscita
 * immediata è quella decisa alla creazione, e a ogni salvataggio non deve ripartire una nuova pubblicazione.
 */
function timelineEnqueueOnEdit(array $profile, int $postId, string $testo, string $visibility, ?string $publishAt): void {
    if ($visibility !== 'public' || !$publishAt) {
        return;
    }
    $uid = (int) $profile['id'];
    if (!metricoolIsEnabled($uid)) {
        return;
    }
    try {
        $st = getDB()->prepare('SELECT image_path FROM timeline_posts WHERE id = ? AND user_id = ?');
        $st->execute([$postId, $uid]);
        $main = (string) ($st->fetchColumn() ?: '');
        $st = getDB()->prepare('SELECT image_path FROM timeline_post_photos WHERE post_id = ? ORDER BY sort_order ASC, id ASC');
        $st->execute([$postId]);
        $imgs = [];
        if ($main !== '') { $imgs[] = siteUrl('/' . ltrim($main, '/')); }
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pth) { $imgs[] = siteUrl('/' . ltrim((string) $pth, '/')); }
        $data = [
            'content_id' => $postId,
            'body'       => $testo,
            'image_url'  => $imgs[0] ?? null,
            'public_url' => siteUrl('/' . $profile['slug'] . '/timeline'),
        ];
        if (count($imgs) > 1) { $data['image_urls'] = array_slice($imgs, 0, 10); }
        socialQueueEnqueueScheduledOnce($uid, 'timeline', $data, $publishAt);
    } catch (Throwable $e) {
        error_log('timelineEnqueueOnEdit: ' . $e->getMessage());
    }
}

/**
 * Aggiunge a ogni riga della coda 'source_author' = firma (created_by) del contenuto di origine,
 * letta in blocco per tipo. Tipi senza firma (offerte, sconosciuti) restano null.
 */
function socialQueueAttachSourceAuthors(array $rows, int $userId): array {
    $tables = ['blog' => 'blog_posts', 'timeline' => 'timeline_posts', 'album' => 'photo_albums', 'service' => 'services', 'event' => 'events', 'video' => 'videos'];
    $ids = [];
    foreach ($rows as $r) {
        if (isset($tables[$r['content_type']]) && (int) $r['content_id'] > 0) {
            $ids[$r['content_type']][] = (int) $r['content_id'];
        }
    }
    $authors = [];
    foreach ($ids as $type => $list) {
        try {
            $list = array_values(array_unique($list));
            $in = implode(',', array_fill(0, count($list), '?'));
            $st = getDB()->prepare('SELECT id, created_by FROM `' . $tables[$type] . "` WHERE user_id = ? AND id IN ($in)");
            $st->execute(array_merge([$userId], $list));
            foreach ($st->fetchAll() as $x) {
                $authors[$type][(int) $x['id']] = $x['created_by'];
            }
        } catch (Throwable $e) {
        }
    }
    foreach ($rows as &$r) {
        $r['source_author'] = $authors[$r['content_type']][(int) $r['content_id']] ?? null;
    }
    unset($r);
    return $rows;
}
