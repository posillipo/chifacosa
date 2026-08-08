<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'import_legacy';
$pageTitle = 'Collega avatar storici';

const CSV_PATH = '/var/www/import/legacy_avatar_link.csv';
const UPLOADS_BASE = '/var/www/html/uploads/images/';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    checkCsrf();

    if (!is_readable(CSV_PATH)) {
        $result = ['error' => 'File CSV non trovato in ' . CSV_PATH . ' — verifica che il redeploy sia andato a buon fine.'];
    } else {
        $db = getDB();
        $handle = fopen(CSV_PATH, 'r');
        $header = fgetcsv($handle);

        $linked = 0;
        $skippedNoMatch = 0;
        $skippedFileMissing = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }
            $data = array_combine($header, $row);
            $legacyGestoreId = (int) $data['legacy_gestore_id'];
            $percorso = trim($data['percorso']);
            $img = trim($data['img']);

            $stmt = $db->prepare('SELECT id, slug FROM users WHERE legacy_gestore_id = ?');
            $stmt->execute([$legacyGestoreId]);
            $user = $stmt->fetch();
            if (!$user) {
                $skippedNoMatch++;
                continue;
            }

            // Il file fisico deve trovarsi nella cartella dello slug ATTUALE dell'utente
            // (dopo "Applica percorso come slug" dovrebbe coincidere col vecchio percorso)
            $physicalPath = UPLOADS_BASE . $user['slug'] . '/' . $img;
            if (!is_file($physicalPath)) {
                $skippedFileMissing++;
                $errors[] = "Gestore {$legacyGestoreId} (slug '{$user['slug']}'): file '{$img}' non trovato in {$physicalPath}";
                continue;
            }

            $relativePath = 'uploads/images/' . $user['slug'] . '/' . $img;
            $stmt = $db->prepare('UPDATE profiles SET avatar_path = ? WHERE user_id = ?');
            $stmt->execute([$relativePath, $user['id']]);
            $linked++;
        }
        fclose($handle);

        $result = [
            'linked' => $linked,
            'skippedNoMatch' => $skippedNoMatch,
            'skippedFileMissing' => $skippedFileMissing,
            'errors' => $errors,
        ];
    }
}

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <strong>Cosa fa questa operazione</strong>
    <p style="color:var(--text-muted)">
      Legge il vecchio campo <code>img</code> per ogni band (1.300 su 1.835 lo avevano
      valorizzato) e imposta <code>profiles.avatar_path</code> puntando al file corrispondente,
      <strong>solo se quel file esiste fisicamente</strong> nella cartella
      <code>uploads/images/{slug}/</code> — se manca, la riga viene saltata e segnalata, non
      genera un link rotto.
    </p>
    <p style="color:var(--text-muted)">
      Presupposto: hai già spostato i file del backup nelle cartelle corrette e (idealmente)
      già lanciato "Applica percorso come slug", così lo slug coincide col nome cartella.
    </p>
  </div>

  <?php if ($result): ?>
    <?php if (isset($result['error'])): ?>
      <div class="alert error"><?= e($result['error']) ?></div>
    <?php else: ?>
      <div class="alert success">
        Completato: <strong><?= $result['linked'] ?></strong> profili collegati alla propria immagine.
      </div>
      <div class="card">
        <p style="color:var(--text-muted)">
          Saltati (nessun account corrispondente): <?= $result['skippedNoMatch'] ?><br>
          Saltati (file non trovato sul disco): <?= $result['skippedFileMissing'] ?>
        </p>
        <?php if ($result['errors']): ?>
          <details>
            <summary>Vedi dettaglio file mancanti (<?= count($result['errors']) ?>)</summary>
            <pre style="white-space:pre-wrap;font-size:12px;"><?= e(implode("\n", array_slice($result['errors'], 0, 100))) ?></pre>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" onsubmit="return confirm('Collegare le immagini storiche ai profili? Aggiorna avatar_path solo per i file trovati fisicamente sul server.');">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="run">
    <button type="submit" class="btn btn-primary">Collega avatar storici</button>
  </form>
<?php include __DIR__ . '/_admin_footer.php'; ?>
