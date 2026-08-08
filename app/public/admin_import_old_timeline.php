<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'import_legacy';
$pageTitle = 'Importa vecchia Timeline';

const CSV_TESTO = '/var/www/import/legacy_timeline_testo.csv';
const CSV_FOTO = '/var/www/import/legacy_timeline_foto.csv';
const UPLOADS_BASE = '/var/www/html/uploads/images/';
$result = null;

function parseOldDate(string $d): string {
    $ts = strtotime($d);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    checkCsrf();
    $db = getDB();
    $importedTesto = 0;
    $importedFoto = 0;
    $importedFotoConImmagine = 0;
    $skippedNoMatch = 0;
    $errors = [];

    // Cache slug per legacy_gestore_id, per non interrogare il DB ad ogni riga
    $slugCache = [];
    $getSlug = function (int $gestoreId) use ($db, &$slugCache) {
        if (!array_key_exists($gestoreId, $slugCache)) {
            $stmt = $db->prepare('SELECT id, slug FROM users WHERE legacy_gestore_id = ?');
            $stmt->execute([$gestoreId]);
            $slugCache[$gestoreId] = $stmt->fetch() ?: null;
        }
        return $slugCache[$gestoreId];
    };

    // ===== Post di testo (Profilo/pubblico/privato/soloio) =====
    if (is_readable(CSV_TESTO)) {
        $handle = fopen(CSV_TESTO, 'r');
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) continue;
            $data = array_combine($header, $row);
            $user = $getSlug((int) $data['legacy_gestore_id']);
            if (!$user) { $skippedNoMatch++; continue; }

            $stmt = $db->prepare('INSERT INTO timeline_posts (user_id, testo, created_at) VALUES (?, ?, ?)');
            $stmt->execute([$user['id'], $data['testo'], parseOldDate($data['data'])]);
            $importedTesto++;
        }
        fclose($handle);
    }

    // ===== Post foto =====
    if (is_readable(CSV_FOTO)) {
        $handle = fopen(CSV_FOTO, 'r');
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) continue;
            $data = array_combine($header, $row);
            $user = $getSlug((int) $data['legacy_gestore_id']);
            if (!$user) { $skippedNoMatch++; continue; }

            $imagePath = null;
            $physicalPath = UPLOADS_BASE . $user['slug'] . '/' . $data['file'];
            if (is_file($physicalPath)) {
                $imagePath = 'uploads/images/' . $user['slug'] . '/' . $data['file'];
                $importedFotoConImmagine++;
            }

            if ($imagePath === null && trim($data['testo']) === '') {
                continue; // né immagine trovata né testo: nulla da importare
            }

            $stmt = $db->prepare('INSERT INTO timeline_posts (user_id, testo, image_path, created_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$user['id'], $data['testo'] ?: null, $imagePath, parseOldDate($data['data'])]);
            $importedFoto++;
        }
        fclose($handle);
    }

    $result = [
        'importedTesto' => $importedTesto,
        'importedFoto' => $importedFoto,
        'importedFotoConImmagine' => $importedFotoConImmagine,
        'skippedNoMatch' => $skippedNoMatch,
    ];
}

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <strong>Cosa fa questa operazione</strong>
    <p style="color:var(--text-muted)">
      Importa i post "Profilo" (aggiornamenti testuali, 1.489 pronti) e "foto" (284 pronti)
      della vecchia timeline nella nuova tabella <code>timeline_posts</code>, preservando la
      data storica originale. Per le foto, collega l'immagine solo se il file esiste
      fisicamente nella cartella <code>uploads/images/{slug}/</code> — altrimenti importa solo
      l'eventuale didascalia come testo.
    </p>
    <p style="color:var(--text-muted)">
      <strong>Non ripetibile in sicurezza</strong>: a differenza degli altri strumenti, questo
      non ha un controllo "già importato" — se lo lanci due volte, duplica i post. Lancialo una
      sola volta.
    </p>
  </div>

  <?php if ($result): ?>
    <div class="alert success">
      Completato: <strong><?= $result['importedTesto'] ?></strong> post di testo,
      <strong><?= $result['importedFoto'] ?></strong> post foto importati
      (di cui <?= $result['importedFotoConImmagine'] ?> con immagine collegata).
    </div>
    <div class="card">
      <p style="color:var(--text-muted)">Saltati (nessun account corrispondente): <?= $result['skippedNoMatch'] ?></p>
    </div>
  <?php endif; ?>

  <form method="post" onsubmit="return confirm('Importare i post storici della vecchia timeline? Operazione da lanciare una sola volta.');">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="run">
    <button type="submit" class="btn btn-primary" <?= $result ? 'disabled' : '' ?>>Importa vecchia Timeline</button>
  </form>
<?php include __DIR__ . '/_admin_footer.php'; ?>
