<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/youtube.php';
$user = requireLogin();
requireBandOrLabel($user);
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
                $stmt->execute([$resolved['channel_id'], $resolved['channel_name'], $user['id']]);
                header('Location: /dashboard_youtube.php');
                exit;
            }
        }
    } elseif ($action === 'unlink') {
        $stmt = getDB()->prepare('UPDATE profiles SET youtube_channel_id=NULL, youtube_channel_name=NULL WHERE user_id=?');
        $stmt->execute([$user['id']]);
        header('Location: /dashboard_youtube.php');
        exit;
    }
}

$user = currentUser();

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Incolla il link del tuo canale YouTube (es. <code>https://www.youtube.com/@tuoband</code>
      o <code>https://www.youtube.com/channel/UC...</code>): la tua pagina pubblica mostrerà
      automaticamente i video più recenti, aggiornati direttamente da YouTube.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <?php if (!empty($user['youtube_channel_id'])): ?>
    <div class="card">
      <strong>Canale collegato:</strong> <?= e($user['youtube_channel_name'] ?: $user['youtube_channel_id']) ?>
      <br>
      <a href="https://www.youtube.com/channel/<?= e($user['youtube_channel_id']) ?>" target="_blank">Vedi su YouTube ↗</a>
      <br><br>
      <a href="/<?= e($user['slug']) ?>/video" target="_blank">Vedi la tua pagina pubblica Video ↗</a>
      <form method="post" style="margin-top:12px;" onsubmit="return confirm('Scollegare il canale YouTube? La sezione dedicata sparirà dalla tua pagina pubblica.');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="unlink">
        <button class="btn danger" type="submit">Scollega canale YouTube</button>
      </form>
    </div>
  <?php else: ?>
    <div class="alert error">Nessun canale YouTube collegato ancora.</div>
  <?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="link">
    <label>Link del tuo canale YouTube</label>
    <input type="text" name="channel_link" placeholder="https://www.youtube.com/@tuoband" required>
    <button type="submit" class="btn">Collega canale</button>
  </form>
<?php include __DIR__ . '/_dash_footer.php'; ?>
