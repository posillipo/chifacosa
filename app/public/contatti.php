<?php
session_start();
require_once __DIR__ . '/../src/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userSlug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, u.email, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.genere, p.youtube_channel_id
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$userSlug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$uid = $artist['id'];
$formSent = false;
$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = trim($_POST['sender_name'] ?? '');
    $email = trim($_POST['sender_email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
        $formError = 'Compila tutti i campi con un\'email valida.';
    } else {
        $stmt = getDB()->prepare('INSERT INTO contact_requests (user_id, sender_name, sender_email, message) VALUES (?,?,?,?)');
        $stmt->execute([$uid, $name, $email, $message]);
        $formSent = true;

        notifyNewContact(
            $artist['email'],
            $artist['display_name'],
            $name,
            $email,
            $message,
            siteUrl('/' . $userSlug)
        );
    }
}

$pageUrl = siteUrl('/' . $userSlug . '/contatti');
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contatti — <?= e($artist['display_name']) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="Contatta <?= e($artist['display_name']) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($artist['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($artist['theme_color'])) ?>; }</style>
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body class="<?= e(getPageThemeClass($artist['page_theme'] ?? 'colorful')) ?>">
<?php if (str_starts_with($artist['page_theme'] ?? 'colorful', 'wave')): ?><?= renderWaveBackground($artist['theme_color'] ?? '#6C5CE7', $artist['page_theme']) ?><?php endif; ?>
<?= embedTrackingBodyStart() ?>
<div class="container">
  <?= publicProfileHeader($artist, 'contatti') ?>

  <?php if ($formSent): ?>
    <div class="alert success">Messaggio inviato! Grazie, verrai ricontattato al più presto.</div>
  <?php else: ?>
    <?php if ($formError): ?><div class="alert error"><?= e($formError) ?></div><?php endif; ?>
    <form method="post" class="card">
      <?= csrfField() ?>
      <label>Nome</label>
      <input type="text" name="sender_name" required>
      <label>Email</label>
      <input type="email" name="sender_email" required>
      <label>Messaggio</label>
      <textarea name="message" rows="4" required></textarea>
      <button type="submit" class="btn">Invia messaggio</button>
    </form>
  <?php endif; ?>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($userSlug) ?>
</body>
</html>
