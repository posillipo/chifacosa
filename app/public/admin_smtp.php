<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'smtp';
$pageTitle = 'Email / SMTP';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('smtp_host', trim($_POST['smtp_host'] ?? ''));
        setSiteSetting('smtp_port', trim($_POST['smtp_port'] ?? '587'));
        setSiteSetting('smtp_user', trim($_POST['smtp_user'] ?? ''));
        // Password: se il campo è lasciato vuoto, manteniamo quella già salvata
        // (evita di dover reinserire la password ogni volta che si aggiorna un altro campo)
        $newPass = $_POST['smtp_pass'] ?? '';
        if (trim($newPass) !== '') {
            setSiteSetting('smtp_pass', trim($newPass));
        }
        setSiteSetting('smtp_secure', $_POST['smtp_secure'] ?? 'tls');
        setSiteSetting('smtp_from', trim($_POST['smtp_from'] ?? ''));
        setSiteSetting('smtp_from_name', trim($_POST['smtp_from_name'] ?? '') ?: siteName());
        setSiteSetting('smtp_verify_cert', isset($_POST['smtp_verify_cert']) ? '1' : '0');
        $success = 'Configurazione SMTP salvata.';
    } elseif ($action === 'test') {
        $testEmail = trim($_POST['test_email'] ?? '');
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $testResult = ['ok' => false, 'msg' => 'Inserisci un indirizzo email valido per il test.'];
        } else {
            $cfg = getSmtpConfig();
            if (!$cfg['host']) {
                $testResult = ['ok' => false, 'msg' => 'Nessun host SMTP configurato: salva prima la configurazione.'];
            } else {
                require_once __DIR__ . '/../src/mailer.php';
                $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);
                $sent = $mailer->send(
                    $cfg['from'], $cfg['fromName'], $testEmail, $testEmail,
                    'Email di prova da ' . siteName(),
                    "Questa è un'email di prova per verificare la configurazione SMTP di " . siteName() . ".\n\nSe la ricevi, la configurazione funziona correttamente."
                );
                $testResult = $sent
                    ? ['ok' => true, 'msg' => "Email di prova inviata a {$testEmail}. Controlla la casella (anche lo spam)."]
                    : ['ok' => false, 'msg' => 'Invio fallito. Controlla i log del container chifacosa_app per il dettaglio tecnico (cerca [SimpleSmtpMailer]).'];
            }
        }
    }
}

$cfg = getSmtpConfig();

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Configura qui le credenziali SMTP del tuo provider (es. SendPulse) per attivare le email di
      conferma registrazione e le notifiche dei messaggi di contatto. Non serve più modificare
      variabili d'ambiente su Portainer: tutto si gestisce da questa pagina.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">

    <label>Host SMTP</label>
    <input type="text" name="smtp_host" value="<?= e($cfg['host']) ?>" placeholder="es. smtp-pulse.com">

    <label>Porta</label>
    <input type="text" name="smtp_port" value="<?= e((string)$cfg['port']) ?>" placeholder="587">

    <label>Tipo di connessione</label>
    <select name="smtp_secure">
      <option value="tls" <?= $cfg['secure']==='tls'?'selected':'' ?>>TLS (porta 587, la più comune)</option>
      <option value="ssl" <?= $cfg['secure']==='ssl'?'selected':'' ?>>SSL (porta 465)</option>
      <option value="" <?= $cfg['secure']===''?'selected':'' ?>>Nessuna cifratura</option>
    </select>

    <label>Username SMTP</label>
    <input type="text" name="smtp_user" value="<?= e($cfg['user']) ?>" placeholder="login fornito dal provider">

    <label>Password SMTP</label>
    <input type="password" name="smtp_pass" placeholder="<?= $cfg['pass'] !== '' ? '••••••••  (lascia vuoto per non modificarla)' : 'inserisci la password' ?>">

    <label>Email mittente (From)</label>
    <input type="text" name="smtp_from" value="<?= e($cfg['from']) ?>" placeholder="es. noreply@tuodominio.it">

    <label>Nome mittente</label>
    <input type="text" name="smtp_from_name" value="<?= e($cfg['fromName']) ?>" placeholder="<?= e(siteName()) ?>">

    <label style="display:flex;align-items:center;gap:8px;">
      <input type="checkbox" name="smtp_verify_cert" value="1" style="width:auto;" <?= $cfg['verifyCert'] ? 'checked' : '' ?>>
      Verifica il certificato SSL del server (disattiva solo se il tuo hosting usa un hostname
      personalizzato che non corrisponde al nome nel certificato, es. molti server Aruba condivisi)
    </label>

    <button type="submit" class="btn">Salva configurazione</button>
  </form>

  <div class="card">
    <strong>Test invio email</strong>
    <p style="color:var(--text-muted)">Invia un'email di prova per verificare che la configurazione funzioni.</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <label>Invia email di prova a</label>
      <input type="email" name="test_email" placeholder="tua-email@esempio.it" required>
      <button type="submit" class="btn secondary">Invia prova</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
