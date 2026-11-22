<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$activeTab = 'profile';
$pageTitle = 'Elimina il mio account';

$blocker = accountDeletionBlocker($user);
$owned = accountDeletionOwnedProfiles((int) $user['id']);
$allIds = array_merge([(int) $user['id']], array_map(fn($p) => (int) $p['id'], $owned));
$error = null;

if (!$blocker && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (empty($_POST['understood'])) {
        $error = 'Conferma di aver capito che l\'eliminazione è definitiva.';
    } elseif (trim((string) ($_POST['confirm_word'] ?? '')) !== 'Elimina') {
        $error = 'Per confermare scrivi esattamente la parola Elimina.';
    } else {
        $email = (string) $user['email'];
        $name = (string) (($user['display_name'] ?? '') ?: $user['slug']);
        $slug = (string) $user['slug'];
        $res = accountDeleteEverything((int) $user['id']);
        if ($res['ok']) {
            notifyAccountDeleted($email, $name);
            notifyAdminsAccountDeleted($email, $name, $slug, (int) $res['deleted']);
            clearRememberToken();
            $_SESSION = [];
            session_destroy();
            header('Location: /account_eliminato.php');
            exit;
        }
        $error = 'Non è stato possibile completare l\'eliminazione. Nessun dato è stato cancellato: riprova o contatta l\'amministratore.';
    }
}

$summary = accountDeletionSummary($allIds);
include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx tlx-narrow">
    <a href="/dashboard_profile.php"><i class="fa-solid fa-arrow-left"></i> Torna al profilo</a>
    <div class="box box-danger">
      <h3><i class="fa-solid fa-triangle-exclamation"></i> Elimina il mio account</h3>
      <?php if ($blocker): ?>
        <div class="alert error"><?= e($blocker) ?></div>
      <?php else: ?>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <p>Eliminando l'account cancelli <b>subito e in modo definitivo</b> il tuo profilo e tutti i suoi contenuti. <b>Non è possibile tornare indietro.</b></p>
        <p><b>Verrà cancellato:</b></p>
        <ul class="box-list">
          <li>Il tuo account (<?= e($user['email']) ?>), il login e il profilo <b>@<?= e($user['slug']) ?></b></li>
          <?php foreach ($summary as $label => $n): if ($n > 0): ?><li><?= e($label) ?>: <?= (int) $n ?></li><?php endif; endforeach; ?>
          <li>Le immagini, i video e i brani caricati</li>
          <li>I follower e le impostazioni collegate</li>
        </ul>
        <?php if ($owned): ?>
          <p><b>Verranno eliminati anche i profili che hai creato:</b></p>
          <ul class="box-list"><?php foreach ($owned as $p): ?><li><?= e($p['display_name'] ?: $p['slug']) ?> (@<?= e($p['slug']) ?>)</li><?php endforeach; ?></ul>
        <?php endif; ?>
        <p class="tlx-hint">I profili altrui che gestisci come co-amministratore non vengono toccati: perdi soltanto l'accesso.</p>
        <form method="post" data-confirm="Ultima conferma: eliminare definitivamente il tuo account e tutti i suoi contenuti?">
          <?= csrfField() ?>
          <label for="da-word">Per confermare scrivi la parola <b>Elimina</b></label>
          <input type="text" name="confirm_word" id="da-word" required autocomplete="off" placeholder="Elimina">
          <?php studioSwitch('understood', "Ho capito che l'eliminazione è definitiva e immediata e che i contenuti non potranno essere recuperati.", false); ?>
          <div class="box-row"><button type="submit" class="stc-act danger"><i class="fa-solid fa-trash"></i> <span>Elimina definitivamente il mio account</span></button><a href="/dashboard_profile.php" class="stc-act">Annulla</a></div>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
