<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/telegram.php';
$admin = requireAdmin();
$activeAdminTab = 'telegram';
$pageTitle = 'Telegram';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('telegram_bot_token', trim($_POST['telegram_bot_token'] ?? ''));
        setSiteSetting('telegram_webhook_secret', trim($_POST['telegram_webhook_secret'] ?? ''));
        $success = 'Configurazione Telegram salvata.';
    } elseif ($action === 'test') {
        $me = telegramGetMe();
        $testResult = $me
            ? ['ok' => true, 'msg' => 'Connessione riuscita! Bot: @' . ($me['username'] ?? '?') . ' (' . ($me['first_name'] ?? '') . ')']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla il Bot Token.'];
    }
}

$botToken = getSiteSetting('telegram_bot_token') ?: '';
$webhookSecret = getSiteSetting('telegram_webhook_secret') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Permette a ogni profilo di collegare (dalla propria dashboard) un canale o gruppo Telegram.
      Quando pubblicano un nuovo contenuto (post Timeline o articolo Blog), viene inviato
      automaticamente anche sul canale Telegram collegato — con immagine, testo e link alla
      pagina pubblica.
    </p>
    <p style="color:var(--text-muted)">
      Per creare un bot Telegram: apri <a href="https://t.me/BotFather" target="_blank">@BotFather</a>
      su Telegram, invia <code>/newbot</code>, segui le istruzioni e copia il <strong>Bot Token</strong>
      qui sotto. Poi aggiungi il bot come amministratore nel canale/gruppo che ogni profilo vuole collegare.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>Bot Token</label>
    <input type="text" name="telegram_bot_token" value="<?= e($botToken) ?>" placeholder="es. 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">
    <label>Webhook Secret <span style="color:var(--text-muted);font-size:.85em;">(opzionale, per verificare le richieste in arrivo)</span></label>
    <input type="text" name="telegram_webhook_secret" value="<?= e($webhookSecret) ?>" placeholder="una stringa segreta a scelta">
    <button type="submit" class="btn">Salva configurazione</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che il Bot Token funzioni (interroga l'API <code>getMe</code>).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
