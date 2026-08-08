<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
$user = requireLogin();
$profile = getActingProfile($user); // il profilo su cui si sta agendo (proprio, o co-gestito)
$activeTab = 'audio';
$pageTitle = 'Brani';

$searchResults = [];
$searchQuery = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $trackId = trim($_POST['track_id'] ?? '');
        if ($trackId !== '') {
            $trackName = trim($_POST['track_name'] ?? '');
            $stmt = getDB()->prepare('INSERT IGNORE INTO favorite_tracks
                (user_id, spotify_track_id, track_name, artist_name, track_image, spotify_url, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM favorite_tracks WHERE user_id=?) t))');
            $stmt->execute([
                $profile['id'], $trackId,
                $trackName, trim($_POST['artist_name'] ?? ''),
                trim($_POST['track_image'] ?? '') ?: null, trim($_POST['spotify_url'] ?? '') ?: null,
                $profile['id'],
            ]);
            logAdminAction((int) $profile['id'], (int) $user['id'], 'Nuovo brano aggiunto', $trackName);
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM favorite_tracks WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        logAdminAction((int) $profile['id'], (int) $user['id'], 'Brano rimosso');
    } elseif ($action === 'save_lyrics') {
        $id = (int) ($_POST['id'] ?? 0);
        $lyrics = trim($_POST['lyrics'] ?? '');
        $stmt = getDB()->prepare('UPDATE favorite_tracks SET lyrics=? WHERE id=? AND user_id=?');
        $stmt->execute([$lyrics ?: null, $id, $profile['id']]);
        logAdminAction((int) $profile['id'], (int) $user['id'], 'Testo del brano aggiornato');
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = spotifySearchTrack($searchQuery);
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM favorite_tracks WHERE user_id=? ORDER BY sort_order ASC');
$stmt->execute([$profile['id']]);
$tracks = $stmt->fetchAll();
$trackIds = array_column($tracks, 'spotify_track_id');

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca su Spotify i brani da mostrare sulla tua pagina (i tuoi, le tue cover, o qualsiasi
      brano ti rappresenti) e aggiungili — compariranno nella sezione "Brani" della tua pagina
      pubblica, con link diretto per ascoltarli su Spotify.
    </p>
  </details>

  <div class="section-title">I tuoi brani (<?= count($tracks) ?>)</div>
  <?php if (!$tracks): ?>
    <div class="alert error">Nessun brano aggiunto ancora — cercalo qui sotto.</div>
  <?php endif; ?>
  <?php foreach ($tracks as $t): ?>
    <div class="link-item" style="flex-direction:column;align-items:stretch;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($t['track_image']): ?>
            <img src="<?= e($t['track_image']) ?>" style="width:48px;height:48px;border-radius:6px;">
          <?php endif; ?>
          <div>
            <strong><?= e($t['track_name']) ?></strong><br>
            <small style="color:var(--text-muted)"><?= e($t['artist_name']) ?></small>
          </div>
        </div>
        <div style="display:flex;gap:8px;">
          <button type="button" class="btn small" style="background:#1DB954;color:#fff;" onclick="document.getElementById('lyrics-<?= (int)$t['id'] ?>').classList.toggle('hidden-form')">
            <?= $t['lyrics'] ? '📝 Testo' : '➕ Lyrics' ?>
          </button>
          <form method="post" onsubmit="return confirm('Rimuovere questo brano?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn small danger" type="submit">Rimuovi</button>
          </form>
        </div>
      </div>
      <form method="post" id="lyrics-<?= (int)$t['id'] ?>" class="hidden-form" style="margin-top:12px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_lyrics">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <label>Testo del brano</label>
        <textarea name="lyrics" rows="6" placeholder="Incolla o scrivi qui il testo..."><?= e($t['lyrics'] ?? '') ?></textarea>
        <button type="submit" class="btn small">Salva testo</button>
      </form>
    </div>
  <?php endforeach; ?>
  <style>.hidden-form { display: none; }</style>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca un brano su Spotify</label>
    <input type="text" name="query" value="<?= e($searchQuery) ?>" placeholder="titolo, artista..." required>
    <button type="submit" class="btn">Cerca</button>
  </form>

  <?php if ($searchResults): ?>
    <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
    <?php foreach ($searchResults as $r): ?>
      <div class="link-item">
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($r['image']): ?>
            <img src="<?= e($r['image']) ?>" alt="" style="width:48px;height:48px;border-radius:6px;">
          <?php endif; ?>
          <div>
            <strong><?= e($r['name']) ?></strong><br>
            <small style="color:var(--text-muted)"><?= e($r['artist_name']) ?></small>
          </div>
        </div>
        <?php if (in_array($r['id'], $trackIds, true)): ?>
          <span style="color:var(--text-muted);font-size:13px;">Già aggiunto</span>
        <?php else: ?>
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="track_id" value="<?= e($r['id']) ?>">
            <input type="hidden" name="track_name" value="<?= e($r['name']) ?>">
            <input type="hidden" name="artist_name" value="<?= e($r['artist_name']) ?>">
            <input type="hidden" name="track_image" value="<?= e($r['image'] ?? '') ?>">
            <input type="hidden" name="spotify_url" value="<?= e($r['spotify_url'] ?? '') ?>">
            <button class="btn small" type="submit">Aggiungi</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php elseif ($searchQuery !== ''): ?>
    <div class="card">Nessun risultato per questa ricerca.</div>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
