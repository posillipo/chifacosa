<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();

if (!empty($user['account_type_chosen'])) {
    header('Location: /dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $type = $_POST['type'] ?? '';
    if (in_array($type, ['band', 'fan', 'label'], true)) {
        $stmt = getDB()->prepare('UPDATE users SET account_type = ?, account_type_chosen = 1 WHERE id = ?');
        $stmt->execute([$type, $user['id']]);
        header('Location: /dashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Completa la registrazione — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="navbar">
  <div class="brand"><a href="/"><?= e(siteName()) ?></a></div>
</div>
<div class="container" style="max-width:900px;">
  <h2>Completa la tua registrazione: cosa vuoi gestire?</h2>
  <p style="color:var(--text-muted);margin-bottom:24px;">Puoi cambiarlo più avanti scrivendo all'assistenza, ma scegli quello più adatto a te per iniziare.</p>

  <form method="post">
    <?= csrfField() ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">

      <button type="submit" name="type" value="band" class="account-type-card" style="border-top:6px solid #6C5CE7;">
        <strong>Band / Artista</strong>
        <span>Crea il profilo della tua band o del tuo progetto solista</span>
      </button>

      <button type="submit" name="type" value="fan" class="account-type-card" style="border-top:6px solid #FF9F43;">
        <strong>Fan</strong>
        <span>Crea una tua pagina e segnala le band che ami</span>
      </button>

      <button type="submit" name="type" value="label" class="account-type-card" style="border-top:6px solid #10ac84;">
        <strong>Etichetta Discografica</strong>
        <span>Gestisci il profilo della tua etichetta</span>
      </button>

    </div>
  </form>
</div>
</body>
</html>
