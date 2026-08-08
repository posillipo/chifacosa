<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

$slug = $_POST['slug'] ?? $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$message = null;
$isError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Inserisci un indirizzo email valido.';
        $isError = true;
    } else {
        $stmt = getDB()->prepare('SELECT id, verified, token FROM followers WHERE user_id = ? AND email = ?');
        $stmt->execute([$artist['id'], $email]);
        $existing = $stmt->fetch();

        if ($existing && $existing['verified']) {
            $message = 'Segui già ' . $artist['display_name'] . ' — nessuna nuova email necessaria.';
        } else {
            $token = $existing['token'] ?? bin2hex(random_bytes(32));
            if (!$existing) {
                $stmt = getDB()->prepare('INSERT INTO followers (user_id, email, token) VALUES (?,?,?)');
                $stmt->execute([$artist['id'], $email, $token]);
            }
            $confirmUrl = siteUrl('/follow_confirm.php?token=' . $token);
            notifyFollowConfirmation($email, $artist['display_name'], $token, $confirmUrl);
            $message = 'Controlla la tua email: ti abbiamo inviato un link per confermare l\'iscrizione.';
        }
    }
}

header('Location: /' . $artist['slug'] . '?follow_msg=' . urlencode($message ?? '') . '&follow_err=' . ($isError ? '1' : '0'));
exit;
