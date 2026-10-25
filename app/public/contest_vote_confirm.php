<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/contest.php';

$res = contestConfirmVote((string) ($_GET['t'] ?? ''));
$v = $res['vote'] ?? null;
$messages = [
    'confirmed' => ['ok', 'Voto confermato!', 'Grazie, il tuo voto per «' . ($v['track_name'] ?? '') . '» di ' . ($v['band_name'] ?? '') . ' è stato registrato.'],
    'already' => ['ok', 'Voto già confermato', 'Questo voto era già stato confermato: conta una volta sola.'],
    'expired' => ['error', 'Link scaduto', 'Il link di conferma non è più valido. Torna alla pagina del contest e richiedi un nuovo voto.'],
    'closed' => ['error', 'Votazioni chiuse', 'Le votazioni non sono aperte: il voto non può essere confermato.'],
    'duplicate' => ['error', 'Hai già votato', 'Hai già un voto confermato in questa classifica: ne è ammesso uno solo.'],
    'invalid' => ['error', 'Link non valido', 'Il link di conferma non è valido o è stato sostituito da uno più recente.'],
];
[$kind, $title, $text] = $messages[$res['state']] ?? $messages['invalid'];
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
</head>
<body>
<div class="container" style="max-width:560px;">
  <h1><?= e($title) ?></h1>
  <div class="alert <?= $kind === 'ok' ? 'success' : 'error' ?>"><?= e($text) ?></div>
  <p><a class="btn" href="/contest">Vai alla classifica</a></p>
</div>
</body>
</html>
