<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'compress_images';
$pageTitle = 'Comprimi immagini esistenti (una tantum)';

// Pagina "usa e getta": converte in JPEG e comprime sotto i 250KB tutte le foto già caricate
// prima dell'introduzione di compressImageToJpeg() (vedi handleCoverUpload() in functions.php).
// Va lanciata una sola volta a mano da un amministratore, poi va rimossa dal codice — non è
// collegata a nessun menu e non fa nulla finché non si preme "Avvia" qui sotto.
const COMPRESS_JOBS = [
    ['table' => 'profiles',             'pk' => 'user_id', 'cols' => [['col' => 'avatar_path',      'maxDim' => 800]]],
    ['table' => 'links',                'pk' => 'id',      'cols' => [['col' => 'cover_path',       'maxDim' => 1600]]],
    ['table' => 'blog_posts',           'pk' => 'id',      'cols' => [['col' => 'cover_path',       'maxDim' => 1600]]],
    ['table' => 'events',               'pk' => 'id',      'cols' => [['col' => 'cover_path',       'maxDim' => 1600]]],
    ['table' => 'timeline_posts',       'pk' => 'id',      'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
    ['table' => 'favorite_tracks',      'pk' => 'id',      'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
    ['table' => 'fan_favorite_bands',   'pk' => 'id',      'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
    ['table' => 'fan_favorite_actors',  'pk' => 'id',      'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
    ['table' => 'fan_favorite_movies',  'pk' => 'id',      'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
    ['table' => 'fan_favorite_books',   'pk' => 'id',      'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
    ['table' => 'fan_favorite_trips',   'pk' => 'id',      'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
    ['table' => 'fan_favorite_playlists', 'pk' => 'id',    'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
    ['table' => 'fan_favorite_albums',  'pk' => 'id',      'cols' => [['col' => 'image_path',       'maxDim' => 1600], ['col' => 'image_thumb_path', 'maxDim' => 320]]],
];

const COMPRESS_MAX_BYTES = 256000; // 250KB
const COMPRESS_UPLOADS_ROOT = '/var/www/html/uploads/images/';

$ran = false;
$log = [];
$stats = ['scanned' => 0, 'compressed' => 0, 'skipped_ok' => 0, 'missing' => 0, 'invalid' => 0, 'bytes_before' => 0, 'bytes_after' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    checkCsrf();
    $ran = true;
    set_time_limit(0);

    $db = getDB();
    foreach (COMPRESS_JOBS as $job) {
        $table = $job['table'];
        $pk = $job['pk'];
        foreach ($job['cols'] as $c) {
            $col = $c['col'];
            $maxDim = $c['maxDim'];
            $stmt = $db->query("SELECT {$pk} AS pk_val, {$col} AS path_val FROM {$table} WHERE {$col} IS NOT NULL AND {$col} != ''");
            foreach ($stmt->fetchAll() as $row) {
                $stats['scanned']++;
                $relPath = $row['path_val'];
                // Solo file nostri (uploads/images/...): niente URL esterne o percorsi anomali.
                if (!str_starts_with($relPath, 'uploads/images/')) {
                    continue;
                }
                $fullPath = '/var/www/html/' . $relPath;
                if (!is_file($fullPath)) {
                    $stats['missing']++;
                    $log[] = "⚠️ {$table}.{$col} #{$row['pk_val']}: file non trovato ({$relPath})";
                    continue;
                }
                $sizeBefore = filesize($fullPath);
                $isAlreadyJpg = preg_match('#\.jpe?g$#i', $relPath) === 1;
                if ($isAlreadyJpg && $sizeBefore <= COMPRESS_MAX_BYTES) {
                    $stats['skipped_ok']++;
                    continue;
                }

                $raw = file_get_contents($fullPath);
                $jpeg = $raw !== false ? compressImageToJpeg($raw, COMPRESS_MAX_BYTES, $maxDim) : null;
                if ($jpeg === null) {
                    $stats['invalid']++;
                    $log[] = "❌ {$table}.{$col} #{$row['pk_val']}: non è un'immagine valida ({$relPath})";
                    continue;
                }

                if ($isAlreadyJpg) {
                    // Stesso file, si sovrascrive: nessun cambio di percorso, nessun UPDATE da fare.
                    file_put_contents($fullPath, $jpeg);
                    $newRelPath = $relPath;
                } else {
                    $newRelPath = preg_replace('#\.[a-zA-Z0-9]+$#', '.jpg', $relPath);
                    $newFullPath = '/var/www/html/' . $newRelPath;
                    file_put_contents($newFullPath, $jpeg);
                    @unlink($fullPath);
                    $db->prepare("UPDATE {$table} SET {$col} = ? WHERE {$pk} = ?")
                        ->execute([$newRelPath, $row['pk_val']]);
                }

                $stats['compressed']++;
                $stats['bytes_before'] += $sizeBefore;
                $stats['bytes_after'] += strlen($jpeg);
                $log[] = "✅ {$table}.{$col} #{$row['pk_val']}: " . round($sizeBefore / 1024, 1) . 'KB → ' . round(strlen($jpeg) / 1024, 1) . "KB ({$relPath} → {$newRelPath})";
            }
        }
    }
}

include __DIR__ . '/_admin_header.php';
?>
  <div class="help-box card" style="margin-bottom:20px;">
    <p style="color:var(--text-muted)">
      Ricomprime in JPEG (max 250KB) tutte le foto caricate <strong>prima</strong> dell'introduzione
      di questo limite — avatar, copertine di link/blog/eventi, foto della Timeline e di tutti i
      moduli "che amo" (Band/Attori/Film/Libri/Viaggi/Brani/Playlist/Album che amo). Le immagini
      già sotto i 250KB e già in formato JPEG vengono lasciate esattamente come sono (nessuna
      ricompressione inutile). Aggiorna anche il percorso salvato in database quando il formato
      cambia (es. da .png a .jpg), così ogni pagina continua a mostrare la foto giusta.
      Pagina pensata per essere lanciata una volta sola e poi rimossa dal codice.
    </p>
  </div>

  <?php if (!$ran): ?>
    <form method="post" class="card" onsubmit="return confirm('Avviare la compressione di tutte le immagini già caricate? Non è annullabile (i file originali oversize/non-JPEG vengono sostituiti).');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="run">
      <button type="submit" class="btn">Avvia compressione</button>
    </form>
  <?php else: ?>
    <div class="card" style="margin-bottom:16px;">
      <strong>Fatto.</strong>
      <p style="color:var(--text-muted)">
        Controllate <?= (int) $stats['scanned'] ?> righe con una foto —
        <?= (int) $stats['compressed'] ?> ricompresse,
        <?= (int) $stats['skipped_ok'] ?> già a posto,
        <?= (int) $stats['missing'] ?> file mancanti su disco,
        <?= (int) $stats['invalid'] ?> non erano immagini valide.
        <?php if ($stats['compressed'] > 0): ?>
          Spazio recuperato: <?= round(($stats['bytes_before'] - $stats['bytes_after']) / 1024 / 1024, 2) ?> MB
          (da <?= round($stats['bytes_before'] / 1024 / 1024, 2) ?> MB a <?= round($stats['bytes_after'] / 1024 / 1024, 2) ?> MB).
        <?php endif; ?>
      </p>
    </div>
    <?php if ($log): ?>
      <details class="card" open>
        <summary>Dettaglio (<?= count($log) ?> righe)</summary>
        <pre style="white-space:pre-wrap;font-size:12.5px;max-height:500px;overflow-y:auto;margin-top:10px;"><?= e(implode("\n", $log)) ?></pre>
      </details>
    <?php endif; ?>
  <?php endif; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
