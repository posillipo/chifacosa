<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
$user = requireLogin();
requireBandOrLabel($user);
$activeTab = 'spotify';
$pageTitle = 'Spotify';

$searchResults = [];
$searchQuery = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'link') {
        $artistId = trim($_POST['artist_id'] ?? '');
        $artistName = trim($_POST['artist_name'] ?? '');
        if ($artistId !== '') {
            $stmt = getDB()->prepare('UPDATE profiles SET spotify_artist_id=?, spotify_artist_name=? WHERE user_id=?');
            $stmt->execute([$artistId, $artistName, $user['id']]);
            header('Location: /dashboard_spotify.php');
            exit;
        }
    } elseif ($action === 'unlink') {
        $stmt = getDB()->prepare('UPDATE profiles SET spotify_artist_id=NULL, spotify_artist_name=NULL WHERE user_id=?');
        $stmt->execute([$user['id']]);
        header('Location: /dashboard_spotify.php');
        exit;
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = spotifySearchArtist($searchQuery);
        }
    }
}

// Ricarichiamo l'utente per avere il collegamento Spotify aggiornato dopo un eventuale salvataggio
$user = currentUser();

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca il tuo nome artista su Spotify e seleziona il profilo giusto: la tua pagina pubblica
      mostrerà automaticamente una sezione dedicata con i tuoi album, singoli e brani più
      ascoltati, aggiornata direttamente da Spotify.
    </p>
  </details>

  <?php if (!empty($user['spotify_artist_id'])): ?>
    <div class="card">
      <strong>Profilo collegato:</strong> <?= e($user['spotify_artist_name'] ?: $user['spotify_artist_id']) ?>
      <br>
      <a href="https://open.spotify.com/artist/<?= e($user['spotify_artist_id']) ?>" target="_blank">Vedi su Spotify ↗</a>
      <br><br>
      <a href="/<?= e($user['slug']) ?>/spotify" target="_blank">Vedi la tua pagina pubblica Spotify ↗</a>
      <form method="post" style="margin-top:12px;" onsubmit="return confirm('Scollegare il profilo Spotify? La sezione dedicata sparirà dalla tua pagina pubblica.');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="unlink">
        <button class="btn danger" type="submit">Scollega profilo Spotify</button>
      </form>
    </div>
  <?php else: ?>
    <div class="alert error">Nessun profilo Spotify collegato ancora — cercalo qui sotto.</div>
  <?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca il tuo nome artista su Spotify</label>
    <input type="text" name="query" value="<?= e($searchQuery) ?>" placeholder="es. Il tuo nome d'arte" required>
    <button type="submit" class="btn">Cerca</button>
  </form>

  <?php if ($searchResults): ?>
    <div class="section-title">Risultati (<?= count($searchResults) ?>) — seleziona il tuo profilo</div>
    <?php foreach ($searchResults as $r): ?>
      <div class="link-item">
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($r['image']): ?>
            <img src="<?= e($r['image']) ?>" alt="" style="width:48px;height:48px;border-radius:50%;">
          <?php endif; ?>
          <div>
            <strong><?= e($r['name']) ?></strong><br>
            <small style="color:var(--text-muted)"><?= number_format($r['followers']) ?> follower ·
              <a href="<?= e($r['spotify_url']) ?>" target="_blank">Vedi su Spotify</a>
            </small>
          </div>
        </div>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="link">
          <input type="hidden" name="artist_id" value="<?= e($r['id']) ?>">
          <input type="hidden" name="artist_name" value="<?= e($r['name']) ?>">
          <button class="btn small" type="submit">È il mio profilo</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php elseif ($searchQuery !== ''): ?>
    <div class="card">Nessun artista trovato con questo nome. Prova con un termine diverso.</div>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
