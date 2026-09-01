<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/tmdb.php';
$admin = requireAdmin();
$activeAdminTab = 'tmdb';
$pageTitle = 'TMDb (Attori)';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('tmdb_api_key', trim($_POST['tmdb_api_key'] ?? ''));
        $success = 'Chiave API TMDb salvata.';
    } elseif ($action === 'test') {
        $testResults = tmdbSearchPerson('Tom Hanks');
        $testResult = $testResults
            ? ['ok' => true, 'msg' => 'Connessione a TMDb riuscita: trovato "' . $testResults[0]['name'] . '".']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla la API Key, o i log del container chifacosa_app.'];
    }
}

$apiKey = getSiteSetting('tmdb_api_key') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Abilita il modulo "Attori che amo" nella dashboard: chi lo gestisce può cercare attori e
      attrici (su tutto il catalogo TMDb, non solo persone già presenti su <?= e(siteName()) ?>) e
      aggiungerli alla propria lista, mostrata poi sulla pagina pubblica del profilo — stesso
      principio già usato per "Band che amo" con Spotify.
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere la chiave: vai su <a href="https://www.themoviedb.org/settings/api" target="_blank">themoviedb.org/settings/api</a>,
      crea un account gratuito e richiedi una API Key di tipo "Developer" — usa quella
      etichettata <strong>"API Key (v3 auth)"</strong>.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>TMDb API Key (v3 auth)</label>
    <input type="text" name="tmdb_api_key" value="<?= e($apiKey) ?>" placeholder="es. a1b2c3d4e5f6...">
    <button type="submit" class="btn">Salva chiave</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che la chiave funzioni (cerca un attore di prova).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
