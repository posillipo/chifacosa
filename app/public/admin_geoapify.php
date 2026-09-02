<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/geoapify.php';
$admin = requireAdmin();
$activeAdminTab = 'geoapify';
$pageTitle = 'Geoapify (Viaggi)';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('geoapify_api_key', trim($_POST['geoapify_api_key'] ?? ''));
        $success = 'Chiave API Geoapify salvata.';
    } elseif ($action === 'test') {
        // Roma, Colosseo — coordinate di prova fisse, solo per verificare che la chiave funzioni.
        $testMap = geoapifyGenerateStaticMap(41.8902, 12.4922, '_test_geoapify');
        if ($testMap) {
            @unlink('/var/www/html/' . $testMap);
            $testResult = ['ok' => true, 'msg' => 'Connessione a Geoapify riuscita: mappa di prova generata correttamente.'];
        } else {
            $testResult = ['ok' => false, 'msg' => 'Connessione fallita. Controlla la API Key, o i log del container chifacosa_app.'];
        }
    }
}

$apiKey = getSiteSetting('geoapify_api_key') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Abilita le immagini di anteprima del modulo "Viaggi": la ricerca del luogo resta gratuita su
      OpenStreetMap (nessuna chiave necessaria), ma per condividere un viaggio sui social serve
      sempre un'immagine — se chi gestisce il profilo non carica una propria foto del posto, viene
      generata automaticamente una miniatura della mappa con Geoapify.
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere la chiave: vai su <a href="https://www.geoapify.com/" target="_blank">geoapify.com</a>,
      registrati gratis (basta un'email, nessuna carta richiesta) e copia la API Key dalla tua
      dashboard. Piano gratuito: circa 3000 richieste al giorno.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>Geoapify API Key</label>
    <input type="text" name="geoapify_api_key" value="<?= e($apiKey) ?>" placeholder="es. 0123456789abcdef...">
    <button type="submit" class="btn">Salva chiave</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che la chiave funzioni (genera una mappa di prova, poi la elimina).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
