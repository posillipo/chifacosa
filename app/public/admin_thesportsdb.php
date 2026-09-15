<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/thesportsdb.php';
$admin = requireAdmin();
$activeAdminTab = 'thesportsdb';
$pageTitle = 'TheSportsDB (Squadre)';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('thesportsdb_api_key', trim($_POST['thesportsdb_api_key'] ?? ''));
        $success = 'Chiave API TheSportsDB salvata.';
    } elseif ($action === 'test') {
        $testResults = thesportsdbSearchTeam('Arsenal');
        $testResult = $testResults
            ? ['ok' => true, 'msg' => 'Connessione a TheSportsDB riuscita: trovata "' . $testResults[0]['title'] . '".']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla la API Key, o i log del container chifacosa_app.'];
    }
}

$apiKey = getSiteSetting('thesportsdb_api_key') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Abilita il modulo "Squadre che amo" nella dashboard: chi lo gestisce può cercare squadre
      sportive (su tutto il catalogo TheSportsDB) e aggiungerle alla propria lista, mostrata poi
      sulla pagina pubblica del profilo — stesso principio già usato per "Attori che amo" con TMDb.
    </p>
    <p style="color:var(--text-muted)">
      Per provare subito senza registrarti: usa <code>3</code>, la chiave di test pubblica
      (limitata, condivisa con tutti). Per un uso stabile, ottieni una chiave personale gratuita
      su <a href="https://www.patreon.com/sportsdb" target="_blank">patreon.com/sportsdb</a>.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>TheSportsDB API Key</label>
    <input type="text" name="thesportsdb_api_key" value="<?= e($apiKey) ?>" placeholder="es. 3 (chiave di test)">
    <button type="submit" class="btn">Salva chiave</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che la chiave funzioni (cerca una squadra di prova).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
