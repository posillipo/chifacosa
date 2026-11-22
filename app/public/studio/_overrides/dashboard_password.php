<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
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
        // Un eventuale cookie "ricordami" rubato prima di questo cambio password non deve
        // sopravvivergli — vedi revokeAllRememberTokensForUser(). Questa sessione (quella con
        // cui si sta facendo la modifica) resta comunque attiva: solo i cookie "ricordami"
        // vengono revocati, non la sessione corrente.
        revokeAllRememberTokensForUser((int) $user['id']);
        $success = 'Password aggiornata correttamente.';
    }
}

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx tlx-narrow">
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <form method="post" class="box">
      <?= csrfField() ?>
      <h3><i class="fa-solid fa-key"></i> Cambia la tua password</h3>
      <p>Ti serve la password attuale per confermare la modifica.</p>
      <label for="pw-cur">Password attuale</label>
      <input type="password" name="current_password" id="pw-cur" required autocomplete="current-password">
      <label for="pw-new">Nuova password <small>(almeno 8 caratteri)</small></label>
      <input type="password" name="new_password" id="pw-new" required minlength="8" autocomplete="new-password">
      <label for="pw-cnf">Conferma la nuova password</label>
      <input type="password" name="confirm_password" id="pw-cnf" required minlength="8" autocomplete="new-password">
      <button type="submit" class="tlx-submit cmp-done">Aggiorna password</button>
    </form>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
