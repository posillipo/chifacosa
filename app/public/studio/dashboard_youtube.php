<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/youtube.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
$activeTab = 'youtube';
$pageTitle = 'YouTube';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'link') {
        $input = trim($_POST['channel_link'] ?? '');
        if ($input === '') {
            $error = 'Incolla il link (o l\'handle @nomecanale) del tuo canale YouTube.';
        } else {
            $resolved = youtubeResolveChannel($input);
            if (!$resolved) {
                $error = 'Non sono riuscito a trovare questo canale. Controlla il link e riprova (usa il link completo del canale, es. https://www.youtube.com/@tuocanale).';
            } else {
                $stmt = getDB()->prepare('UPDATE profiles SET youtube_channel_id=?, youtube_channel_name=? WHERE user_id=?');
                $stmt->execute([$resolved['channel_id'], $resolved['channel_name'], $profile['id']]);
                header('Location: /dashboard_youtube.php');
                exit;
            }
        }
    } elseif ($action === 'unlink') {
        $stmt = getDB()->prepare('UPDATE profiles SET youtube_channel_id=NULL, youtube_channel_name=NULL WHERE user_id=?');
        $stmt->execute([$profile['id']]);
        header('Location: /dashboard_youtube.php');
        exit;
    }
}

$user = currentUser();
$profile = getActingProfile($user);

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx tlx-narrow">
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if (!empty($profile['youtube_channel_id'])): ?>
      <div class="box">
        <h3><i class="fa-brands fa-youtube"></i> Canale collegato</h3>
        <p><b><?= e($profile['youtube_channel_name'] ?: $profile['youtube_channel_id']) ?></b></p>
        <div class="box-row">
          <a class="stc-act" href="https://www.youtube.com/channel/<?= e($profile['youtube_channel_id']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Vedi su YouTube</span></a>
          <a class="stc-act" href="/<?= e($profile['slug']) ?>/youtube" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> <span>La tua pagina pubblica</span></a>
          <form method="post" data-confirm="Scollegare il canale YouTube? La sezione dedicata sparirà dalla tua pagina pubblica."><?= csrfField() ?><input type="hidden" name="action" value="unlink"><button class="stc-act danger" type="submit"><i class="fa-solid fa-link-slash"></i> <span>Scollega</span></button></form>
        </div>
      </div>
    <?php else: ?>
      <div class="tlx-empty"><i class="fa-brands fa-youtube"></i><b>Nessun canale YouTube collegato</b><span>Incolla qui sotto il link del tuo canale.</span></div>
    <?php endif; ?>
    <form method="post" class="box">
      <?= csrfField() ?><input type="hidden" name="action" value="link">
      <h3><i class="fa-solid fa-link"></i> Link del tuo canale YouTube</h3>
      <input type="text" name="channel_link" placeholder="https://www.youtube.com/@tuoband" required>
      <button type="submit" class="tlx-submit cmp-done">Collega canale</button>
    </form>
  </div>
<script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
