<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'import_legacy';
$pageTitle = 'Rigenera miniature Timeline/Che Amo';

// Le 13 tabelle che hanno una miniatura leggera generata dal browser al caricamento (stesso
// meccanismo di dashboard_post.php, vedi commento lì) — l'elenco corrisponde esattamente alle
// tabelle con colonna image_thumb_path in schema.sql.
const THUMB_TABLES = [
    'timeline_posts', 'favorite_tracks', 'fan_favorite_bands', 'fan_favorite_actors',
    'fan_favorite_movies', 'fan_favorite_books', 'fan_favorite_trips', 'fan_favorite_playlists',
    'fan_favorite_albums', 'fan_favorite_recipes', 'fan_favorite_teams', 'fan_favorite_players',
    'fan_favorite_matches',
];
const THUMB_MAX_DIM = 600;
const UPLOADS_ROOT = '/var/www/html/';

$result = null;

// Ricrea UNA miniatura dalla foto originale già caricata (image_path, già compressa a ≤1600px
// da compressImageToJpeg — mai il file scattato dal telefono a piena risoluzione), con lo stesso
// identico algoritmo (lato lungo a THUMB_MAX_DIM, mai upscalare) del canvas lato browser in
// dashboard_post.php e negli 11 moduli Che Amo. Sovrascrive lo stesso file di miniatura: nessuna
// colonna del database cambia, nessun link si rompe.
function regenerateOneThumb(string $imagePathRel, string $thumbPathRel): string {
    $srcFile = UPLOADS_ROOT . $imagePathRel;
    $thumbFile = UPLOADS_ROOT . $thumbPathRel;
    if (!is_file($srcFile)) {
        return 'missing_source';
    }
    if (!is_file($thumbFile)) {
        return 'missing_thumb';
    }
    $thumbInfo = @getimagesize($thumbFile);
    if ($thumbInfo && max($thumbInfo[0], $thumbInfo[1]) >= THUMB_MAX_DIM) {
        return 'already_ok';
    }

    @ini_set('memory_limit', '512M');
    $raw = file_get_contents($srcFile);
    $img = $raw !== false ? @imagecreatefromstring($raw) : false;
    if ($img === false) {
        return 'error';
    }
    $width = imagesx($img);
    $height = imagesy($img);
    $scale = min(1, THUMB_MAX_DIM / max($width, $height));
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($img);
    $ok = imagejpeg($resized, $thumbFile, 82);
    imagedestroy($resized);
    return $ok ? 'regenerated' : 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    checkCsrf();
    @set_time_limit(0);
    $db = getDB();
    $counts = ['regenerated' => 0, 'already_ok' => 0, 'missing_source' => 0, 'missing_thumb' => 0, 'error' => 0];
    $errors = [];
    foreach (THUMB_TABLES as $table) {
        $stmt = $db->query("SELECT id, image_path, image_thumb_path FROM {$table} WHERE image_thumb_path IS NOT NULL AND image_path IS NOT NULL");
        foreach ($stmt->fetchAll() as $row) {
            $status = regenerateOneThumb($row['image_path'], $row['image_thumb_path']);
            $counts[$status]++;
            if (in_array($status, ['error', 'missing_source', 'missing_thumb'], true)) {
                $errors[] = "{$table}#{$row['id']}: {$status} ({$row['image_thumb_path']})";
            }
        }
    }
    $result = ['counts' => $counts, 'errors' => $errors];
}

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <strong>Cosa fa questa operazione</strong>
    <p style="color:var(--text-muted)">
      Il 16/09/2026 il tetto delle miniature leggere (Timeline e tutti gli 11 moduli Che Amo,
      più Brani) è passato da 320px a 600px sul lato lungo — ma solo per le foto caricate
      <strong>da quel momento in poi</strong>: le miniature già salvate sul disco non si
      rigenerano da sole quando cambia il codice. Questo strumento rilegge ogni miniatura più
      vecchia di 600px partendo dalla foto originale già caricata (non serve richiedere nulla
      agli utenti) e la ricrea alla nuova dimensione, sovrascrivendo lo stesso file: nessuna
      modifica al database, nessun link cambia.
    </p>
    <p style="color:var(--text-muted)">
      Sicuro da ripetere quante volte vuoi: le miniature già a 600px o più vengono saltate senza
      toccarle.
    </p>
  </div>

  <?php if ($result): ?>
    <div class="alert success">
      Completato: <strong><?= $result['counts']['regenerated'] ?></strong> miniature rigenerate,
      <?= $result['counts']['already_ok'] ?> già a posto.
    </div>
    <?php if ($result['counts']['missing_source'] || $result['counts']['missing_thumb'] || $result['counts']['error']): ?>
      <div class="card">
        <p style="color:var(--text-muted)">
          Foto originale non trovata sul disco: <?= $result['counts']['missing_source'] ?><br>
          Miniatura non trovata sul disco: <?= $result['counts']['missing_thumb'] ?><br>
          Errori di elaborazione: <?= $result['counts']['error'] ?>
        </p>
        <?php if ($result['errors']): ?>
          <details>
            <summary>Vedi dettaglio (<?= count($result['errors']) ?>)</summary>
            <pre style="white-space:pre-wrap;font-size:12px;"><?= e(implode("\n", array_slice($result['errors'], 0, 200))) ?></pre>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" onsubmit="return confirm('Rigenerare tutte le miniature più vecchie di 600px? Operazione sicura, non tocca il database.');">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="run">
    <button type="submit" class="btn btn-primary">Rigenera miniature esistenti a 600px</button>
  </form>
<?php include __DIR__ . '/_admin_footer.php'; ?>
