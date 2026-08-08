<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'import_legacy';
$pageTitle = 'Import dati legacy';

const CSV_PATH = '/var/www/import/legacy_import_ready.csv';
const BATCH_SIZE = 150; // righe elaborate per singola richiesta, per non superare i timeout

// Conta il totale delle righe dati nel CSV (una sola volta, per la barra di avanzamento)
function countCsvRows(string $path): int {
    $count = 0;
    $handle = fopen($path, 'r');
    fgetcsv($handle); // salta l'intestazione
    while (fgetcsv($handle) !== false) {
        $count++;
    }
    fclose($handle);
    return $count;
}

// Elabora un lotto di righe a partire da $offset (0-based, righe dati, intestazione esclusa).
function processBatch(string $path, int $offset, int $batchSize): array {
    $db = getDB();
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle);

    for ($i = 0; $i < $offset; $i++) {
        if (fgetcsv($handle) === false) {
            fclose($handle);
            return ['done' => true, 'newOffset' => $offset, 'imported' => 0, 'skipped' => 0, 'errors' => []];
        }
    }

    $imported = 0;
    $skipped = 0;
    $errors = [];
    $processedInBatch = 0;
    $reachedEnd = false;

    while ($processedInBatch < $batchSize) {
        $row = fgetcsv($handle);
        if ($row === false) {
            $reachedEnd = true;
            break;
        }
        $processedInBatch++;

        if (count($row) !== count($header)) {
            $skipped++;
            continue;
        }
        $data = array_combine($header, $row);
        $legacyGestoreId = (int) $data['legacy_gestore_id'];

        $stmt = $db->prepare('SELECT id FROM users WHERE legacy_gestore_id = ?');
        $stmt->execute([$legacyGestoreId]);
        if ($stmt->fetch()) {
            $skipped++;
            continue;
        }

        $email = strtolower(trim($data['email']));
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $skipped++;
            continue;
        }

        $slug = trim($data['slug']);
        $slug = mb_substr($slug, 0, 50); // margine di sicurezza per un eventuale suffisso
        $stmt = $db->prepare('SELECT id FROM users WHERE slug = ?');
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            $suffix = '-' . $legacyGestoreId;
            $slug = mb_substr($slug, 0, 60 - mb_strlen($suffix)) . $suffix;
        }

        try {
            $db->beginTransaction();
            $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

            $createdAt = date('Y-m-d H:i:s');
            if (!empty($data['created_at'])) {
                $ts = strtotime($data['created_at']);
                if ($ts) {
                    $createdAt = date('Y-m-d H:i:s', $ts);
                }
            }

            $stmt = $db->prepare('INSERT INTO users
                (slug, email, password_hash, is_active, is_admin, email_verified, legacy_gestore_id, legacy_band_id, legacy_stato, created_at)
                VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?, ?)');
            // Chi risultava "OK" nel vecchio sistema aveva già confermato/attivato la propria
            // registrazione a suo tempo: lo trattiamo come email già verificata, non da rifare
            // da capo. Tutti gli altri stati (no, ATTESA, ELIMINATO, ecc.) restano da verificare.
            $emailVerified = (trim((string)($data['legacy_stato'] ?? '')) === 'OK') ? 1 : 0;
            $stmt->execute([$slug, $email, $randomPassword, $emailVerified, $legacyGestoreId, (int)$data['legacy_band_id'], $data['legacy_stato'] ?: null, $createdAt]);
            $userId = (int) $db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO profiles (user_id, display_name, bio, genere, citta, provincia, telefono) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $data['display_name'], $data['bio'] ?: null, $data['genere'] ?: null, $data['citta'] ?: null, $data['provincia'] ?: null, $data['telefono'] ?: null]);

            if (!empty($data['sito_ufficiale']) && filter_var($data['sito_ufficiale'], FILTER_VALIDATE_URL)) {
                $stmt = $db->prepare('INSERT INTO links (user_id, label, url, sort_order) VALUES (?, ?, ?, 1)');
                $stmt->execute([$userId, 'Sito ufficiale', $data['sito_ufficiale']]);
            }

            $db->commit();
            $imported++;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "Gestore {$legacyGestoreId}: " . $e->getMessage();
        }
    }
    fclose($handle);

    return [
        'done' => $reachedEnd,
        'newOffset' => $offset + $processedInBatch,
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

$fileOk = is_readable(CSV_PATH);
$batchResult = null;

$isPostStart = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_import';
$isGetContinue = isset($_GET['run']) && isset($_GET['csrf']);

$started = false;
if ($isPostStart) {
    checkCsrf(); // form classico: controlla $_POST['csrf']
    $started = true;
} elseif ($isGetContinue) {
    // La prosecuzione automatica arriva come link (GET), non come form: verifichiamo il
    // token CSRF manualmente dalla query string invece che dal corpo POST.
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_GET['csrf'])) {
        http_response_code(403);
        exit('Richiesta non valida (CSRF).');
    }
    $started = true;
}

