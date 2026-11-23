<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
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
    $timezone = trim($_POST['timezone'] ?? '');
    $timezone = isset(TIMEZONE_OPTIONS[$timezone]) ? $timezone : 'rome';
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
            $dir = __DIR__ . '/../uploads/images/' . $profile['slug'];
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
            $dir = __DIR__ . '/../uploads/images/' . $profile['slug'];
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
        $stmt = getDB()->prepare('UPDATE profiles SET display_name=?, bio=?, avatar_path=?, theme_color=?, genere=?, citta=?, provincia=?, telefono=?, dashboard_theme=? WHERE user_id=?');
        $stmt->execute([$displayName, $bio, $avatarPath, $themeColor, $genere ?: null, $citta ?: null, $provincia ?: null, $telefono ?: null, $timezone, $profile['id']]);
        // Profilo privato (migrazione 2026_11_33): nascosto da ricerche/liste, pagina pubblica chiusa ai non autorizzati.
        if (profilesHavePrivateColumn()) {
            getDB()->prepare('UPDATE profiles SET is_private=? WHERE user_id=?')->execute([!empty($_POST['is_private']) ? 1 : 0, $profile['id']]);
        }
        $success = 'Profilo aggiornato.';
        $user = currentUser(true);
        $profile = getActingProfile($user);
    }
}

$isPrivateNow = false;
if (profilesHavePrivateColumn()) {
    $pq = getDB()->prepare('SELECT is_private FROM profiles WHERE user_id=?');
    $pq->execute([$profile['id']]);
    $isPrivateNow = (int) $pq->fetchColumn() === 1;
}

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx tlx-narrow">
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="box">
      <?= csrfField() ?>
      <h3><i class="fa-regular fa-id-card"></i> Il tuo profilo</h3>

      <div class="pfx-avatar">
        <img id="avatar-preview" src="<?= $profile['avatar_path'] ? e('/' . $profile['avatar_path']) : '' ?>" alt="">
        <div>
          <label for="avatar-input">Foto profilo</label>
          <input type="file" id="avatar-input" name="avatar" accept="image/*">
          <input type="hidden" name="avatar_cropped_data" id="avatar-cropped-data">
          <p class="tlx-hint">Dopo aver scelto una foto potrai ritagliarla prima di salvarla.</p>
        </div>
      </div>

      <label for="pf-dn">Nome / Nome d'arte</label>
      <input type="text" id="pf-dn" name="display_name" value="<?= e($profile['display_name']) ?>" required>
      <label for="pf-bio">Bio</label>
      <textarea id="pf-bio" name="bio" rows="4"><?= e($profile['bio']) ?></textarea>
      <label for="pf-gen">Genere musicale</label>
      <input type="text" id="pf-gen" name="genere" value="<?= e($profile['genere'] ?? '') ?>" placeholder="es. Rock, Pop, Cantautore...">
      <div class="pfx-row">
        <div><label for="pf-city">Città</label><input type="text" id="pf-city" name="citta" value="<?= e($profile['citta'] ?? '') ?>"></div>
        <div><label for="pf-prov">Provincia</label><input type="text" id="pf-prov" name="provincia" value="<?= e($profile['provincia'] ?? '') ?>" placeholder="es. Na, Mi, Rm..."></div>
      </div>
      <label for="pf-tel">Telefono</label>
      <input type="text" id="pf-tel" name="telefono" value="<?= e($profile['telefono'] ?? '') ?>">
      <label for="pf-tz">Fuso orario</label>
      <select id="pf-tz" name="timezone">
        <?php foreach (TIMEZONE_OPTIONS as $tzKey => $tz): ?>
          <option value="<?= e($tzKey) ?>" <?= profileTimezoneKey($profile) === $tzKey ? 'selected' : '' ?>><?= e($tz['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="tlx-hint">Serve a mostrare date e orari dei tuoi contenuti nel fuso giusto, ovunque sia il server.</p>
      <label for="pf-col">Colore tema (pagina pubblica)</label>
      <input type="color" id="pf-col" name="theme_color" value="<?= e($profile['theme_color'] ?? '#6C5CE7') ?>" class="pfx-color">

      <?php if (profilesHavePrivateColumn()): ?>
        <?php studioSwitch('is_private', 'Profilo privato: non compare nelle ricerche, nelle liste né nei motori di ricerca, e la tua pagina pubblica si apre solo a te, a chi la gestisce e ai tuoi follower. Automazioni e MCP continuano a funzionare.', $isPrivateNow); ?>
      <?php endif; ?>

      <button type="submit" class="tlx-submit cmp-done">Salva profilo</button>
    </form>

    <div class="box">
      <h3><i class="fa-solid fa-link"></i> Il tuo link pubblico</h3>
      <p><a href="/<?= e($profile['slug']) ?>" target="_blank" rel="noopener"><?= e(siteName()) ?>/<?= e($profile['slug']) ?></a></p>
    </div>
    <div class="box box-danger">
      <h3><i class="fa-solid fa-triangle-exclamation"></i> Elimina il mio account</h3>
      <p>Cancella il tuo account e tutti i tuoi contenuti dal portale. L'operazione è definitiva.</p>
      <a href="/dashboard_delete_account.php" class="stc-act danger"><i class="fa-solid fa-trash"></i> <span>Elimina il mio account…</span></a>
    </div>
  </div>

  <div id="avatar-crop-modal" class="pfx-crop">
    <div class="pfx-crop-in">
      <b>Ritaglia la foto profilo</b>
      <p class="tlx-hint">Trascina per spostare, usa la rotellina o pizzica per ingrandire.</p>
      <div class="pfx-crop-img"><img id="avatar-crop-image" src="" alt=""></div>
      <div class="box-row pfx-crop-act">
        <button type="button" id="avatar-crop-cancel" class="stc-act">Annulla</button>
        <button type="button" id="avatar-crop-confirm" class="stc-act primary">Ritaglia e usa</button>
      </div>
    </div>
  </div>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
  <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
  <script src="<?= assetUrl('/assets/js/avatar-crop.js') ?>" defer></script>
<script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
