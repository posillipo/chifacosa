<?php
/**
 * Session-authenticated endpoint for manual social queue actions.
 * Called from the dashboard JS (share modal, queue management page).
 *
 * POST body (JSON): { action, ... }
 *
 * action=enqueue  → { type, content_id, title, img, url, scheduled_for? }
 * action=suspend  → { id }          toggle suspended flag
 * action=delete   → { id }
 * action=reschedule → { id, scheduled_for }  (null clears it)
 */
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Content-Type: application/json; charset=UTF-8');

$user = currentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non autenticato']);
    exit;
}
$profile = getActingProfile($user);

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['action'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Payload mancante']);
    exit;
}

$action = $body['action'];

// ── enqueue ─────────────────────────────────────────────────────────────────
if ($action === 'enqueue') {
    require_once __DIR__ . '/../src/metricool_queue.php';
    if (!metricoolIsEnabled((int) $profile['id'])) {
        echo json_encode(['ok' => false, 'error' => 'Metricool non configurato per questo profilo']);
        exit;
    }
    $type        = $body['type'] ?? '';
    $contentId   = (int) ($body['content_id'] ?? 0);
    $title       = mb_substr(trim($body['title'] ?? ''), 0, 250);
    $imgUrl      = $body['img'] ?? null;
    $pubUrl      = $body['url'] ?? '';
    $scheduledFor = $body['scheduled_for'] ?? null;

    if (!$type || !$contentId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'type e content_id obbligatori']);
        exit;
    }
    // Validate/parse scheduled_for
    if ($scheduledFor) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduledFor) ?: DateTime::createFromFormat('Y-m-d H:i', $scheduledFor);
        $scheduledFor = $dt ? $dt->format('Y-m-d H:i:s') : null;
    }
    // Avoid duplicate: if same content is already pending/processing, skip
    $dup = getDB()->prepare(
        'SELECT id FROM social_queue WHERE user_id=? AND content_type=? AND content_id=? AND status IN ("pending","processing","suspended") LIMIT 1'
    );
    $dup->execute([$profile['id'], $type, $contentId]);
    if ($existing = $dup->fetch()) {
        // Update scheduled_for if changed
        if ($scheduledFor) {
            getDB()->prepare('UPDATE social_queue SET scheduled_for=? WHERE id=?')->execute([$scheduledFor, $existing['id']]);
        }
        echo json_encode(['ok' => true, 'id' => $existing['id'], 'updated' => true]);
        exit;
    }
    $id = socialQueueEnqueue((int) $profile['id'], $type, [
        'content_id' => $contentId,
        'title'      => $title,
        'image_url'  => $imgUrl,
        'public_url' => $pubUrl,
    ], $scheduledFor);
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── suspend / unsuspend ──────────────────────────────────────────────────────
if ($action === 'suspend') {
    $id = (int) ($body['id'] ?? 0);
    $stmt = getDB()->prepare(
        'UPDATE social_queue SET suspended = 1 - suspended WHERE id=? AND user_id=? AND status IN ("pending","processing","suspended")'
    );
    $stmt->execute([$id, $profile['id']]);
    // Read back new state
    $row = getDB()->prepare('SELECT suspended, status FROM social_queue WHERE id=? AND user_id=?');
    $row->execute([$id, $profile['id']]);
    $updated = $row->fetch();
    // Sync status column: suspended=1 → status='suspended', suspended=0 → status='pending'
    if ($updated) {
        $newStatus = $updated['suspended'] ? 'suspended' : 'pending';
        getDB()->prepare('UPDATE social_queue SET status=? WHERE id=? AND user_id=? AND status != "done" AND status != "failed"')
               ->execute([$newStatus, $id, $profile['id']]);
        echo json_encode(['ok' => true, 'suspended' => (bool) $updated['suspended'], 'status' => $newStatus]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Elemento non trovato o già completato']);
    }
    exit;
}

// ── reschedule ───────────────────────────────────────────────────────────────
if ($action === 'reschedule') {
    $id = (int) ($body['id'] ?? 0);
    $scheduledFor = $body['scheduled_for'] ?? null;
    if ($scheduledFor) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduledFor) ?: DateTime::createFromFormat('Y-m-d H:i', $scheduledFor);
        $scheduledFor = $dt ? $dt->format('Y-m-d H:i:s') : null;
    }
    $stmt = getDB()->prepare(
        'UPDATE social_queue SET scheduled_for=? WHERE id=? AND user_id=? AND status IN ("pending","suspended")'
    );
    $stmt->execute([$scheduledFor, $id, $profile['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── delete ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int) ($body['id'] ?? 0);
    $stmt = getDB()->prepare('DELETE FROM social_queue WHERE id=? AND user_id=?');
    $stmt->execute([$id, $profile['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Azione non riconosciuta']);
