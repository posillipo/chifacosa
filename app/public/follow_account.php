<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $redirect = $_POST['redirect'] ?? '/';

    if ($targetId && $targetId !== (int) $user['id']) {
        if ($action === 'follow') {
            $stmt = getDB()->prepare('INSERT IGNORE INTO account_follows (follower_user_id, followed_user_id) VALUES (?, ?)');
            $stmt->execute([$user['id'], $targetId]);
            if ($stmt->rowCount() > 0) {
                $stmt = getDB()->prepare('SELECT u.email, p.display_name FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
                $stmt->execute([$targetId]);
                $target = $stmt->fetch();
                if ($target) {
                    notifyNewFollower($target['email'], $target['display_name'], $user['slug'], $user['display_name']);
                }
            }
        } elseif ($action === 'unfollow') {
            $stmt = getDB()->prepare('DELETE FROM account_follows WHERE follower_user_id=? AND followed_user_id=?');
            $stmt->execute([$user['id'], $targetId]);
        }
    }
    header('Location: ' . $redirect);
    exit;
}
header('Location: /');
