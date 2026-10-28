<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/contest.php';

$res = contestConfirmVote((string) ($_GET['t'] ?? ''));
$v = $res['vote'] ?? null;
$messages = [
    'confirmed' => ['ok', '✅', 'Voto confermato!', 'Grazie, il tuo voto per «' . ($v['track_name'] ?? '') . '» di ' . ($v['band_name'] ?? '') . ' è stato registrato.'],
    'already' => ['ok', '✅', 'Voto già confermato', 'Questo voto era già stato confermato: conta una volta sola.'],
    'expired' => ['err', '⌛', 'Link scaduto', 'Il link di conferma non è più valido. Torna alla pagina del contest e richiedi un nuovo voto.'],
    'closed' => ['err', '🔒', 'Votazioni chiuse', 'Le votazioni non sono aperte: il voto non può essere confermato.'],
    'duplicate' => ['err', '🗳️', 'Hai già votato', 'Hai già un voto confermato in questa classifica: ne è ammesso uno solo.'],
    'invalid' => ['err', '⚠️', 'Link non valido', 'Il link di conferma non è valido o è stato sostituito da uno più recente.'],
];
[$kind, $icon, $title, $text] = $messages[$res['state']] ?? $messages['invalid'];
$href = !empty($v['contest_slug']) ? '/contest/' . $v['contest_slug'] : '/contest';
contestHead($title, '', true);
?>
  <header class="hero">
    <div class="logo"><?= $icon ?></div>
    <h1><?= e($title) ?></h1>
  </header>
  <div class="msg <?= $kind ?>"><?= e($text) ?></div>
  <a class="btn-link primary" href="<?= e($href) ?>"><span class="ico">🏆</span><span>Vai alla classifica</span><span class="arrow">›</span></a>
<?php contestFoot(); ?>
