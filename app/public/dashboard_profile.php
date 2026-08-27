<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
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
    $customFeedGuid = trim($_POST['custom_feed_guid'] ?? '');
    $avatarPath = $user['avatar_path'];

    if ($customFeedGuid !== '' && !filter_var($customFeedGuid, FILTER_VALIDATE_URL)) {
        $error = 'Il link personalizzato per il feed non è un URL valido.';
    }

    if (!empty($_FILES['avatar']['name'])) {
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'], true) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $fname = 'avatar_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dir = __DIR__ . '/uploads/images/' . $user['slug'];
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $dest = $dir . '/' . $fname;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                $avatarPath = 'uploads/images/' . $user['slug'] . '/' . $fname;
            }
        } else {
            $error = 'Formato immagine non valido (usa jpg, png o webp).';
        }
    }

    if (!$error) {
        $customFeedGuid = $customFeedGuid ?: null;
        // Il timestamp "since" segna da quando vale il link personalizzato: si aggiorna solo
        // quando il valore cambia davvero, così il feed continua ad applicarlo dal momento giusto
        // invece di ripartire da adesso ogni volta che si salva il form senza toccare questo campo.
        $customFeedGuidSince = $user['custom_feed_guid_since'] ?? null;
        if ($customFeedGuid !== ($user['custom_feed_guid'] ?? null)) {
            $customFeedGuidSince = $customFeedGuid ? date('Y-m-d H:i:s') : null;
        }

        $stmt = getDB()->prepare('UPDATE profiles SET display_name=?, bio=?, avatar_path=?, theme_color=?, genere=?, citta=?, provincia=?, telefono=?, custom_feed_guid=?, custom_feed_guid_since=? WHERE user_id=?');
        $stmt->execute([$displayName, $bio, $avatarPath, $themeColor, $genere ?: null, $citta ?: null, $provincia ?: null, $telefono ?: null, $customFeedGuid, $customFeedGuidSince, $user['id']]);
        $success = 'Profilo aggiornato.';
        $user = currentUser();
    }
}

include __DIR__ . '/_dash_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <label>Nome d'arte / Band</label>
    <input type="text" name="display_name" value="<?= e($user['display_name']) ?>" required>

    <label>Bio</label>
    <textarea name="bio" rows="4"><?= e($user['bio']) ?></textarea>

    <label>Genere musicale</label>
    <input type="text" name="genere" value="<?= e($user['genere'] ?? '') ?>" placeholder="es. Rock, Pop, Cantautore...">

    <label>Città</label>
    <input type="text" name="citta" value="<?= e($user['citta'] ?? '') ?>">

    <label>Provincia</label>
    <input type="text" name="provincia" value="<?= e($user['provincia'] ?? '') ?>" placeholder="es. Na, Mi, Rm...">

    <label>Telefono</label>
    <input type="text" name="telefono" value="<?= e($user['telefono'] ?? '') ?>">

    <label>Colore tema (pagina pubblica)</label>
    <input type="color" name="theme_color" value="<?= e($user['theme_color'] ?? '#6C5CE7') ?>" style="width:80px;height:44px;padding:4px;">

    <label>Foto profilo</label>
    <?php if ($user['avatar_path']): ?>
      <img src="/<?= e($user['avatar_path']) ?>" style="width:70px;height:70px;border-radius:50%;object-fit:cover;margin-bottom:10px;">
    <?php endif; ?>
    <input type="file" name="avatar" accept="image/*">

    <label>Link personalizzato per il feed (opzionale)</label>
    <p style="color:var(--text-muted);font-size:13.5px;margin:-6px 0 10px;">
      Se lo compili, i post pubblicati <strong>da questo momento in poi</strong> useranno questo
      URL come identificativo (<code>guid</code>) nel feed RSS della tua Timeline, al posto del
      permalink automatico su <?= e(siteName()) ?> — utile per esempio se un'automazione
      collegata al feed deve aprire un altro indirizzo. Il permalink della pagina resta comunque
      sempre quello standard, solo il feed cambia, e i post già pubblicati non vengono toccati.
      Finché non modifichi o svuoti questo campo, ogni nuovo post userà lo stesso link: se ti
      serve un link diverso per un'altra pubblicazione, torna qui e aggiornalo.
    </p>
    <input type="url" name="custom_feed_guid" value="<?= e($user['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
    <?php if (!empty($user['custom_feed_guid_since'])): ?>
      <p style="color:var(--text-muted);font-size:13px;">
        Attivo dal <?= e(date('d/m/Y H:i', strtotime($user['custom_feed_guid_since']))) ?>.
      </p>
    <?php endif; ?>

    <button type="submit" class="btn">Salva profilo</button>
  </form>

  <div class="card">
    <strong>Il tuo link pubblico:</strong><br>
    <a href="/<?= e($user['slug']) ?>" target="_blank"><?= e(siteName()) ?>/<?= e($user['slug']) ?></a>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
