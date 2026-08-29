<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'fan_bands';
$pageTitle = 'Band che amo';

$searchResults = [];
$searchQuery = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $artistId = trim($_POST['artist_id'] ?? '');
        $artistName = trim($_POST['artist_name'] ?? '');
        $artistImage = trim($_POST['artist_image'] ?? '');
        if ($artistId !== '') {
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_bands
                (user_id, spotify_artist_id, spotify_artist_name, artist_image, sort_order)
                VALUES (?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_bands WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $artistId, $artistName, $artistImage ?: null, $profile['id']]);
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_bands WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = spotifySearchArtist($searchQuery);
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_bands WHERE user_id=? ORDER BY sort_order ASC');
$stmt->execute([$profile['id']]);
$favorites = $stmt->fetchAll();
$favoriteIds = array_column($favorites, 'spotify_artist_id');

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca su Spotify le band o gli artisti che ami e aggiungili alla tua lista — qualsiasi
      band esista su Spotify, non solo quelle registrate su <?= e(siteName()) ?>. Comparirà sulla tua
      pagina pubblica come vetrina di ciò che ascolti.
    </p>
  </details>

  <div class="section-title">La tua lista (<?= count($favorites) ?>)</div>
  <?php if (!$favorites): ?>
    <div class="alert error">Nessuna band aggiunta ancora — cercala qui sotto.</div>
  <?php endif; ?>
  <?php foreach ($favorites as $f): ?>
    <div class="link-item">
      <div style="display:flex;align-items:center;gap:12px;">
        <?php if ($f['artist_image']): ?>
          <img src="<?= e($f['artist_image']) ?>" style="width:44px;height:44px;border-radius:50%;">
        <?php endif; ?>
        <strong><?= e($f['spotify_artist_name']) ?></strong>
      </div>
      <form method="post" onsubmit="return confirm('Rimuovere questa band dalla tua lista?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
        <button class="btn small danger" type="submit">Rimuovi</button>
      </form>
    </div>
  <?php endforeach; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca una band/artista su Spotify</label>
    <input type="text" name="query" value="<?= e($searchQuery) ?>" placeholder="es. nome della band" required>
    <button type="submit" class="btn">Cerca</button>
  </form>

  <?php if ($searchResults): ?>
    <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
    <?php foreach ($searchResults as $r): ?>
      <div class="link-item">
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($r['image']): ?>
            <img src="<?= e($r['image']) ?>" alt="" style="width:44px;height:44px;border-radius:50%;">
          <?php endif; ?>
          <strong><?= e($r['name']) ?></strong>
        </div>
        <?php if (in_array($r['id'], $favoriteIds, true)): ?>
          <span style="color:var(--text-muted);font-size:13px;">Già in lista</span>
        <?php else: ?>
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="artist_id" value="<?= e($r['id']) ?>">
            <input type="hidden" name="artist_name" value="<?= e($r['name']) ?>">
            <input type="hidden" name="artist_image" value="<?= e($r['image'] ?? '') ?>">
            <button class="btn small" type="submit">Aggiungi alla lista</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php elseif ($searchQuery !== ''): ?>
    <div class="card">Nessun risultato per questa ricerca.</div>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
