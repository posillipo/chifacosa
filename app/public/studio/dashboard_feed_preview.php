<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
<?php
// Testo di anteprima per il popup del Feed (dashboard). GET ?t=tipo&id=N → {text}. Solo contenuti pubblici e già pubblicati.
session_start();
require_once __DIR__ . '/../../src/functions.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (!currentUser()) {
    http_response_code(401);
    exit(json_encode(['text' => '']));
}
$t = (string) ($_GET['t'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$text = '';
try {
    if ($t === 'blog') {
        $st = getDB()->prepare('SELECT excerpt, content FROM blog_posts b JOIN users u ON u.id = b.user_id WHERE b.id = ? AND b.published_at <= NOW() AND u.is_active = 1');
        $st->execute([$id]);
        if ($r = $st->fetch()) {
            $text = trim((string) ($r['excerpt'] ?? '')) !== '' ? (string) $r['excerpt'] : (string) $r['content'];
        }
    } elseif ($t === 'pensiero') {
        $st = getDB()->prepare("SELECT testo FROM timeline_posts p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND p.visibility = 'public' AND (p.publish_at IS NULL OR p.publish_at <= NOW()) AND u.is_active = 1");
        $st->execute([$id]);
        $text = (string) ($st->fetchColumn() ?: '');
    }
} catch (Throwable $e) {
    $text = '';
}
$text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')));
echo json_encode(['text' => mb_strimwidth($text, 0, 420, '…')], JSON_UNESCAPED_UNICODE);
