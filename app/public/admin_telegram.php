<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/telegram.php';
$admin = requireAdmin();
$activeAdminTab = 'telegram';
$pageTitle = 'Telegram';
$success = null;
$testResult = null;
$webhookResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('telegram_bot_token', trim($_POST['telegram_bot_token'] ?? ''));
        setSiteSetting('telegram_webhook_secret', trim($_POST['telegram_webhook_secret'] ?? ''));
        $success = 'Configurazione Telegram salvata.';
    } elseif ($action === 'test') {
        try {
            $me = telegramGetMe();
            $testResult = $me
                ? ['ok' => true, 'msg' => 'Connessione riuscita! Bot: @' . ($me['username'] ?? '?') . ' (' . ($me['first_name'] ?? '') . ')']
                : ['ok' => false, 'msg' => 'Connessione fallita. Controlla il Bot Token.'];
        } catch (\Throwable $e) {
            $testResult = ['ok' => false, 'msg' => 'Errore di connessione: ' . $e->getMessage()];
        }
    } elseif ($action === 'setup_webhook') {
        try {
            $webhookUrl = siteUrl('webhook/telegram');
            $secret = getSiteSetting('telegram_webhook_secret') ?: null;
            $result = telegramSetWebhook($webhookUrl, $secret);
            $webhookResult = $result !== null
                ? ['ok' => true, 'msg' => 'Webhook registrato: ' . $webhookUrl]
                : ['ok' => false, 'msg' => 'Registrazione webhook fallita. Verifica che il sito sia raggiungibile via HTTPS.'];
        } catch (\Throwable $e) {
            $webhookResult = ['ok' => false, 'msg' => 'Errore: ' . $e->getMessage()];
        }
    } elseif ($action === 'delete_webhook') {
        try {
            $result = telegramDeleteWebhook();
            $webhookResult = $result !== null
                ? ['ok' => true, 'msg' => 'Webhook rimosso.']
                : ['ok' => false, 'msg' => 'Rimozione webhook fallita.'];
        } catch (\Throwable $e) {
            $webhookResult = ['ok' => false, 'msg' => 'Errore: ' . $e->getMessage()];
        }
    }
}

$botToken = getSiteSetting('telegram_bot_token') ?: '';
$webhookSecret = getSiteSetting('telegram_webhook_secret') ?: '';
$webhookInfo = null;
try {
    if (getTelegramBotToken()) {
        $webhookInfo = telegramGetWebhookInfo();
    }
} catch (\Throwable $e) {
    // Ignora errori API
}

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>
  <?php if ($webhookResult): ?>
    <div class="alert <?= $webhookResult['ok'] ? 'success' : 'error' ?>"><?= e($webhookResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Ogni profilo può collegare il proprio account Telegram personale per <strong>inviare foto e testi
      al bot</strong> e pubblicarli direttamente sulla Timeline o sul Blog — come Instagram, ma via Telegram.
    </p>
    <p style="color:var(--text-muted)">
      Opzionalmente, possono anche collegare un canale/gruppo per la <strong>pubblicazione automatica</strong>:
      ogni nuovo contenuto dal sito viene inviato anche lì.
    </p>
    <p style="color:var(--text-muted)">
      Per creare un bot Telegram: apri <a href="https://t.me/BotFather" target="_blank">@BotFather</a>
      su Telegram, invia <code>/newbot</code>, segui le istruzioni e copia il <strong>Bot Token</strong>
      qui sotto. Dopo aver salvato, clicca <strong>Registra webhook</strong> per attivare la ricezione dei messaggi.
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
    <p style="color:var(--text-muted)">Verifica che il Bot Token funzioni.</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>

  <div class="card">
    <strong>Webhook (ricezione messaggi)</strong>
    <?php if ($webhookInfo): ?>
      <?php $currentUrl = $webhookInfo['url'] ?? ''; ?>
      <?php if ($currentUrl): ?>
        <p style="color:var(--text-muted)">
          URL attuale: <code><?= e($currentUrl) ?></code><br>
          <?php if (!empty($webhookInfo['last_error_message'])): ?>
            <span style="color:#e74c3c;">Ultimo errore: <?= e($webhookInfo['last_error_message']) ?></span>
          <?php else: ?>
            <span style="color:#27ae60;">Nessun errore recente.</span>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p style="color:var(--text-muted)">Nessun webhook configurato. Clicca sotto per attivare la ricezione dei messaggi.</p>
      <?php endif; ?>
    <?php else: ?>
      <p style="color:var(--text-muted)">Configura prima il Bot Token.</p>
    <?php endif; ?>
    <div style="display:flex;gap:8px;margin-top:8px;">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="setup_webhook">
        <button type="submit" class="btn">Registra webhook</button>
      </form>
      <?php if ($webhookInfo && !empty($webhookInfo['url'])): ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_webhook">
          <button type="submit" class="btn danger">Rimuovi webhook</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
