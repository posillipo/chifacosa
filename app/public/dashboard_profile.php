<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'profile';
$pageTitle = 'Profilo';
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $displayName = trim($_POST['display_name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $themeColor = trim($_POST['theme_color'] ?? '#6C5CE7');
    $genere = trim($_POST['genere'] ?? '');
    $citta = trim($_POST['citta'] ?? '');
    $provincia = trim($_POST['provincia'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $avatarPath = $profile['avatar_path'];

    if (!empty($_FILES['avatar']['name'])) {
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'], true) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $fname = 'avatar_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dir = __DIR__ . '/uploads/images/' . $profile['slug'];
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $dest = $dir . '/' . $fname;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                $avatarPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
            }
        } else {
            $error = 'Formato immagine non valido (usa jpg, png o webp).';
        }
    }

    if (!$error) {
        $stmt = getDB()->prepare('UPDATE profiles SET display_name=?, bio=?, avatar_path=?, theme_color=?, genere=?, citta=?, provincia=?, telefono=? WHERE user_id=?');
        $stmt->execute([$displayName, $bio, $avatarPath, $themeColor, $genere ?: null, $citta ?: null, $provincia ?: null, $telefono ?: null, $profile['id']]);
        $success = 'Profilo aggiornato.';
        $user = currentUser();
        $profile = getActingProfile($user);
    }
}

include __DIR__ . '/_dash_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <label>Nome d'arte / Band</label>
    <input type="text" name="display_name" value="<?= e($profile['display_name']) ?>" required>

    <label>Bio</label>
    <textarea name="bio" rows="4"><?= e($profile['bio']) ?></textarea>

    <label>Genere musicale</label>
    <input type="text" name="genere" value="<?= e($profile['genere'] ?? '') ?>" placeholder="es. Rock, Pop, Cantautore...">

    <label>Città</label>
    <input type="text" name="citta" value="<?= e($profile['citta'] ?? '') ?>">

    <label>Provincia</label>
    <input type="text" name="provincia" value="<?= e($profile['provincia'] ?? '') ?>" placeholder="es. Na, Mi, Rm...">

    <label>Telefono</label>
    <input type="text" name="telefono" value="<?= e($profile['telefono'] ?? '') ?>">

    <label>Colore tema (pagina pubblica)</label>
    <input type="color" name="theme_color" value="<?= e($profile['theme_color'] ?? '#6C5CE7') ?>" style="width:80px;height:44px;padding:4px;">

    <label>Foto profilo</label>
    <?php if ($profile['avatar_path']): ?>
      <img src="/<?= e($profile['avatar_path']) ?>" style="width:70px;height:70px;border-radius:50%;object-fit:cover;margin-bottom:10px;">
    <?php endif; ?>
    <input type="file" name="avatar" accept="image/*">

    <button type="submit" class="btn">Salva profilo</button>
  </form>

  <div class="card">
    <strong>Il tuo link pubblico:</strong><br>
    <a href="/<?= e($profile['slug']) ?>" target="_blank"><?= e(siteName()) ?>/<?= e($profile['slug']) ?></a>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
