<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/spotify.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
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
            $stmt->execute([$artistId, $artistName, $profile['id']]);
            header('Location: /dashboard_spotify.php');
            exit;
        }
    } elseif ($action === 'unlink') {
        $stmt = getDB()->prepare('UPDATE profiles SET spotify_artist_id=NULL, spotify_artist_name=NULL WHERE user_id=?');
        $stmt->execute([$profile['id']]);
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
$profile = getActingProfile($user);

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx tlx-narrow">
    <?php if (!empty($profile['spotify_artist_id'])): ?>
      <div class="box">
        <h3><i class="fa-brands fa-spotify"></i> Profilo collegato</h3>
        <p><b><?= e($profile['spotify_artist_name'] ?: $profile['spotify_artist_id']) ?></b></p>
        <div class="box-row">
          <a class="stc-act" href="https://open.spotify.com/artist/<?= e($profile['spotify_artist_id']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Vedi su Spotify</span></a>
          <a class="stc-act" href="/<?= e($profile['slug']) ?>/spotify" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> <span>La tua pagina pubblica</span></a>
          <form method="post" data-confirm="Scollegare il profilo Spotify? La sezione dedicata sparirà dalla tua pagina pubblica."><?= csrfField() ?><input type="hidden" name="action" value="unlink"><button class="stc-act danger" type="submit"><i class="fa-solid fa-link-slash"></i> <span>Scollega</span></button></form>
        </div>
      </div>
    <?php else: ?>
      <div class="tlx-empty"><i class="fa-brands fa-spotify"></i><b>Nessun profilo Spotify collegato</b><span>Cerca il tuo nome d'arte qui sotto.</span></div>
    <?php endif; ?>

    <form method="post" class="box">
      <?= csrfField() ?><input type="hidden" name="action" value="search">
      <h3><i class="fa-solid fa-magnifying-glass"></i> Cerca il tuo nome artista su Spotify</h3>
      <input type="text" name="query" value="<?= e($searchQuery) ?>" placeholder="es. Il tuo nome d'arte" required>
      <button type="submit" class="tlx-submit cmp-done">Cerca</button>
    </form>

    <?php if ($searchResults): ?>
      <div class="tlx-listhead"><h2>Risultati <small>(<?= count($searchResults) ?>)</small></h2></div>
      <?php foreach ($searchResults as $r): ?>
        <div class="pnx">
          <?php if ($r['image']): ?><img class="pnx-img rnd" src="<?= e($r['image']) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr($r['name'], 0, 1))) ?></span><?php endif; ?>
          <div class="pnx-b"><b><?= e($r['name']) ?></b><small><?= number_format($r['followers']) ?> follower · <a href="<?= e($r['spotify_url']) ?>" target="_blank" rel="noopener">Vedi su Spotify</a></small></div>
          <form method="post" class="pnx-act"><?= csrfField() ?><input type="hidden" name="action" value="link"><input type="hidden" name="artist_id" value="<?= e($r['id']) ?>"><input type="hidden" name="artist_name" value="<?= e($r['name']) ?>"><button class="stc-act primary" type="submit"><i class="fa-solid fa-check"></i> <span>È il mio</span></button></form>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="tlx-empty"><i class="fa-solid fa-circle-question"></i><b>Nessun artista trovato</b><span>Prova con un termine diverso.</span></div>
    <?php endif; ?>
  </div>
<script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
