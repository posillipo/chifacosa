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
$cheAmoNavRows = [];
foreach (getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']) as $row) {
    $key = PUBLIC_NAV_ITEM_KEYS[$row['name']] ?? null;
    if ($key !== null && isset(CHE_AMO_MODULES[$key])) {
        $cheAmoNavRows[] = ['id' => (int) $row['id'], 'key' => $key];
    }
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
    <p style="color:var(--text-muted)">
      Trascina una card dall'icona <i class="fa-solid fa-grip-vertical"></i> per cambiarne
      l'ordine: si salva subito, senza bisogno di premere alcun pulsante. È lo stesso ordine
      usato dalla barra di navigazione pubblica per queste voci (personalizzabile anche da
      Menu di Navigazione).
    </p>
  </details>
  <div id="che-amo-reorder-msg" class="alert success" style="display:none;">Ordine aggiornato.</div>

  <div id="che-amo-sortable-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;">
    <?php foreach ($cheAmoNavRows as $row): ?>
      <?php $key = $row['key']; $m = CHE_AMO_MODULES[$key]; ?>
      <div class="che-amo-tile-wrap" data-id="<?= $row['id'] ?>" style="position:relative;">
        <i class="fa-solid fa-grip-vertical che-amo-drag-handle"
           style="position:absolute;top:12px;right:12px;z-index:2;cursor:grab;color:var(--text-muted);padding:6px;"></i>
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
      </div>
    <?php endforeach; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
  <script>
  (function () {
    var list = document.getElementById('che-amo-sortable-list');
    if (!list || typeof Sortable === 'undefined') return;
    var msg = document.getElementById('che-amo-reorder-msg');
    var msgTimer = null;

    Sortable.create(list, {
      handle: '.che-amo-drag-handle',
      animation: 150,
      onEnd: function () {
        var ids = Array.prototype.map.call(list.querySelectorAll('.che-amo-tile-wrap'), function (el) {
          return el.getAttribute('data-id');
        });
        var body = new URLSearchParams();
        body.set('csrf', <?= json_encode(csrfToken()) ?>);
        body.set('action', 'reorder');
        ids.forEach(function (id) { body.append('order[]', id); });

        fetch('/dashboard_che_amo.php', { method: 'POST', body: body })
          .then(function (r) { return r.json(); })
          .then(function () {
            msg.style.display = 'block';
            clearTimeout(msgTimer);
            msgTimer = setTimeout(function () { msg.style.display = 'none'; }, 2000);
          })
          .catch(function () {});
      }
    });
  })();
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
