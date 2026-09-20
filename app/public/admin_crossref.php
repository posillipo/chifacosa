<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/crossref.php';
$admin = requireAdmin();
$activeAdminTab = 'crossref';
$pageTitle = 'CrossRef (Pubblicazioni)';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('crossref_contact_email', trim($_POST['crossref_contact_email'] ?? ''));
        $success = 'Impostazioni CrossRef salvate.';
    } elseif ($action === 'test') {
        $testResults = crossrefSearch('CRISPR gene editing');
        $testResult = $testResults
            ? ['ok' => true, 'msg' => 'Connessione a CrossRef riuscita: trovato "' . $testResults[0]['title'] . '".']
            : ['ok' => false, 'msg' => 'Connessione fallita. Riprova tra poco, o controlla i log del container chifacosa_app.'];
    }
}

$contactEmail = getSiteSetting('crossref_contact_email') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Abilita il modulo "Pubblicazioni che amo" nella dashboard: chi lo gestisce può cercare
      pubblicazioni scientifiche/accademiche (articoli di riviste, atti di convegni...) su
      <strong>CrossRef</strong> — il registro ufficiale dei DOI, con oltre 150 milioni di
      pubblicazioni indicizzate da praticamente tutti gli editori accademici — e aggiungerle alla
      propria lista, mostrata poi sulla pagina pubblica del profilo. Stesso principio già usato
      per "Libri che amo" (Google Books).
    </p>
    <p style="color:var(--text-muted)">
      <strong>Nessuna chiave API richiesta</strong>: CrossRef è un servizio pubblico gratuito.
      L'unico campo qui sotto è facoltativo.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>Email di contatto (facoltativa)</label>
    <input type="text" name="crossref_contact_email" value="<?= e($contactEmail) ?>" placeholder="es. admin@chifacosa.it">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">
      CrossRef la chiama "polite pool": non serve per autenticarsi, ma segnalare un contatto dà
      priorità e limiti di frequenza migliori rispetto alle richieste anonime. Consigliata ma non
      obbligatoria.
    </p>
    <button type="submit" class="btn">Salva</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che il servizio risponda (cerca una pubblicazione di prova).</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
