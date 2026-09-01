<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/gemini.php';
$admin = requireAdmin();
$activeAdminTab = 'gemini';
$pageTitle = 'Assistente AI (Gemini)';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('gemini_api_key', trim($_POST['gemini_api_key'] ?? ''));
        $success = 'Chiave API Gemini salvata.';
    } elseif ($action === 'test') {
        $testText = geminiGenerateText('Scrivi un saluto di una frase per testare una connessione API. Rispondi solo con la frase, senza commenti.');
        $testResult = $testText
            ? ['ok' => true, 'msg' => 'Connessione a Gemini riuscita: "' . $testText . '"']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla la API Key, o i log del container chifacosa_app.'];
    }
}

$apiKey = getSiteSetting('gemini_api_key') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Abilita il pulsante "Genera con AI" nel modulo Timeline della dashboard: chi pubblica un
      aggiornamento può farsi suggerire un testo a partire da qualche parola chiave, invece di
      scriverlo da zero. Usa Google Gemini (livello gratuito, nessuna carta di credito richiesta)
      con un'unica chiave condivisa da tutti i profili.
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere la chiave: vai su <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com/apikey</a>,
      accedi con un account Google e clicca <strong>"Create API key"</strong> — è gratuita e non
      richiede la configurazione di un progetto Cloud come per YouTube.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>Gemini API Key</label>
    <input type="text" name="gemini_api_key" value="<?= e($apiKey) ?>" placeholder="es. AIzaSy...">
    <button type="submit" class="btn">Salva chiave</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che la chiave funzioni (genera una breve frase di prova).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
