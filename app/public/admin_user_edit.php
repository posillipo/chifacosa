<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'users';
$pageTitle = 'Modifica utente';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$error = null;
$success = null;

$stmt = getDB()->prepare('SELECT u.*, p.display_name FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
$stmt->execute([$id]);
$u = $stmt->fetch();

if (!$u) {
    http_response_code(404);
    exit('Utente non trovato.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $displayName = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $slug = slugify($_POST['slug'] ?? '');

    if ($displayName === '' || $email === '' || $slug === '') {
        $error = 'Tutti i campi sono obbligatori.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email non valida.';
    } elseif (in_array($slug, RESERVED_SLUGS, true)) {
        $error = 'Questo nome pagina non è disponibile.';
    } else {
        // Verifica unicità email/slug escludendo l'utente stesso
        $chk = getDB()->prepare('SELECT id FROM users WHERE (email = ? OR slug = ?) AND id != ?');
        $chk->execute([$email, $slug, $id]);
        if ($chk->fetch()) {
            $error = 'Email o nome pagina già in uso da un altro utente.';
        } else {
            getDB()->prepare('UPDATE users SET email = ?, slug = ? WHERE id = ?')->execute([$email, $slug, $id]);
            getDB()->prepare('UPDATE profiles SET display_name = ? WHERE user_id = ?')->execute([$displayName, $id]);
            $success = 'Dati aggiornati.';
            $u['email'] = $email;
            $u['slug'] = $slug;
            $u['display_name'] = $displayName;
        }
    }
}

include __DIR__ . '/_admin_header.php';
?>
  <p><a href="/admin_users.php">← Tutti gli utenti</a></p>

  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">

    <label>Nome d'arte / Band</label>
    <input type="text" name="display_name" value="<?= e($u['display_name']) ?>" required>

    <label>Email</label>
    <input type="email" name="email" value="<?= e($u['email']) ?>" required>

    <label>Nome pagina (<?= e(siteName()) ?>/slug)</label>
    <input type="text" name="slug" value="<?= e($u['slug']) ?>" required>

    <button type="submit" class="btn">Salva modifiche</button>
  </form>
<?php include __DIR__ . '/_admin_footer.php'; ?>
