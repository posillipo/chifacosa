<?php
// Verifica al volo se uno username è libero (form di benvenuto). GET ?u=testo → {ok, slug, message}.
session_start();
require_once __DIR__ . '/../src/functions.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = currentUser();
if (!$user) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'slug' => '', 'message' => 'Accedi per continuare.']));
}
$slug = usernameNormalize((string) ($_GET['u'] ?? ''));
$err = usernameValidationError($slug, (string) $user['slug']);
echo json_encode(['ok' => $err === null, 'slug' => $slug, 'message' => $err ?? 'Username disponibile'], JSON_UNESCAPED_UNICODE);
