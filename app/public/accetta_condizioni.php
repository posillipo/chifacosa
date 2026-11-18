<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

// Niente requireLogin(): è proprio quello che manda qui (si finirebbe in un ciclo).
$user = currentUser();
if (!$user) {
    header('Location: /login.php');
    exit;
}
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '/dashboard.php');
if (!isSafeInternalRedirect($next)) {
    $next = '/dashboard.php';
}
if (!termsNeededForUser($user)) {
    header('Location: ' . $next);
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (empty($_POST['accept_terms'])) {
        $error = 'Per continuare devi accettare le Condizioni d\'uso.';
    } else {
        termsRecordAcceptance((int) $user['id']);
        header('Location: ' . $next);
        exit;
    }
}
$privacyUrl = trim((string) (getSiteSetting('privacy_policy_url') ?: ''));
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Condizioni d'uso — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
</head>
<body>
<div class="container" style="max-width:760px;">
  <h1>Condizioni d'uso</h1>
  <p>Per continuare a usare <?= e(siteName()) ?> leggi e accetta le Condizioni d'uso.</p>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <div class="card" style="max-height:340px;overflow:auto;"><?= nl2br(e(termsContent())) ?></div>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label style="display:flex;align-items:flex-start;gap:8px;font-weight:normal;">
      <input type="checkbox" name="accept_terms" value="1" required style="width:auto;margin-top:3px;">
      <span>Ho letto e accetto le Condizioni d'uso<?php if ($privacyUrl !== ''): ?> e ho letto l'<a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener">Informativa privacy</a><?php endif; ?>.</span>
    </label>
    <button type="submit" class="btn">Accetto e continuo</button>
    <a href="/logout.php" class="btn secondary" style="margin-left:8px;">Esci</a>
  </form>
  <p style="font-size:13px;color:#666;margin-top:18px;">Se non vuoi accettare puoi uscire, oppure <a href="/dashboard_delete_account.php">eliminare il tuo account</a>.</p>
</div>
</body>
</html>
