<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php'; // httpRequest(), per la foto dell'account Google
$user = requireLogin();

if (!empty($user['account_type_chosen'])) {
    header('Location: /dashboard.php');
    exit;
}

$profile = getActingProfile($user);
requireFullOwnerAccess($user, $profile);
$error = null;

// Dati dell'account Google (arrivano dal login): precompilano il form.
$google = $_SESSION['google_profile'] ?? [];
$googlePicture = (string) ($google['picture'] ?? '');
if (!preg_match('#^https://[a-z0-9.-]*\.googleusercontent\.com/#i', $googlePicture)) {
    $googlePicture = '';
}

// Voci di menu "base": restano sempre attive e visibili, non compaiono come scelta qui — solo
// i moduli davvero opzionali si possono disattivare dalla schermata di benvenuto. L'elenco deve
// restare in sync con PUBLIC_NAV_ITEM_KEYS/createDefaultProfileNavMenu() in functions.php.
const ONBOARDING_BASE_MODULES = ['Home', 'Timeline', 'Blog', 'Contatti', 'Segui'];

$siteHost = parse_url(siteUrl('/'), PHP_URL_HOST) ?: (string) ($_SERVER['HTTP_HOST'] ?? siteName());

$selectedType = $_POST['account_type'] ?? '';
$isBandOrLabel = in_array($selectedType, ['band', 'label'], true) || $selectedType === '';
$optionalModules = [
    ['name' => 'Link', 'icon' => 'fas fa-link', 'desc' => 'I tuoi link importanti in una pagina'],
    ['name' => 'Band che amo', 'icon' => 'fas fa-heart-circle-check', 'desc' => 'Vetrina degli artisti che ami'],
    ['name' => 'Attori che amo', 'icon' => 'fas fa-clapperboard', 'desc' => 'Vetrina degli attori che ami'],
    ['name' => 'Film che amo', 'icon' => 'fas fa-film', 'desc' => 'Vetrina dei film che ami'],
    ['name' => 'Libri che amo', 'icon' => 'fas fa-book', 'desc' => 'Vetrina dei libri che ami'],
    ['name' => 'Viaggi', 'icon' => 'fas fa-plane', 'desc' => 'Diario dei luoghi che hai visitato'],
    ['name' => 'Brani che amo', 'icon' => 'fas fa-music', 'desc' => 'Vetrina dei brani che ami'],
    ['name' => 'Playlist che amo', 'icon' => 'fas fa-list-ul', 'desc' => 'Vetrina delle playlist Spotify che ami'],
    ['name' => 'Album che amo', 'icon' => 'fas fa-compact-disc', 'desc' => 'Vetrina degli album Spotify che ami'],
    ['name' => 'Ricette che amo', 'icon' => 'fas fa-bowl-food', 'desc' => 'Vetrina delle ricette che ami'],
    ['name' => 'Squadre che amo', 'icon' => 'fas fa-futbol', 'desc' => 'Vetrina delle squadre sportive che ami'],
    ['name' => 'Calciatori che amo', 'icon' => 'fas fa-shirt', 'desc' => 'Vetrina dei calciatori che ami'],
    ['name' => 'Partite che amo', 'icon' => 'fas fa-calendar-check', 'desc' => 'Vetrina delle partite che ami'],
    ['name' => 'Menù', 'icon' => 'fas fa-utensils', 'desc' => 'Menù digitale per locali e ristoranti'],
];
if ($isBandOrLabel) {
    $optionalModules[] = ['name' => 'Eventi', 'icon' => 'fas fa-calendar', 'desc' => 'Calendario degli eventi pubblico'];
    $optionalModules[] = ['name' => 'Spotify', 'icon' => 'fa-brands fa-spotify', 'desc' => 'Discografia collegata a Spotify'];
    $optionalModules[] = ['name' => 'Podcast', 'icon' => 'fas fa-microphone', 'desc' => 'Episodi del tuo podcast Spotify'];
    $optionalModules[] = ['name' => 'YouTube', 'icon' => 'fa-brands fa-youtube', 'desc' => 'Video collegati dal tuo canale YouTube'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $accountType = $_POST['account_type'] ?? '';
    $slug = usernameNormalize((string) ($_POST['slug'] ?? $profile['slug']));
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $bio = trim($_POST['bio'] ?? '');

    if (!in_array($accountType, ['band', 'fan', 'label'], true)) {
        $error = 'Scegli che tipo di profilo vuoi.';
    } elseif ($displayName === '') {
        $error = 'Scrivi il nome del profilo.';
    } elseif (($slugError = usernameValidationError($slug, (string) $profile['slug'])) !== null) {
        $error = $slugError;
    } else {
        $avatarPath = $profile['avatar_path'];

        // Stessa logica di ritaglio lato browser di dashboard_profile.php: se arriva già pronta
        // (cropper.js) la usiamo, altrimenti si ricade sul file grezzo caricato. In entrambi i
        // casi passa da compressImageToJpeg(), che garantisce JPEG e non oltre 250KB.
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
                if (file_put_contents($dir . '/' . $fname, $jpeg) !== false) {
                    $avatarPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
                }
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
                if (file_put_contents($dir . '/' . $fname, $jpeg) !== false) {
                    $avatarPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
                }
            }
        }

        // Nessuna foto caricata: se è spuntata, si usa quella dell'account Google.
        if ($avatarPath === $profile['avatar_path'] && $googlePicture !== '' && !empty($_POST['use_google_photo'])) {
            $big = preg_replace('/=s\d+(-c)?$/', '=s400-c', $googlePicture);
            $raw = httpRequest('GET', $big);
            $jpeg = ($raw && strlen($raw) < 8 * 1024 * 1024) ? compressImageToJpeg($raw, 250 * 1024, 800) : null;
            if ($jpeg !== null) {
                $fname = 'avatar_' . bin2hex(random_bytes(6)) . '.jpg';
                $dir = __DIR__ . '/uploads/images/' . $profile['slug'];
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                if (file_put_contents($dir . '/' . $fname, $jpeg) !== false) {
                    $avatarPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
                }
            }
        }

        $db = getDB();
        if ($slug !== $profile['slug']) {
            $stmt = $db->prepare('UPDATE users SET account_type = ?, account_type_chosen = 1, slug = ? WHERE id = ?');
            $stmt->execute([$accountType, $slug, $profile['id']]);
        } else {
            $stmt = $db->prepare('UPDATE users SET account_type = ?, account_type_chosen = 1 WHERE id = ?');
            $stmt->execute([$accountType, $profile['id']]);
        }

        $stmt = $db->prepare('UPDATE profiles SET display_name = ?, bio = ?, avatar_path = ? WHERE user_id = ?');
        $stmt->execute([mb_substr($displayName, 0, 120), $bio !== '' ? $bio : null, $avatarPath, $profile['id']]);
        unset($_SESSION['google_profile']);

        // Moduli scelti: le voci "base" restano sempre visibili, per le altre si applica la
        // selezione. Prima si assicura che tutte le voci di default esistano già in tabella.
        $selectedModules = $_POST['modules'] ?? [];
        $items = getAllProfileNavigationMenu((int) $profile['id'], $slug);
        $stmt = $db->prepare('UPDATE profile_navigation_menu SET is_visible = ? WHERE id = ? AND user_id = ?');
        foreach ($items as $item) {
            $isBase = in_array($item['name'], ONBOARDING_BASE_MODULES, true);
            $isVisible = $isBase || in_array($item['name'], $selectedModules, true);
            $stmt->execute([$isVisible ? 1 : 0, $item['id'], $profile['id']]);
        }

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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #FAF5EE; color: #17172b; }
  a { color: rgb(108,92,231); }
  .ob-navbar { padding: 22px 24px; }
  .ob-navbar .logo { font-weight: 800; font-size: 20px; }
  .ob-wrap { max-width: 640px; margin: 0 auto; padding: 10px 24px 70px; }
  .ob-wrap h1 { font-size: 26px; margin: 0 0 6px; }
  .ob-wrap .ob-sub { color: #666; font-size: 14.5px; margin: 0 0 26px; }
  .ob-card { background: #fff; border-radius: 18px; padding: 26px; box-shadow: 0 4px 20px rgba(23,23,43,0.06); margin-bottom: 20px; }
  .ob-card h2 { font-size: 15px; margin: 0 0 4px; }
  .ob-card .ob-hint { color: #888; font-size: 12.5px; margin: 0 0 16px; }
  .ob-card label:not(.ob-type-card):not(.ob-module) { display: block; font-size: 13px; font-weight: 600; color: #444; margin: 16px 0 6px; }
  .ob-card label:first-of-type { margin-top: 0; }
  .ob-card input[type=text], .ob-card textarea, .ob-card input[type=file] {
    width: 100%; padding: 11px 14px; border-radius: 10px; border: 1px solid #ddd; background: #fff;
    color: #1a1a1a; font-size: 14.5px; font-family: inherit;
  }
  .ob-card textarea { resize: vertical; }
  .ob-type-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(150px,1fr)); gap: 12px; }
  .ob-type-card {
    display: block; cursor: pointer; border: 2px solid #e8e4dc; border-radius: 14px; padding: 16px 14px;
    background: #FDFBF8; transition: border-color .15s, background .15s;
  }
  .ob-type-card strong { display: block; font-size: 15px; margin-bottom: 4px; }
  .ob-type-card span { color: #777; font-size: 12.5px; }
  .ob-type-input { position: absolute; opacity: 0; pointer-events: none; }
  .ob-type-input:checked + .ob-type-card { border-color: rgb(108,92,231); background: #F1EEFE; }
  .ob-module-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(210px,1fr)); gap: 4px 16px; margin-top: 4px; }
  .ob-module { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; cursor: pointer; }
  .ob-module input { width: auto; margin-top: 3px; accent-color: rgb(108,92,231); }
  .ob-module i { width: 18px; text-align: center; color: #999; }
  .ob-module strong { font-size: 14px; }
  .ob-module small { color: #888; font-size: 12px; display: block; }
  .ob-submit {
    display: block; width: 100%; text-align: center; background: #17172b; color: #fff;
    padding: 14px; border-radius: 999px; border: none; font-weight: 700; font-size: 15px; cursor: pointer;
  }
  .ob-error { background: #fdecec; color: #b3261e; border: 1px solid #f5c6c3; border-radius: 10px; padding: 12px 16px; font-size: 14px; margin-bottom: 18px; }
  .ob-type-icon { display: block; font-size: 20px; color: rgb(108,92,231); margin-bottom: 8px; }
  .ob-google-photo { display: flex !important; align-items: center; gap: 10px; font-weight: 400 !important; margin: 0 0 10px !important; }
  .ob-google-photo img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; }
  .ob-google-photo input { width: auto !important; }
  #ob-slug-msg.ok { color: #1e8e3e; }
  #ob-slug-msg.bad { color: #b3261e; }
  .ob-avatar-preview { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; }
</style>
</head>
<body>
<div class="ob-navbar"><a href="/" class="logo"><?= e(siteName()) ?></a></div>
<div class="ob-wrap">
  <h1>Benvenuto! Completa il tuo profilo.</h1>
  <p class="ob-sub">Puoi cambiare tutto in ogni momento dalla dashboard — questo è solo un punto di partenza.</p>

  <?php if ($error): ?><div class="ob-error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" autocomplete="off"<?= uploadProgressAttrs() ?>>
    <?= csrfField() ?>

    <div class="ob-card">
      <h2>Che tipo di profilo vuoi?</h2>
      <p class="ob-hint">Serve solo a darti gli strumenti giusti. Puoi cambiarlo più avanti scrivendo all'assistenza.</p>
      <div class="ob-type-grid">
        <div style="position:relative;">
          <input class="ob-type-input" type="radio" name="account_type" value="band" id="ob-type-band" <?= $selectedType === 'band' || $selectedType === '' ? 'checked' : '' ?>>
          <label for="ob-type-band" class="ob-type-card">
            <i class="fa-solid fa-star ob-type-icon"></i>
            <strong>Profilo artista</strong>
            <span>Musicisti, band, performer e creativi che si presentano al pubblico</span>
          </label>
        </div>
        <div style="position:relative;">
          <input class="ob-type-input" type="radio" name="account_type" value="label" id="ob-type-label" <?= $selectedType === 'label' ? 'checked' : '' ?>>
          <label for="ob-type-label" class="ob-type-card">
            <i class="fa-solid fa-building ob-type-icon"></i>
            <strong>Profilo aziendale</strong>
            <span>Attività, locali, negozi, etichette e organizzazioni</span>
          </label>
        </div>
        <div style="position:relative;">
          <input class="ob-type-input" type="radio" name="account_type" value="fan" id="ob-type-fan" <?= $selectedType === 'fan' ? 'checked' : '' ?>>
          <label for="ob-type-fan" class="ob-type-card">
            <i class="fa-solid fa-user ob-type-icon"></i>
            <strong>Profilo personale</strong>
            <span>Per te: racconta ciò che ami e segui gli altri profili</span>
          </label>
        </div>
      </div>
    </div>

    <div class="ob-card">
      <h2>Il tuo profilo</h2>
      <label>Email (dal tuo account Google)</label>
      <input type="text" value="<?= e($user['email']) ?>" readonly style="background:#f5f3ee;color:#666;">

      <label>Nome del profilo</label>
      <input type="text" name="display_name" value="<?= e($_POST['display_name'] ?? '') ?>" placeholder="Come vuoi chiamare il tuo profilo?" maxlength="120" required autocomplete="off" autocapitalize="words">

      <label>Username (<span id="ob-domain"><?= e($siteHost) ?></span>/<strong id="ob-slug-live"><?= e(usernameNormalize((string) ($_POST['slug'] ?? '')) ?: 'username') ?></strong>)</label>
      <input type="text" name="slug" id="ob-slug" value="<?= e($_POST['slug'] ?? '') ?>" placeholder="Scegli il tuo username" required maxlength="40" autocomplete="off" autocapitalize="off" spellcheck="false">
      <p id="ob-slug-msg" class="ob-hint" style="margin:6px 0 0;"></p>

      <label>Bio (opzionale)</label>
      <textarea name="bio" rows="3"><?= e($_POST['bio'] ?? $profile['bio'] ?? '') ?></textarea>

      <label>Foto profilo (opzionale)</label>
      <img id="avatar-preview" class="ob-avatar-preview" src="<?= $profile['avatar_path'] ? e('/' . $profile['avatar_path']) : '' ?>"
           style="<?= $profile['avatar_path'] ? '' : 'display:none;' ?>">
      <?php if ($googlePicture !== '' && !$profile['avatar_path']): ?>
        <label class="ob-google-photo">
          <input type="checkbox" name="use_google_photo" value="1" checked>
          <img src="<?= e($googlePicture) ?>" alt="" referrerpolicy="no-referrer">
          <span>Usa la foto del mio account Google (o scegline un'altra qui sotto)</span>
        </label>
      <?php endif; ?>
      <input type="file" id="avatar-input" name="avatar" accept="image/*">
      <input type="hidden" name="avatar_cropped_data" id="avatar-cropped-data">
      <p class="ob-hint" style="margin-top:6px;">Dopo aver scelto una foto potrai ritagliarla prima di salvarla.</p>
    </div>

    <div class="ob-card">
      <h2>Quali moduli vuoi usare?</h2>
      <p class="ob-hint">
        Home, Timeline, Blog, Contatti e Segui restano sempre attivi. Gli altri li scegli tu —
        compaiono comunque solo quando ci metti davvero del contenuto, e puoi riaccenderli/spegnerli
        in ogni momento da Dashboard → menù hamburger → Menu di Navigazione.
      </p>
      <div class="ob-module-grid">
        <?php foreach ($optionalModules as $m): ?>
          <label class="ob-module">
            <input type="checkbox" name="modules[]" value="<?= e($m['name']) ?>" checked>
            <span>
              <i class="<?= e($m['icon']) ?>"></i>
              <strong><?= e($m['name']) ?></strong>
              <small><?= e($m['desc']) ?></small>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <button type="submit" class="ob-submit">Vai alla Dashboard</button>
  </form>

  <!-- Ritaglio foto profilo: stesso componente di dashboard_profile.php -->
  <div id="avatar-crop-modal" style="display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:12px;padding:18px;max-width:420px;width:100%;">
      <strong>Ritaglia la foto profilo</strong>
      <p style="color:#888;font-size:12.5px;margin:4px 0 12px;">Trascina per spostare, usa la rotellina o pizzica per ingrandire.</p>
      <div style="max-height:60vh;overflow:hidden;">
        <img id="avatar-crop-image" src="" style="max-width:100%;display:block;">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
        <button type="button" id="avatar-crop-cancel" style="background:#fff;color:#1a1a1a;padding:10px 16px;border-radius:999px;border:1px solid #ccc;font-weight:700;font-size:13px;cursor:pointer;">Annulla</button>
        <button type="button" id="avatar-crop-confirm" style="background:rgb(108,92,231);color:#fff;padding:10px 16px;border-radius:999px;border:none;font-weight:700;font-size:13px;cursor:pointer;">Ritaglia e usa</button>
      </div>
    </div>
  </div>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
  <style>
    #avatar-crop-modal .cropper-view-box,
    #avatar-crop-modal .cropper-face { border-radius: 50%; }
    #avatar-crop-modal .cropper-view-box { outline: 0; box-shadow: 0 0 0 1px rgb(108,92,231); }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
  <script src="<?= assetUrl('/assets/js/avatar-crop.js') ?>" defer></script>
  <script src="<?= assetUrl('/assets/js/upload-progress.js') ?>" defer></script>
  <script>
  // Username: l'indirizzo tra parentesi si aggiorna mentre scrivi e si controlla subito se è libero.
  (function () {
    var input = document.getElementById('ob-slug'), live = document.getElementById('ob-slug-live'), msg = document.getElementById('ob-slug-msg');
    var timer = null, seq = 0;
    function norm(v) { return v.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40); }
    function check() {
      var s = norm(input.value), my = ++seq;
      live.textContent = s || 'username';
      if (s.length < 3) { msg.className = 'ob-hint bad'; msg.textContent = 'Almeno 3 caratteri: lettere, numeri o trattini.'; return; }
      fetch('/api_username_check.php?u=' + encodeURIComponent(s), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (my !== seq) return; msg.className = 'ob-hint ' + (d.ok ? 'ok' : 'bad'); msg.textContent = (d.ok ? '✓ ' : '✗ ') + d.message; })
        .catch(function () {});
    }
    input.addEventListener('input', function () {
      live.textContent = norm(input.value) || 'username';
      clearTimeout(timer);
      if (!input.value.trim()) { msg.className = 'ob-hint'; msg.textContent = ''; return; }
      timer = setTimeout(check, 300);
    });
    // Nessun valore preimpostato: se il browser ha ripristinato qualcosa (cache, ripristino modulo), si svuota.
    var serverVal = input.getAttribute('value') || '';
    function wipe() { if (document.activeElement !== input && input.value !== serverVal) { input.value = serverVal; live.textContent = norm(serverVal) || 'username'; } var dn = document.querySelector('input[name=display_name]'); if (dn && document.activeElement !== dn && dn.value !== (dn.getAttribute('value') || '')) { dn.value = dn.getAttribute('value') || ''; } }
    wipe(); window.addEventListener('pageshow', wipe); setTimeout(wipe, 300);
    if (serverVal) check();
  })();
  </script>
</div>
</body>
</html>
