<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
$user = requireLogin();
requireBandOrLabel($user);
$activeTab = 'podcast';
$pageTitle = 'Podcast';

$searchResults = [];
$searchQuery = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'link') {
        $showId = trim($_POST['show_id'] ?? '');
        $showName = trim($_POST['show_name'] ?? '');
        if ($showId !== '') {
            $stmt = getDB()->prepare('UPDATE profiles SET spotify_show_id=?, spotify_show_name=? WHERE user_id=?');
            $stmt->execute([$showId, $showName, $user['id']]);
            header('Location: /dashboard_podcast.php');
            exit;
        }
    } elseif ($action === 'unlink') {
        $stmt = getDB()->prepare('UPDATE profiles SET spotify_show_id=NULL, spotify_show_name=NULL WHERE user_id=?');
        $stmt->execute([$user['id']]);
        header('Location: /dashboard_podcast.php');
        exit;
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = spotifySearchShow($searchQuery);
        }
    }
}

$user = currentUser();

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Se hai un podcast su Spotify, cercalo qui e collegalo: la tua pagina pubblica mostrerà
      automaticamente gli episodi più recenti in una sezione dedicata, aggiornata direttamente
      da Spotify.
    </p>
  </details>

  <?php if (!empty($user['spotify_show_id'])): ?>
    <div class="card">
      <strong>Podcast collegato:</strong> <?= e($user['spotify_show_name'] ?: $user['spotify_show_id']) ?>
      <br>
      <a href="https://open.spotify.com/show/<?= e($user['spotify_show_id']) ?>" target="_blank">Vedi su Spotify ↗</a>
      <br><br>
      <a href="/<?= e($user['slug']) ?>/podcast" target="_blank">Vedi la tua pagina pubblica Podcast ↗</a>
      <form method="post" style="margin-top:12px;" onsubmit="return confirm('Scollegare il podcast? La sezione dedicata sparirà dalla tua pagina pubblica.');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="unlink">
        <button class="btn danger" type="submit">Scollega podcast</button>
      </form>
    </div>
  <?php else: ?>
    <div class="alert error">Nessun podcast collegato ancora — cercalo qui sotto.</div>
  <?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca il nome del tuo podcast su Spotify</label>
    <input type="text" name="query" value="<?= e($searchQuery) ?>" placeholder="es. il nome del tuo podcast" required>
    <button type="submit" class="btn">Cerca</button>
  </form>

  <?php if ($searchResults): ?>
    <div class="section-title">Risultati (<?= count($searchResults) ?>) — seleziona il tuo podcast</div>
    <?php foreach ($searchResults as $r): ?>
      <div class="link-item">
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($r['image']): ?>
            <img src="<?= e($r['image']) ?>" alt="" style="width:48px;height:48px;border-radius:8px;">
          <?php endif; ?>
          <div>
            <strong><?= e($r['name']) ?></strong><br>
            <small style="color:var(--text-muted)"><?= e($r['publisher']) ?> ·
              <a href="<?= e($r['spotify_url']) ?>" target="_blank">Vedi su Spotify</a>
            </small>
          </div>
        </div>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="link">
          <input type="hidden" name="show_id" value="<?= e($r['id']) ?>">
          <input type="hidden" name="show_name" value="<?= e($r['name']) ?>">
          <button class="btn small" type="submit">È il mio podcast</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php elseif ($searchQuery !== ''): ?>
    <div class="card">Nessun podcast trovato con questo nome. Prova con un termine diverso.</div>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
