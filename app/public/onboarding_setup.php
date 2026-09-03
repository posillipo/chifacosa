<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$error = null;

// Voci di menu "base": restano sempre attive e visibili, non compaiono come scelta qui — solo
// i moduli davvero opzionali si possono disattivare dalla schermata di benvenuto. L'elenco deve
// restare in sync con PUBLIC_NAV_ITEM_KEYS/createDefaultProfileNavMenu() in functions.php.
const ONBOARDING_BASE_MODULES = ['Home', 'Timeline', 'Blog', 'Contatti', 'Segui'];

$isBandOrLabel = in_array($_POST['account_type'] ?? $profile['account_type'] ?? 'band', ['band', 'label'], true);
$optionalModules = [
    ['name' => 'Link', 'icon' => 'fas fa-link', 'desc' => 'I tuoi link importanti in una pagina'],
    ['name' => 'Band che amo', 'icon' => 'fas fa-heart-circle-check', 'desc' => 'Vetrina delle band/artisti che ami'],
    ['name' => 'Attori che amo', 'icon' => 'fas fa-clapperboard', 'desc' => 'Vetrina degli attori che ami'],
    ['name' => 'Film che amo', 'icon' => 'fas fa-film', 'desc' => 'Vetrina dei film che ami'],
    ['name' => 'Libri che amo', 'icon' => 'fas fa-book', 'desc' => 'Vetrina dei libri che ami'],
    ['name' => 'Viaggi', 'icon' => 'fas fa-plane', 'desc' => 'Diario dei luoghi che hai visitato'],
    ['name' => 'Brani che amo', 'icon' => 'fas fa-music', 'desc' => 'Vetrina dei brani che ami'],
    ['name' => 'Menù', 'icon' => 'fas fa-utensils', 'desc' => 'Menù digitale (locali/ristoranti)'],
];
if ($isBandOrLabel) {
    $optionalModules[] = ['name' => 'Eventi', 'icon' => 'fas fa-calendar', 'desc' => 'Calendario concerti/eventi pubblico'];
    $optionalModules[] = ['name' => 'Spotify', 'icon' => 'fa-brands fa-spotify', 'desc' => 'Discografia collegata a Spotify'];
    $optionalModules[] = ['name' => 'Podcast', 'icon' => 'fas fa-microphone', 'desc' => 'Episodi del tuo podcast Spotify'];
    $optionalModules[] = ['name' => 'Video', 'icon' => 'fa-brands fa-youtube', 'desc' => 'Video collegati dal tuo canale YouTube'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $slug = slugify($_POST['slug'] ?? $profile['slug']);
    $bio = trim($_POST['bio'] ?? '');

    if ($slug === '') {
        $error = 'Il nome pagina non può essere vuoto.';
    } elseif ($slug !== $profile['slug'] && in_array($slug, RESERVED_SLUGS, true)) {
        $error = 'Questo nome pagina non è disponibile, scegline un altro.';
    } elseif ($slug !== $profile['slug'] && slugExists($slug)) {
        $error = 'Questo nome pagina è già in uso.';
    } else {
        $avatarPath = $profile['avatar_path'];

        // Stessa logica di ritaglio lato browser di dashboard_profile.php: se arriva già pronta
        // (cropper.js) la usiamo, altrimenti si ricade sul file grezzo caricato.
        $croppedData = $_POST['avatar_cropped_data'] ?? '';
        if ($croppedData !== '' && preg_match('#^data:image/(jpeg|png);base64,#', $croppedData, $m)) {
            $raw = base64_decode(substr($croppedData, strpos($croppedData, ',') + 1), true);
            if ($raw !== false && strlen($raw) > 0 && strlen($raw) < 8 * 1024 * 1024) {
                $fname = 'avatar_' . bin2hex(random_bytes(6)) . '.jpg';
                $dir = __DIR__ . '/uploads/images/' . $profile['slug'];
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                if (file_put_contents($dir . '/' . $fname, $raw) !== false) {
                    $avatarPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
                }
            }
        } elseif (!empty($_FILES['avatar']['name'])) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $fname = 'avatar_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $dir = __DIR__ . '/uploads/images/' . $profile['slug'];
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dir . '/' . $fname)) {
                    $avatarPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
                }
            }
        }

        $db = getDB();
        if ($slug !== $profile['slug']) {
            $stmt = $db->prepare('UPDATE users SET slug = ? WHERE id = ?');
            $stmt->execute([$slug, $profile['id']]);
        }
        $stmt = $db->prepare('UPDATE profiles SET bio = ?, avatar_path = ? WHERE user_id = ?');
        $stmt->execute([$bio !== '' ? $bio : null, $avatarPath, $profile['id']]);

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
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="navbar">
  <div class="brand"><a href="/"><?= e(siteName()) ?></a></div>
