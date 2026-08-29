<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
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
        $customFeedGuidSince = $profile['custom_feed_guid_since'] ?? null;
        if ($customFeedGuid !== ($profile['custom_feed_guid'] ?? null)) {
            $customFeedGuidSince = $customFeedGuid ? date('Y-m-d H:i:s') : null;
        }

        $stmt = getDB()->prepare('UPDATE profiles SET custom_feed_guid=?, custom_feed_guid_since=? WHERE user_id=?');
        $stmt->execute([$customFeedGuid, $customFeedGuidSince, $profile['id']]);
        $success = 'Impostazioni feed aggiornate.';
        $user = currentUser();
        $profile = getActingProfile($user);
    }
}

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Se compili il link qui sotto, chi clicca sui post pubblicati <strong>da questo momento in
      poi</strong> (dalla Timeline, dal feed RSS o da un'automazione tipo Metricool) viene
      automaticamente reindirizzato a questo indirizzo invece che alla pagina del post su
      <?= e(siteName()) ?>. Il feed RSS continua però a esporre il permalink standard di
      <?= e(siteName()) ?> (non questo link) proprio per restare compatibile con strumenti come
      Metricool, che leggono l'immagine di anteprima dalla pagina del post — l'immagine mostrata
      resta quindi sempre quella caricata qui, anche quando il click porta altrove. I post già
      pubblicati non vengono toccati. Finché non modifichi o svuoti questo campo, ogni nuovo post
      userà lo stesso link: se ti serve un link diverso per un'altra pubblicazione, torna qui e
      aggiornalo.
    </p>
  </details>

  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label>Link personalizzato (opzionale)</label>
    <input type="url" name="custom_feed_guid" value="<?= e($profile['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
    <?php if (!empty($profile['custom_feed_guid_since'])): ?>
      <p style="color:var(--text-muted);font-size:13px;">
        Attivo dal <?= e(date('d/m/Y H:i', strtotime($profile['custom_feed_guid_since']))) ?>.
      </p>
    <?php endif; ?>
    <button type="submit" class="btn">Salva</button>
  </form>

  <div class="card">
    <strong>Il tuo feed:</strong><br>
    <a href="/<?= e($profile['slug']) ?>/feed" target="_blank"><?= e(siteName()) ?>/<?= e($profile['slug']) ?>/feed</a>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