if ($fileOk && $started) {
    if (!isset($_SESSION['legacy_import'])) {
        $_SESSION['legacy_import'] = [
            'total' => countCsvRows(CSV_PATH),
            'offset' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    $state = &$_SESSION['legacy_import'];
    $batchResult = processBatch(CSV_PATH, $state['offset'], BATCH_SIZE);

    $state['offset'] = $batchResult['newOffset'];
    $state['imported'] += $batchResult['imported'];
    $state['skipped'] += $batchResult['skipped'];
    $state['errors'] = array_merge($state['errors'], $batchResult['errors']);
}

$state = $_SESSION['legacy_import'] ?? null;
$stmt = getDB()->query('SELECT COUNT(*) c FROM users WHERE legacy_gestore_id IS NOT NULL');
$alreadyImported = (int) $stmt->fetch()['c'];

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <strong>Cosa fa questa importazione</strong>
    <p style="color:var(--text-muted)">
      Importa i profili band dal vecchio database (1.835 record, uno per gestore). Ogni account
      viene creato con <strong>is_active = 0</strong> (non visibile pubblicamente, non può fare
      login) finché non deciderai come attivarli. Procede automaticamente a lotti di
      <?= BATCH_SIZE ?> righe per volta (evita i timeout), senza bisogno di ricaricare
      manualmente la pagina.
    </p>
  </div>

  <div class="card">
    <strong>Account già importati in totale (anche da run precedenti):</strong> <?= $alreadyImported ?>
  </div>

  <?php if (!$fileOk): ?>
    <div class="alert error">File CSV non trovato in <?= e(CSV_PATH) ?> — verifica che il redeploy sia andato a buon fine.</div>
  <?php elseif ($state && $batchResult && !$batchResult['done']): ?>
    <div class="card">
      <strong>Importazione in corso...</strong>
      <p>
        <?= $state['offset'] ?> / <?= $state['total'] ?> righe elaborate
        (<?= $state['total'] > 0 ? round($state['offset'] / $state['total'] * 100) : 0 ?>%)
        — <?= $state['imported'] ?> account creati finora, <?= $state['skipped'] ?> saltati
      </p>
      <div style="background:#26262f;border-radius:8px;overflow:hidden;height:14px;">
        <div style="background:var(--accent);height:100%;width:<?= $state['total'] > 0 ? round($state['offset'] / $state['total'] * 100) : 0 ?>%;"></div>
      </div>
      <p style="color:var(--text-muted);font-size:13px;">
        Questa pagina si aggiorna automaticamente tra un secondo — non chiuderla e non serve
        premere nulla.
      </p>
    </div>
    <script>
      setTimeout(function () {
        window.location.href = '/admin_import_legacy.php?run=1&csrf=<?= urlencode(csrfToken()) ?>';
      }, 800);
    </script>
  <?php elseif ($state && $batchResult && $batchResult['done']): ?>
    <div class="alert success">
      Importazione completata: <strong><?= $state['imported'] ?></strong> account creati,
      <?= $state['skipped'] ?> righe saltate (già importate in precedenza, email duplicata, o
      malformate).
    </div>
    <?php if ($state['errors']): ?>
      <div class="card">
        <strong>Errori riscontrati (<?= count($state['errors']) ?>)</strong>
        <details>
          <summary>Vedi dettaglio</summary>
          <pre style="white-space:pre-wrap;font-size:12px;"><?= e(implode("\n", array_slice($state['errors'], 0, 50))) ?></pre>
        </details>
      </div>
    <?php endif; ?>
    <?php unset($_SESSION['legacy_import']); ?>
  <?php else: ?>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="start_import">
      <button type="submit" class="btn" onclick="return confirm('Avviare l\'importazione dei dati legacy?');">
        Esegui importazione
      </button>
    </form>
  <?php endif; ?>

  <div class="card" style="margin-top:20px;">
    <strong>Passo successivo, dopo l'import</strong>
    <p style="color:var(--text-muted)">
      Sostituisce lo slug di ogni account importato con il vecchio campo "percorso", per far
      coincidere gli URL con la struttura multimediale storica.
    </p>
    <a href="/admin_apply_percorso.php" class="btn btn-secondary">Applica percorso come slug →</a>
    <a href="/admin_link_avatars.php" class="btn btn-secondary">Collega avatar storici →</a>
    <a href="/admin_import_old_timeline.php" class="btn btn-secondary">Importa vecchia Timeline →</a>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
