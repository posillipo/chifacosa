<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/youtube.php';
$admin = requireAdmin();
$activeAdminTab = 'youtube';
$pageTitle = 'YouTube';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('youtube_api_key', trim($_POST['youtube_api_key'] ?? ''));
        $success = 'Chiave API YouTube salvata.';
    } elseif ($action === 'test') {
        $testChannel = youtubeResolveChannel('https://www.youtube.com/@YouTube');
        $testResult = $testChannel
            ? ['ok' => true, 'msg' => 'Connessione a YouTube riuscita: le credenziali funzionano.']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla la API Key, o i log del container chifacosa_app.'];
    }
}

$apiKey = getSiteSetting('youtube_api_key') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Permette a ogni band manager di collegare (dalla propria dashboard) il proprio canale
      YouTube incollando semplicemente il link, mostrando automaticamente i video più recenti su
      una pagina pubblica dedicata (<code><?= e(siteName()) ?>/slug/video</code>). Non richiede il login
      YouTube/Google degli utenti: usa solo l'accesso al catalogo pubblico tramite una API Key.
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere la chiave: vai su <a href="https://console.cloud.google.com/" target="_blank">console.cloud.google.com</a>,
      crea un progetto (gratuito), attiva l'API <strong>YouTube Data API v3</strong>, poi crea
      una <strong>API Key</strong> dalle credenziali del progetto.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>YouTube API Key</label>
    <input type="text" name="youtube_api_key" value="<?= e($apiKey) ?>" placeholder="es. AIzaSy...">
    <button type="submit" class="btn">Salva chiave</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che la chiave funzioni (interroga un canale pubblico di prova).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
