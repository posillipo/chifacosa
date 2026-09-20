<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'turnstile';
$pageTitle = 'Antispam (Cloudflare Turnstile)';
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    setSiteSetting('turnstile_site_key', trim($_POST['turnstile_site_key'] ?? ''));
    setSiteSetting('turnstile_secret_key', trim($_POST['turnstile_secret_key'] ?? ''));
    $success = 'Impostazioni Antispam salvate.';
}

$siteKey = getSiteSetting('turnstile_site_key') ?: '';
$secretKey = getSiteSetting('turnstile_secret_key') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      <strong>Cloudflare Turnstile</strong> è un CAPTCHA gratuito e invisibile nella maggior parte
      dei casi (non richiede di selezionare semafori o strisce pedonali): protegge dallo spam
      automatizzato i form pubblici del sito, senza bisogno di spostare il dominio su Cloudflare.
    </p>
    <p style="color:var(--text-muted)">
      Appena salvi le due chiavi qui sotto, il widget compare automaticamente su tutti i form
      pubblici protetti: <strong>Contatti</strong>, <strong>Richiedi informazioni</strong> (Servizi),
      <strong>Prenota un tavolo</strong> (Eventi), <strong>Attiva il preconto</strong> (Menù) e
      <strong>Segui via email</strong>. Finché i campi restano vuoti, questi form continuano a
      funzionare esattamente come oggi — nessun rischio di blocco con una configurazione a metà.
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere le chiavi: vai su <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank">dash.cloudflare.com</a>
      (basta un account gratuito, non serve spostare il dominio), crea un nuovo widget di tipo
      "Managed", imposta come dominio <code><?= e($_SERVER['HTTP_HOST'] ?? 'chifacosa.it') ?></code>
      e copia qui sotto la <strong>Site Key</strong> e la <strong>Secret Key</strong> generate.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label>Site Key (pubblica)</label>
    <input type="text" name="turnstile_site_key" value="<?= e($siteKey) ?>" placeholder="es. 0x4AAAAAAA...">
    <label>Secret Key (segreta)</label>
    <input type="text" name="turnstile_secret_key" value="<?= e($secretKey) ?>" placeholder="es. 0x4AAAAAAA...">
    <button type="submit" class="btn">Salva</button>
  </form>

  <div class="card">
    <strong>Stato attuale</strong>
    <p style="color:var(--text-muted)">
      <?php if ($siteKey && $secretKey): ?>
        ✅ Antispam <strong>attivo</strong> su tutti i form pubblici protetti.
      <?php else: ?>
        ⚪ Antispam <strong>non ancora attivo</strong> — compila entrambe le chiavi sopra per attivarlo.
      <?php endif; ?>
    </p>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
