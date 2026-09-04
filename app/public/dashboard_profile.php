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

    // Il ritaglio avviene nel browser (canvas): arriva già pronto come immagine JPEG in
    // base64 in questo campo nascosto, prodotto da cropper.js quando l'utente conferma il
    // ritaglio. Se per qualunque motivo il JavaScript non parte (script bloccato, browser molto
    // vecchio...), il campo resta vuoto e si ricade sul caricamento diretto del file come prima,
    // senza ritaglio ma comunque funzionante. In entrambi i casi il risultato finale passa
    // sempre da compressImageToJpeg(), che garantisce JPEG e non oltre 250KB indipendentemente
    // da cosa arriva dal browser.
    $croppedData = $_POST['avatar_cropped_data'] ?? '';
    if ($croppedData !== '' && preg_match('#^data:image/(jpeg|png);base64,#', $croppedData, $m)) {
        $raw = base64_decode(substr($croppedData, strpos($croppedData, ',') + 1), true);
        $jpeg = ($raw !== false && strlen($raw) > 0 && strlen($raw) < 8 * 1024 * 1024) ? compressImageToJpeg($raw, 250 * 1024, 800) : null;
        if ($jpeg !== null) {
            $fname = 'avatar_' . bin2hex(random_bytes(6)) . '.jpg';
            $dir = __DIR__ . '/uploads/images/' . $profile['slug'];
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $dest = $dir . '/' . $fname;
            if (file_put_contents($dest, $jpeg) !== false) {
                $avatarPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
            }
        } else {
            $error = 'Il ritaglio non è riuscito, riprova.';
        }
    } elseif (!empty($_FILES['avatar']['name'])) {
        $raw = $_FILES['avatar']['error'] === UPLOAD_ERR_OK ? file_get_contents($_FILES['avatar']['tmp_name']) : false;
        $jpeg = $raw !== false ? compressImageToJpeg($raw, 250 * 1024, 800) : null;
        if ($jpeg !== null) {
            $fname = 'avatar_' . bin2hex(random_bytes(6)) . '.jpg';
            $dir = __DIR__ . '/uploads/images/' . $profile['slug'];
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $dest = $dir . '/' . $fname;
            if (file_put_contents($dest, $jpeg) !== false) {
                $avatarPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
            }
        } else {
            $error = 'Formato immagine non valido.';
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
    <label>Nome / Nome d'arte</label>
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
    <img id="avatar-preview" src="<?= $profile['avatar_path'] ? e('/' . $profile['avatar_path']) : '' ?>"
         style="width:70px;height:70px;border-radius:50%;object-fit:cover;margin-bottom:10px;<?= $profile['avatar_path'] ? '' : 'display:none;' ?>">
    <input type="file" id="avatar-input" name="avatar" accept="image/*">
    <input type="hidden" name="avatar_cropped_data" id="avatar-cropped-data">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:6px;">Dopo aver scelto una foto potrai ritagliarla prima di salvarla.</p>

    <button type="submit" class="btn">Salva profilo</button>
  </form>

  <!-- Finestra di ritaglio della foto profilo: appare solo dopo aver scelto un file, si chiude
       da sola alla conferma. Il ritaglio avviene per intero nel browser (canvas), il server
       riceve già l'immagine quadrata pronta in base64 — vedi commento nel gestore POST sopra. -->
  <div id="avatar-crop-modal" style="display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:12px;padding:18px;max-width:420px;width:100%;">
      <strong>Ritaglia la foto profilo</strong>
      <p style="color:var(--text-muted);font-size:12.5px;margin:4px 0 12px;">Trascina per spostare, usa la rotellina o pizzica per ingrandire.</p>
      <div style="max-height:60vh;overflow:hidden;">
        <img id="avatar-crop-image" src="" style="max-width:100%;display:block;">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
        <button type="button" id="avatar-crop-cancel" class="btn secondary small">Annulla</button>
        <button type="button" id="avatar-crop-confirm" class="btn small">Ritaglia e usa</button>
      </div>
    </div>
  </div>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
  <style>
    /* L'avatar è sempre mostrato come cerchio: la selezione di ritaglio lo mostra allo stesso
       modo (solo l'inquadratura visiva — il ritaglio salvato resta un quadrato, i cerchi CSS
       normalmente partono comunque da un'immagine quadrata). */
    #avatar-crop-modal .cropper-view-box,
    #avatar-crop-modal .cropper-face { border-radius: 50%; }
    #avatar-crop-modal .cropper-view-box { outline: 0; box-shadow: 0 0 0 1px var(--accent); }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
  <script src="<?= assetUrl('/assets/js/avatar-crop.js') ?>" defer></script>

  <div class="card">
    <strong>Il tuo link pubblico:</strong><br>
    <a href="/<?= e($profile['slug']) ?>" target="_blank"><?= e(siteName()) ?>/<?= e($profile['slug']) ?></a>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
