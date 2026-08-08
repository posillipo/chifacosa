<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$activeTab = 'password';
$pageTitle = 'Cambia password';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password_hash'])) {
        $error = 'La password attuale non è corretta.';
    } elseif (strlen($new) < 8) {
        $error = 'La nuova password deve avere almeno 8 caratteri.';
    } elseif ($new !== $confirm) {
        $error = 'La conferma non coincide con la nuova password.';
    } else {
        $stmt = getDB()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        $success = 'Password aggiornata correttamente.';
    }
}

include __DIR__ . '/_dash_header.php';
?>
  <div class="card">
    <strong>Cambia la tua password</strong>
    <p style="color:var(--text-muted)">Ti servirà la password attuale per confermare la modifica.</p>
  </div>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label>Password attuale</label>
    <input type="password" name="current_password" required autocomplete="current-password">
    <label>Nuova password (min. 8 caratteri)</label>
    <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
    <label>Conferma nuova password</label>
    <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
    <button type="submit" class="btn">Aggiorna password</button>
  </form>
<?php include __DIR__ . '/_dash_footer.php'; ?>
