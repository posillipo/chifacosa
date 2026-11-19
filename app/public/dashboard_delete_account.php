<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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
include __DIR__ . '/_dash_header.php';
?>
  <p><a href="/dashboard_profile.php"><i class="fa-solid fa-arrow-left"></i> Torna al profilo</a></p>

  <div class="card" style="border:1px solid #e74c3c55;">
    <div class="section-title" style="margin-top:0;color:#e74c3c;">Elimina il mio account</div>

    <?php if ($blocker): ?>
      <div class="alert error"><?= e($blocker) ?></div>
    <?php else: ?>
      <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

      <p>Eliminando l'account cancelli <strong>subito e in modo definitivo</strong> il tuo profilo e tutti i suoi contenuti. <strong>Non è possibile tornare indietro.</strong></p>

      <p style="margin-bottom:6px;"><strong>Verrà cancellato:</strong></p>
      <ul style="margin-top:0;">
        <li>Il tuo account (<?= e($user['email']) ?>), il login e il profilo <strong>@<?= e($user['slug']) ?></strong></li>
        <?php foreach ($summary as $label => $n): if ($n > 0): ?><li><?= e($label) ?>: <?= (int) $n ?></li><?php endif; endforeach; ?>
        <li>Le immagini, i video e i brani caricati</li>
        <li>I follower e le impostazioni collegate</li>
      </ul>

      <?php if ($owned): ?>
        <p style="margin-bottom:6px;"><strong>Verranno eliminati anche i profili che hai creato:</strong></p>
        <ul style="margin-top:0;">
          <?php foreach ($owned as $p): ?><li><?= e($p['display_name'] ?: $p['slug']) ?> (@<?= e($p['slug']) ?>)</li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <p style="color:var(--text-muted);font-size:13px;">I profili altrui che gestisci come co-amministratore non vengono toccati: perdi soltanto l'accesso. Se vuoi conservare qualcosa, copialo prima di procedere.</p>

      <form method="post" onsubmit="return confirm('Ultima conferma: eliminare definitivamente il tuo account e tutti i suoi contenuti?');">
        <?= csrfField() ?>
        <label>Per confermare scrivi la parola <strong>Elimina</strong></label>
        <input type="text" name="confirm_word" required autocomplete="off" placeholder="Elimina">
        <label style="display:flex;align-items:flex-start;gap:8px;font-weight:normal;">
          <input type="checkbox" name="understood" value="1" style="width:auto;margin-top:3px;" required>
          Ho capito che l'eliminazione è definitiva e immediata e che i contenuti non potranno essere recuperati.
        </label>
        <button type="submit" class="btn danger"><i class="fa-solid fa-trash"></i> Elimina definitivamente il mio account</button>
        <a href="/dashboard_profile.php" class="btn secondary" style="margin-left:8px;">Annulla</a>
      </form>
    <?php endif; ?>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
