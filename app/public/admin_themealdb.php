<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/themealdb.php';
$admin = requireAdmin();
$activeAdminTab = 'themealdb';
$pageTitle = 'TheMealDB (Ricette)';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('themealdb_api_key', trim($_POST['themealdb_api_key'] ?? ''));
        $success = 'Chiave API TheMealDB salvata.';
    } elseif ($action === 'test') {
        $testResults = themealdbSearchRecipe('pasta');
        $testResult = $testResults
            ? ['ok' => true, 'msg' => 'Connessione a TheMealDB riuscita: trovata "' . $testResults[0]['title'] . '".']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla la API Key, o i log del container chifacosa_app.'];
    }
}

$apiKey = getSiteSetting('themealdb_api_key') ?: '';

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
      tutto il catalogo TheMealDB) e aggiungerle alla propria lista, mostrata poi sulla pagina
      pubblica del profilo — stesso principio già usato per "Attori che amo" con TMDb.
    </p>
    <p style="color:var(--text-muted)">
      Funziona subito senza fare nulla: <code>1</code> è la chiave di test pubblica, sempre valida
      per l'uso base, già impostata di default se lasci il campo vuoto. Se in futuro dovesse
      diventare troppo limitata, una chiave personale gratuita si ottiene su
      <a href="https://www.patreon.com/themealdb" target="_blank">patreon.com/themealdb</a>.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>TheMealDB API Key</label>
    <input type="text" name="themealdb_api_key" value="<?= e($apiKey) ?>" placeholder="es. 1 (chiave di test, va bene per iniziare)">
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
