<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'import_legacy';
$pageTitle = 'Applica percorso storico come slug';

const CSV_PATH = '/var/www/import/legacy_percorso_slug.csv';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    checkCsrf();

    if (!is_readable(CSV_PATH)) {
        $result = ['error' => 'File CSV non trovato in ' . CSV_PATH . ' — verifica che il redeploy sia andato a buon fine.'];
    } else {
        $db = getDB();
        $handle = fopen(CSV_PATH, 'r');
        $header = fgetcsv($handle);

        $updated = 0;
        $skippedCollision = 0;
        $skippedNoMatch = 0;
        $unchanged = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }
            $data = array_combine($header, $row);
            $legacyGestoreId = (int) $data['legacy_gestore_id'];
            $newSlug = trim($data['percorso']);

            // Trova l'utente importato corrispondente
            $stmt = $db->prepare('SELECT id, slug FROM users WHERE legacy_gestore_id = ?');
            $stmt->execute([$legacyGestoreId]);
            $user = $stmt->fetch();
            if (!$user) {
                $skippedNoMatch++;
                continue;
            }

            if ($user['slug'] === $newSlug) {
                $unchanged++;
                continue;
            }

            // Verifica che il nuovo slug non collida con un altro account (reale o legacy)
            $stmt = $db->prepare('SELECT id FROM users WHERE slug = ? AND id != ?');
            $stmt->execute([$newSlug, $user['id']]);
            if ($stmt->fetch()) {
                $skippedCollision++;
                $errors[] = "Gestore {$legacyGestoreId}: slug '{$newSlug}' già in uso da un altro account, saltato.";
                continue;
            }

            try {
                $stmt = $db->prepare('UPDATE users SET slug = ? WHERE id = ?');
                $stmt->execute([$newSlug, $user['id']]);
                $updated++;
            } catch (Exception $e) {
                $errors[] = "Gestore {$legacyGestoreId}: " . $e->getMessage();
            }
        }
        fclose($handle);

        $result = [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skippedCollision' => $skippedCollision,
            'skippedNoMatch' => $skippedNoMatch,
            'errors' => $errors,
        ];
    }
}

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <strong>Cosa fa questa operazione</strong>
    <p style="color:var(--text-muted)">
      Sostituisce lo slug (URL pubblica) di ogni account importato dal vecchio sistema con il
      valore originale del campo <code>percorso</code> del vecchio database — così la nuova
      piattaforma userà esattamente lo stesso URL/nome cartella del vecchio sito
      (<code>my-band.it/percorso</code> → <code><?= e(siteName()) ?>/percorso</code>), utile per far
      coincidere gli URL con la struttura multimediale che stai ricostruendo tu manualmente.
    </p>
    <p style="color:var(--text-muted)">
      1.835 corrispondenze pronte, verificate senza collisioni note. Sicura da rilanciare più
      volte (gli slug già corretti vengono saltati automaticamente).
    </p>
  </div>

  <?php if ($result): ?>
    <?php if (isset($result['error'])): ?>
      <div class="alert error"><?= e($result['error']) ?></div>
    <?php else: ?>
      <div class="alert success">
        Completato: <strong><?= $result['updated'] ?></strong> slug aggiornati.
      </div>
      <div class="card">
        <p style="color:var(--text-muted)">
          Già corretti (nessuna modifica necessaria): <?= $result['unchanged'] ?><br>
          Saltati per collisione con un altro account: <?= $result['skippedCollision'] ?><br>
          Saltati perché nessun account corrispondente trovato: <?= $result['skippedNoMatch'] ?>
        </p>
        <?php if ($result['errors']): ?>
          <details>
            <summary>Vedi dettaglio (<?= count($result['errors']) ?>)</summary>
            <pre style="white-space:pre-wrap;font-size:12px;"><?= e(implode("\n", array_slice($result['errors'], 0, 100))) ?></pre>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" onsubmit="return confirm('Sostituire gli slug con i vecchi percorso? Cambierà l\'URL pubblica di 1.835 account (attualmente disattivati e non visibili).');">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="run">
    <button type="submit" class="btn btn-primary">Applica percorso come slug</button>
  </form>
<?php include __DIR__ . '/_admin_footer.php'; ?>
