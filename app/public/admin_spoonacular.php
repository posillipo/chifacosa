<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spoonacular.php';
$admin = requireAdmin();
$activeAdminTab = 'spoonacular';
$pageTitle = 'Spoonacular (Ricette)';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('spoonacular_api_key', trim($_POST['spoonacular_api_key'] ?? ''));
        $success = 'Chiave API Spoonacular salvata.';
    } elseif ($action === 'test') {
        $testResults = spoonacularSearchRecipe('pasta');
        $testResult = $testResults
            ? ['ok' => true, 'msg' => 'Connessione a Spoonacular riuscita: trovata "' . $testResults[0]['name'] . '".']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla la API Key, o i log del container chifacosa_app.'];
    }
}

$apiKey = getSiteSetting('spoonacular_api_key') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Abilita il modulo "Ricette che amo" nella dashboard: chi lo gestisce può cercare ricette (su
      tutto il catalogo Spoonacular) e aggiungerle alla propria lista, mostrata poi sulla pagina
      pubblica del profilo — stesso principio già usato per "Attori che amo" con TMDb.
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere la chiave: vai su <a href="https://spoonacular.com/food-api/console#Dashboard" target="_blank">spoonacular.com/food-api/console</a>,
      crea un account gratuito (150 richieste/giorno incluse) e copia la tua API Key dalla dashboard.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>Spoonacular API Key</label>
    <input type="text" name="spoonacular_api_key" value="<?= e($apiKey) ?>" placeholder="es. a1b2c3d4e5f6...">
    <button type="submit" class="btn">Salva chiave</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che la chiave funzioni (cerca una ricetta di prova).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
