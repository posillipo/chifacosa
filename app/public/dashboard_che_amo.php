<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'che_amo';
$pageTitle = 'Che Amo';

// Query di conteggio per modulo — una per tabella, tenute qui invece che dentro CHE_AMO_MODULES
// perché il nome tabella/colonna non serve altrove (solo per questa vetrina).
$moduleTables = [
    'bandcheamo' => 'fan_favorite_bands',
    'attorichamo' => 'fan_favorite_actors',
    'filmcheamo' => 'fan_favorite_movies',
    'libricheamo' => 'fan_favorite_books',
    'viaggi' => 'fan_favorite_trips',
    'brani' => 'favorite_tracks',
    'playlistcheamo' => 'fan_favorite_playlists',
    'albumcheamo' => 'fan_favorite_albums',
];
$moduleUrls = [
    'bandcheamo' => '/dashboard_fan_bands.php',
    'attorichamo' => '/dashboard_fan_actors.php',
    'filmcheamo' => '/dashboard_fan_movies.php',
    'libricheamo' => '/dashboard_fan_books.php',
    'viaggi' => '/dashboard_fan_trips.php',
    'brani' => '/dashboard_audio.php',
    'playlistcheamo' => '/dashboard_fan_playlists.php',
    'albumcheamo' => '/dashboard_fan_albums.php',
];

$counts = [];
foreach ($moduleTables as $key => $table) {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM {$table} WHERE user_id = ?");
    $stmt->execute([$profile['id']]);
    $counts[$key] = (int) $stmt->fetch()['c'];
}

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Tutti i moduli "che amo" raccolti in un unico posto, invece di una scheda a testa in cima
      alla dashboard. Ogni modulo resta esattamente quello di sempre (stessa pagina, stessi dati,
      stesse impostazioni di privacy e pubblicazione) — cambia solo il punto da cui ci si arriva.
    </p>
  </details>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;">
    <?php foreach (CHE_AMO_MODULES as $key => $m): ?>
      <a href="<?= e($moduleUrls[$key]) ?>" class="card" style="display:block;text-decoration:none;color:inherit;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
          <span style="width:40px;height:40px;border-radius:10px;background:rgba(108,92,231,0.12);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">
            <i class="<?= e($m['icon']) ?>"></i>
          </span>
          <strong><?= e($m['label']) ?></strong>
        </div>
        <p style="margin:0;color:var(--text-muted);font-size:13.5px;">
          <?= $counts[$key] ?> element<?= $counts[$key] === 1 ? 'o' : 'i' ?>
        </p>
      </a>
    <?php endforeach; ?>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
