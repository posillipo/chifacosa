<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/googlebooks.php';
$admin = requireAdmin();
$activeAdminTab = 'googlebooks';
$pageTitle = 'Google Books (Libri)';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('google_books_api_key', trim($_POST['google_books_api_key'] ?? ''));
        $success = 'Chiave API Google Books salvata.';
    } elseif ($action === 'test') {
        $testResults = googleBooksSearch('Il nome della rosa');
        $testResult = $testResults
            ? ['ok' => true, 'msg' => 'Connessione a Google Books riuscita: trovato "' . $testResults[0]['title'] . '".']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla la API Key, o i log del container chifacosa_app.'];
    }
}

$apiKey = getSiteSetting('google_books_api_key') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Abilita il modulo "Libri che amo" nella dashboard: chi lo gestisce può cercare libri (su
      tutto il catalogo Google Books, non solo titoli già presenti su <?= e(siteName()) ?>) e
      aggiungerli alla propria lista, mostrata poi sulla pagina pubblica del profilo — stesso
      principio già usato per "Band che amo" (Spotify) e "Attori/Film che amo" (TMDb).
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere la chiave: vai su <a href="https://console.cloud.google.com/apis/library/books.googleapis.com" target="_blank">console.cloud.google.com</a>,
      crea un progetto gratuito, abilita la "Books API" e crea una credenziale di tipo
      "Chiave API". Gratuita fino a 1000 richieste al giorno.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>Google Books API Key</label>
    <input type="text" name="google_books_api_key" value="<?= e($apiKey) ?>" placeholder="es. AIzaSy...">
    <button type="submit" class="btn">Salva chiave</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che la chiave funzioni (cerca un libro di prova).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
