<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale (conteggi, visibilità, riordino), cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
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
    'ricettecheamo' => 'fan_favorite_recipes',
    'squadrecheamo' => 'fan_favorite_teams',
    'calciatoricheamo' => 'fan_favorite_players',
    'partitecheamo' => 'fan_favorite_matches',
    'pubblicazionicheamo' => 'fan_favorite_publications',
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
    'ricettecheamo' => '/dashboard_fan_recipes.php',
    'squadrecheamo' => '/dashboard_fan_teams.php',
    'calciatoricheamo' => '/dashboard_fan_players.php',
    'partitecheamo' => '/dashboard_fan_matches.php',
    'pubblicazionicheamo' => '/dashboard_fan_publications.php',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'reorder') {
        // Trascinamento delle card (vedi JS più sotto): stessa tabella/meccanismo di
        // dashboard_nav_menu.php (profile_navigation_menu.sort_order — ogni modulo "che amo" ha
        // già una sua riga lì), ma NON possiamo rinumerarle da 1 a 8 come fa quella pagina: qui
        // riordiniamo solo un sottoinsieme di 20 voci di menu, e quelle 8 non sono contigue (si
        // intrecciano con Spotify/Podcast/Video/Blog). Prendiamo quindi gli "slot" di sort_order
        // già occupati da questi 8 moduli (nell'ordine attuale) e li ridistribuiamo tra i moduli
        // nel nuovo ordine scelto — così l'ordine di TUTTO il menu pubblico (incluse le voci non
        // "che amo" in mezzo) resta intatto, cambia solo l'ordine reciproco di questi 8.
        header('Content-Type: application/json; charset=UTF-8');
        $cheAmoNames = [];
        foreach (PUBLIC_NAV_ITEM_KEYS as $name => $key) {
            if (isset(CHE_AMO_MODULES[$key])) {
                $cheAmoNames[] = $name;
            }
        }
        $placeholders = implode(',', array_fill(0, count($cheAmoNames), '?'));
        $stmt = getDB()->prepare("SELECT sort_order FROM profile_navigation_menu WHERE user_id = ? AND name IN ($placeholders) ORDER BY sort_order ASC");
        $stmt->execute(array_merge([$profile['id']], $cheAmoNames));
        $slots = array_column($stmt->fetchAll(), 'sort_order');

        $orderedIds = array_map('intval', $_POST['order'] ?? []);
        $updateStmt = getDB()->prepare('UPDATE profile_navigation_menu SET sort_order = ? WHERE id = ? AND user_id = ?');
        foreach ($orderedIds as $i => $id) {
            if (isset($slots[$i])) {
                $updateStmt->execute([$slots[$i], $id, $profile['id']]);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

$counts = [];
foreach ($moduleTables as $key => $table) {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM {$table} WHERE user_id = ?");
    $stmt->execute([$profile['id']]);
    $counts[$key] = (int) $stmt->fetch()['c'];
}

// Ordine delle card personalizzabile via trascinamento — stessa tabella/ordine di
// dashboard_nav_menu.php (profile_navigation_menu), dato che ogni modulo "che amo" ha già una
// sua riga lì: trascinare qui aggiorna anche l'ordine della barra di navigazione pubblica per
// queste voci, e viceversa.
// Stesse regole di visibilità della vetrina pubblica (che_amo.php): un modulo nascosto da
// "Menu di Navigazione" o disattivato per tutta l'installazione da Area Admin → Funzioni del
// sito non deve avere una card qui, altrimenti resterebbe gestibile un modulo che nessun
// visitatore potrà mai vedere.
$hiddenKeys = getHiddenNavKeys((int) $profile['id']);
$cheAmoNavRows = [];
if (!in_array('cheamo', $hiddenKeys, true)) {
    foreach (getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']) as $row) {
        $key = PUBLIC_NAV_ITEM_KEYS[$row['name']] ?? null;
        if ($key !== null && isset(CHE_AMO_MODULES[$key]) && !in_array($key, $hiddenKeys, true)) {
            $cheAmoNavRows[] = ['id' => (int) $row['id'], 'key' => $key];
        }
    }
}

include __DIR__ . '/../_dash_header.php';
$camColor = ['bandcheamo' => 1, 'attorichamo' => 2, 'filmcheamo' => 3, 'libricheamo' => 4, 'viaggi' => 5, 'brani' => 6, 'playlistcheamo' => 1, 'albumcheamo' => 2,
    'ricettecheamo' => 3, 'squadrecheamo' => 4, 'calciatoricheamo' => 5, 'partitecheamo' => 6, 'pubblicazionicheamo' => 1];
$camTotal = 0; $camActive = 0;
foreach ($cheAmoNavRows as $r) { $camTotal += $counts[$r['key']]; if ($counts[$r['key']] > 0) { $camActive++; } }
?>
  <div class="tlx cam">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= $camTotal ?></b><span>Elementi in tutto</span></div>
      <div class="tlx-kpi"><b><?= $camActive ?> / <?= count($cheAmoNavRows) ?></b><span>Raccolte in uso</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>/che-amo" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>Apri la vetrina pubblica</span></a>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_nav_menu.php"><b><i class="fa-solid fa-sliders"></i></b><span>Menu di navigazione</span></a>
    </div>

    <div class="tlx-listhead"><h2>Le cose che ami</h2><span class="lkx-hint"><i class="fa-solid fa-grip-vertical"></i> Trascina per riordinare</span></div>

    <?php if (!$cheAmoNavRows): ?>
      <div class="tlx-empty"><i class="fa-solid fa-heart"></i><b>Nessuna raccolta attiva</b><span>Le raccolte sono nascoste dal Menu di navigazione o disattivate per il sito.</span></div>
    <?php endif; ?>

    <div class="cam-grid" id="che-amo-sortable-list" data-csrf="<?= e(csrfToken()) ?>">
      <?php foreach ($cheAmoNavRows as $row):
        $key = $row['key']; $m = CHE_AMO_MODULES[$key]; $n = $counts[$key]; ?>
        <div class="cam-tile cam-c<?= (int) ($camColor[$key] ?? 1) ?><?= $n === 0 ? ' is-empty' : '' ?> che-amo-tile-wrap" data-id="<?= $row['id'] ?>">
          <span class="cam-grip che-amo-drag-handle" aria-label="Trascina per riordinare"><i class="fa-solid fa-grip-vertical"></i></span>
          <a href="<?= e($moduleUrls[$key]) ?>" class="cam-card">
            <span class="cam-ic"><i class="<?= e($m['icon']) ?>"></i></span>
            <b><?= e($m['label']) ?></b>
            <?php if ($n > 0): ?>
              <span class="cam-n"><?= $n ?> element<?= $n === 1 ? 'o' : 'i' ?></span>
              <span class="cam-go">Gestisci <i class="fa-solid fa-arrow-right"></i></span>
            <?php else: ?>
              <span class="cam-n">Ancora vuoto</span>
              <span class="cam-go cam-add"><i class="fa-solid fa-plus"></i> Aggiungi il primo</span>
            <?php endif; ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-cheamo.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-cheamo.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