</div>
<div class="container" style="max-width:640px;">
  <h2>Personalizza la tua pagina</h2>
  <p style="color:var(--text-muted);margin-bottom:24px;">
    Puoi cambiare tutto in ogni momento dalla dashboard — questo è solo un punto di partenza.
  </p>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>

    <label>Nome pagina (<?= e(siteName()) ?>/<strong>nomepagina</strong>)</label>
    <input type="text" name="slug" value="<?= e($_POST['slug'] ?? $profile['slug']) ?>" required>

    <label>Bio (opzionale)</label>
    <textarea name="bio" rows="3"><?= e($_POST['bio'] ?? $profile['bio'] ?? '') ?></textarea>

    <label>Foto profilo (opzionale)</label>
    <img id="avatar-preview" src="<?= $profile['avatar_path'] ? e('/' . $profile['avatar_path']) : '' ?>"
         style="width:70px;height:70px;border-radius:50%;object-fit:cover;margin-bottom:10px;<?= $profile['avatar_path'] ? '' : 'display:none;' ?>">
    <input type="file" id="avatar-input" name="avatar" accept="image/*">
    <input type="hidden" name="avatar_cropped_data" id="avatar-cropped-data">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:6px;">Dopo aver scelto una foto potrai ritagliarla prima di salvarla.</p>

    <label style="margin-top:18px;">Quali moduli vuoi usare?</label>
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">
      Home, Timeline, Blog, Contatti e Segui restano sempre attivi. Gli altri li scegli tu —
      compaiono comunque solo quando ci metti davvero del contenuto, e puoi riaccenderli/spegnerli
      in ogni momento da Dashboard → menù hamburger → Menu di Navigazione.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:4px 16px;margin-top:6px;">
      <?php foreach ($optionalModules as $m): ?>
        <label style="display:flex;align-items:flex-start;gap:10px;font-weight:normal;padding:8px 0;">
          <input type="checkbox" name="modules[]" value="<?= e($m['name']) ?>" checked style="width:auto;margin-top:3px;">
          <span>
            <i class="<?= e($m['icon']) ?>" style="width:18px;text-align:center;color:var(--text-muted);"></i>
            <strong><?= e($m['name']) ?></strong><br>
            <small style="color:var(--text-muted)"><?= e($m['desc']) ?></small>
          </span>
        </label>
      <?php endforeach; ?>
    </div>

    <button type="submit" class="btn" style="margin-top:20px;">Vai alla Dashboard</button>
  </form>

  <!-- Ritaglio foto profilo: stesso componente di dashboard_profile.php -->
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
    #avatar-crop-modal .cropper-view-box,
    #avatar-crop-modal .cropper-face { border-radius: 50%; }
    #avatar-crop-modal .cropper-view-box { outline: 0; box-shadow: 0 0 0 1px var(--accent); }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
  <script src="<?= assetUrl('/assets/js/avatar-crop.js') ?>" defer></script>
</div>
</body>
</html>
