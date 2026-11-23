<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/spotify.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
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
            $stmt->execute([$showId, $showName, $profile['id']]);
            header('Location: /dashboard_podcast.php');
            exit;
        }
    } elseif ($action === 'unlink') {
        $stmt = getDB()->prepare('UPDATE profiles SET spotify_show_id=NULL, spotify_show_name=NULL WHERE user_id=?');
        $stmt->execute([$profile['id']]);
        header('Location: /dashboard_podcast.php');
        exit;
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = spotifySearchShow($searchQuery);
        }
    }
}

$user = currentUser(true);
$profile = getActingProfile($user);

include __DIR__ . '/../_dash_header.php';
$linked = !empty($profile['spotify_show_id']);
?>
  <div class="tlx">
    <?php if ($linked): ?>
      <div class="box">
        <h3><i class="fa-solid fa-podcast"></i> Podcast collegato</h3>
        <p><b><?= e($profile['spotify_show_name'] ?: $profile['spotify_show_id']) ?></b></p>
        <div class="box-row">
          <a class="stc-act primary" href="/<?= e($profile['slug']) ?>/podcast" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>La tua pagina Podcast</span></a>
          <a class="stc-act" href="https://open.spotify.com/show/<?= e($profile['spotify_show_id']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-spotify"></i> <span>Vedi su Spotify</span></a>
          <form method="post" data-confirm="Scollegare il podcast? La sezione dedicata sparirà dalla tua pagina pubblica."><?= csrfField() ?><input type="hidden" name="action" value="unlink"><button class="stc-act danger" type="submit"><i class="fa-solid fa-link-slash"></i> <span>Scollega</span></button></form>
        </div>
      </div>
    <?php else: ?>
      <div class="tlx-empty"><i class="fa-solid fa-podcast"></i><b>Nessun podcast collegato ancora</b><span>Cercalo qui sotto per nome: lo trovi su Spotify.</span></div>
    <?php endif; ?>

    <form method="post" class="blx-add">
      <?= csrfField() ?><input type="hidden" name="action" value="search">
      <input type="text" name="query" value="<?= e($searchQuery) ?>" placeholder="Cerca il nome del tuo podcast su Spotify" required aria-label="Nome del podcast">
      <button type="submit" class="blx-new"><i class="fa-solid fa-magnifying-glass"></i> <span>Cerca</span></button>
    </form>

    <?php if ($searchResults): ?>
      <div class="tlx-listhead"><h2>Risultati <small>(<?= count($searchResults) ?>)</small></h2><span class="lkx-hint">scegli il tuo podcast</span></div>
      <?php foreach ($searchResults as $r): ?>
        <div class="pnx">
          <?php if ($r['image']): ?><img class="pnx-img" src="<?= e($r['image']) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img stc-ph"><i class="fa-solid fa-podcast"></i></span><?php endif; ?>
          <div class="pnx-b"><b><?= e($r['name']) ?></b><small><?= e($r['publisher']) ?> · <a href="<?= e($r['spotify_url']) ?>" target="_blank" rel="noopener">Vedi su Spotify</a></small></div>
          <form method="post" class="pnx-act"><?= csrfField() ?><input type="hidden" name="action" value="link"><input type="hidden" name="show_id" value="<?= e($r['id']) ?>"><input type="hidden" name="show_name" value="<?= e($r['name']) ?>"><button class="stc-act primary" type="submit"><i class="fa-solid fa-check"></i> <span>È il mio podcast</span></button></form>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="tlx-empty"><i class="fa-solid fa-magnifying-glass"></i><b>Nessun podcast trovato</b><span>Prova con un termine diverso.</span></div>
    <?php endif; ?>

    <?php if ($linked):
      $epList = spotifyGetShowEpisodes($profile['spotify_show_id'], 10); ?>
      <div class="tlx-listhead"><h2>Ultimi episodi <small>(<?= count($epList) ?>)</small></h2></div>
      <?php if (!$epList): ?><div class="tlx-empty"><i class="fa-solid fa-podcast"></i><b>Nessun episodio trovato</b><span>Spotify non ha restituito episodi per questo podcast.</span></div><?php endif; ?>
      <?php foreach ($epList as $ep): ?>
        <a class="pnx" href="<?= e((string) $ep['spotify_url']) ?>" target="_blank" rel="noopener">
          <?php if ($ep['image']): ?><img class="pnx-img" src="<?= e($ep['image']) ?>" alt="" loading="lazy"><?php endif; ?>
          <div class="pnx-b"><span class="pnx-t"><?= $ep['release_date'] ? e(date('d/m/Y', strtotime($ep['release_date']))) : '' ?><?= $ep['duration_ms'] ? ' · ' . gmdate('i:s', (int) ($ep['duration_ms'] / 1000)) . ' min' : '' ?></span><b><?= e($ep['name']) ?></b><?php if ($ep['description']): ?><small><?= e($ep['description']) ?></small><?php endif; ?></div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
