<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

// La registrazione non è più su invito: questa pagina esiste solo per non rompere vecchi link
// già condivisi (bio social, inviti mandati in passato) — rimanda dritto alla registrazione
// aperta, passando eventualmente il riferimento di chi ha invitato (?ref=slug).
$refSlug = trim($_GET['ref'] ?? '');
$location = '/register.php' . ($refSlug !== '' ? ('?ref=' . urlencode($refSlug)) : '');
header('Location: ' . $location);
exit;
