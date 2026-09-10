<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'nav_menu';
$pageTitle = 'Menu di Navigazione';
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save_visibility';

    if ($action === 'reset_order') {
        // Redirect dedicato (invece di proseguire come per save_visibility): questo invio non
        // porta con sé nessuna casella "visibility[]", quindi lasciarlo continuare nel blocco
        // sotto nasconderebbe per errore tutte le voci del menu.
        resetProfileNavMenuOrder((int) $profile['id']);
        header('Location: /dashboard_nav_menu.php?reset=1');
        exit;
    } elseif ($action === 'reorder') {
        // Chiamata via fetch() dal trascinamento delle voci (vedi JS più sotto): risponde in
        // JSON, non genera un vero page reload — l'ordine salvato è quello con cui l'utente ha
        // appena disposto le voci nella lista.
        header('Content-Type: application/json; charset=UTF-8');
        $orderedIds = array_map('intval', $_POST['order'] ?? []);
        $stmt = getDB()->prepare('UPDATE profile_navigation_menu SET sort_order = ? WHERE id = ? AND user_id = ?');
        foreach ($orderedIds as $i => $id) {
            $stmt->execute([$i + 1, $id, $profile['id']]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    $items = getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']);
    foreach ($items as $item) {
        $isVisible = isset($_POST['visibility'][$item['id']]);
        getDB()->prepare('UPDATE profile_navigation_menu SET is_visible = ? WHERE id = ? AND user_id = ?')
            ->execute([$isVisible ? 1 : 0, $item['id'], $profile['id']]);
    }
    $success = 'Menu aggiornato.';
}

$items = getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']);

// Come sul menu pubblico vero (publicNav()): alcune voci compaiono solo se la spunta qui sotto
// è attiva E c'è anche del contenuto pubblico effettivo da mostrare — altrimenti l'anteprima qui
// sotto le darebbe per visibili quando invece sulla pagina pubblica non compaiono affatto.
$contentCheckers = [
    'Offerte' => 'hasActiveOffers',
    'Foto' => 'hasPublicPhotoContent',
    'Servizi' => 'hasVisibleServices',
];
$missingContentNames = [];
foreach ($items as $it) {
    if ($it['is_visible'] && isset($contentCheckers[$it['name']]) && !$contentCheckers[$it['name']]((int) $profile['id'])) {
        $missingContentNames[] = $it['name'];
    }
}
$visibleItems = array_values(array_filter($items, function ($it) use ($contentCheckers, $profile) {
    if (!$it['is_visible']) {
        return false;
    }
    if (isset($contentCheckers[$it['name']])) {
        return $contentCheckers[$it['name']]((int) $profile['id']);
    }
    return true;
}));

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Scegli quali voci mostrare nel menu di navigazione della tua pagina pubblica — anche
      Spotify, Podcast, Video e il pulsante "Segui". Band/Attori/Film/Libri/Playlist/Album che
      amo non hanno più un tab a testa in cima alla pagina: sono raccolti dentro "Che Amo", e la
      spunta qui sotto decide se compaiono come card in quella vetrina. Restano comunque
      raggiungibili con un link diretto e condivisibile (es. <code>/tuoslug/film-che-amo</code>),
      come indicato accanto a ciascuna voce.
      Nota: Spotify/Podcast/Video/Menù/Offerte/Foto/Servizi/Band/Attori/Film/Libri/Playlist/Album
      che amo/Viaggi restano comunque visibili solo se hanno anche effettivamente del contenuto
      PUBBLICO (collegamento fatto, piatti attivi, elementi aggiunti, offerta/album/servizio con
      visibilità "Pubblico" e non programmato nel futuro) — spuntarli qui non basta da solo a
      farli comparire: qui sotto trovi segnalato quali, fra quelle già spuntate, ne sono ancora
      prive.
      Trascina una voce dall'icona <i class="fa-solid fa-grip-vertical"></i>
      per cambiarne l'ordine: si salva subito, senza bisogno di premere "Salva".
    </p>
  </details>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if (!empty($_GET['reset'])): ?><div class="alert success">Ordine riportato a quello predefinito (uguale a quello della barra in cima alla dashboard).</div><?php endif; ?>
  <div id="nav-reorder-msg" class="alert success" style="display:none;">Ordine aggiornato.</div>

  <?php if ($missingContentNames): ?>
    <div class="alert error">
      <?= e(implode(', ', $missingContentNames)) ?> <?= count($missingContentNames) === 1 ? 'è spuntata' : 'sono spuntate' ?>
      qui sotto ma non <?= count($missingContentNames) === 1 ? 'compare' : 'compaiono' ?> ancora sulla pagina pubblica: manca del
      contenuto pubblicato con visibilità "Pubblico" e non programmato nel futuro nella relativa
      sezione della dashboard.
    </div>
  <?php endif; ?>

  <form method="post" style="margin-bottom:16px;" onsubmit="return confirm('Riportare l\'ordine delle voci a quello predefinito? Le scelte di visibilità non cambiano.');">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="reset_order">
    <button type="submit" class="btn small secondary">Ripristina l'ordine predefinito</button>
  </form>

  <form method="post" class="card">
    <?= csrfField() ?>
    <div id="nav-sortable-list">
      <?php foreach ($items as $it): ?>
        <div class="nav-sort-item" data-id="<?= (int) $it['id'] ?>" style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
          <i class="fa-solid fa-grip-vertical nav-drag-handle" style="cursor:grab;color:var(--text-muted);padding:4px;"></i>
          <label style="display:flex;align-items:center;gap:10px;margin-bottom:0;font-weight:normal;flex:1;min-width:0;">
            <input type="checkbox" name="visibility[<?= (int) $it['id'] ?>]" value="1" style="width:auto;" <?= $it['is_visible'] ? 'checked' : '' ?>>
            <?php if ($it['icon']): ?><i class="<?= e($it['icon']) ?>" style="width:20px;text-align:center;color:var(--text-muted);"></i><?php endif; ?>
            <strong><?= e($it['name']) ?></strong>
            <small style="color:var(--text-muted)"><?= e($it['url']) ?></small>
            <?php if (in_array($it['name'], $missingContentNames, true)): ?>
              <small style="color:#c0392b;font-weight:700;">— nessun contenuto pubblico, non compare</small>
            <?php endif; ?>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn">Salva</button>
  </form>

  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
  <script>
  (function () {
    var list = document.getElementById('nav-sortable-list');
    if (!list || typeof Sortable === 'undefined') return;
    var msg = document.getElementById('nav-reorder-msg');
    var msgTimer = null;

    Sortable.create(list, {
      handle: '.nav-drag-handle',
      animation: 150,
      onEnd: function () {
        var ids = Array.prototype.map.call(list.querySelectorAll('.nav-sort-item'), function (el) {
          return el.getAttribute('data-id');
        });
        var body = new URLSearchParams();
        body.set('csrf', <?= json_encode(csrfToken()) ?>);
        body.set('action', 'reorder');
        ids.forEach(function (id) { body.append('order[]', id); });

        fetch('/dashboard_nav_menu.php', { method: 'POST', body: body })
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

  <div class="section-title">Anteprima menu pubblico</div>
  <div class="card">
    <?php if ($visibleItems): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach ($visibleItems as $it): ?>
          <span class="icon-btn" style="width:auto;padding:0 14px;gap:8px;display:inline-flex;">
            <?php if ($it['icon']): ?><i class="<?= e($it['icon']) ?>"></i><?php endif; ?>
            <?= e($it['name']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <span style="color:var(--text-muted)">Nessuna voce di menu visibile.</span>
    <?php endif; ?>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
