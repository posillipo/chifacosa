<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$activeTab = 'feed';
$pageTitle = 'Feed RSS';
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $customFeedGuid = trim($_POST['custom_feed_guid'] ?? '');

    if ($customFeedGuid !== '' && !filter_var($customFeedGuid, FILTER_VALIDATE_URL)) {
        $error = 'Il link personalizzato non è un URL valido.';
    } else {
        $customFeedGuid = $customFeedGuid ?: null;
        // Il timestamp "since" segna da quando vale il link personalizzato: si aggiorna solo
        // quando il valore cambia davvero, così il feed continua ad applicarlo dal momento giusto
        // invece di ripartire da adesso ogni volta che si salva il form senza toccare questo campo.
        $customFeedGuidSince = $user['custom_feed_guid_since'] ?? null;
        if ($customFeedGuid !== ($user['custom_feed_guid'] ?? null)) {
            $customFeedGuidSince = $customFeedGuid ? date('Y-m-d H:i:s') : null;
        }

        $stmt = getDB()->prepare('UPDATE profiles SET custom_feed_guid=?, custom_feed_guid_since=? WHERE user_id=?');
        $stmt->execute([$customFeedGuid, $customFeedGuidSince, $user['id']]);
        $success = 'Impostazioni feed aggiornate.';
        $user = currentUser();
    }
}

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Il feed RSS della tua Timeline (<code>/<?= e($user['slug']) ?>/feed</code>) espone per ogni
      post un elemento <code>&lt;link&gt;</code> che punta al permalink automatico su
      <?= e(siteName()) ?>. Se compili il link qui sotto, i post pubblicati
      <strong>da questo momento in poi</strong> useranno quel link al posto del permalink
      automatico — utile ad esempio se un'automazione collegata al feed deve aprire un altro
      indirizzo. Il permalink della pagina resta comunque sempre quello standard, cambia solo il
      feed, e i post già pubblicati non vengono toccati. Finché non modifichi o svuoti questo
      campo, ogni nuovo post userà lo stesso link: se ti serve un link diverso per un'altra
      pubblicazione, torna qui e aggiornalo.
    </p>
  </details>

  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label>Link personalizzato (opzionale)</label>
    <input type="url" name="custom_feed_guid" value="<?= e($user['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
    <?php if (!empty($user['custom_feed_guid_since'])): ?>
      <p style="color:var(--text-muted);font-size:13px;">
        Attivo dal <?= e(date('d/m/Y H:i', strtotime($user['custom_feed_guid_since']))) ?>.
      </p>
    <?php endif; ?>
    <button type="submit" class="btn">Salva</button>
  </form>

  <div class="card">
    <strong>Il tuo feed:</strong><br>
    <a href="/<?= e($user['slug']) ?>/feed" target="_blank"><?= e(siteName()) ?>/<?= e($user['slug']) ?>/feed</a>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
