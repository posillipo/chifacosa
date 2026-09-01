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

    $isAjax = !empty($_POST['ajax']);

    if ($action === 'add') {
        $artistId = trim($_POST['artist_id'] ?? '');
        $artistName = trim($_POST['artist_name'] ?? '');
        $artistImage = trim($_POST['artist_image'] ?? '');
        $addedRow = null;
        if ($artistId !== '') {
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_bands
                (user_id, spotify_artist_id, spotify_artist_name, artist_image, sort_order)
                VALUES (?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_bands WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $artistId, $artistName, $artistImage ?: null, $profile['id']]);
            // Non ci si fida di lastInsertId(): con INSERT IGNORE su un duplicato resterebbe a 0
            // o non aggiornato — si rilegge sempre la riga vera dal database.
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_bands WHERE user_id=? AND spotify_artist_id=?');
            $stmt->execute([$profile['id'], $artistId]);
            $addedRow = $stmt->fetch() ?: null;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $addedRow, 'item' => $addedRow]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_bands WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true]);
            exit;
        }
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = spotifySearchArtist($searchQuery);
        }
        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT spotify_artist_id FROM fan_favorite_bands WHERE user_id=?');
            $stmt->execute([$profile['id']]);
            $favIds = array_column($stmt->fetchAll(), 'spotify_artist_id');
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $searchResults, 'favoriteIds' => $favIds]);
            exit;
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
      pagina pubblica come vetrina di ciò che ascolti. La ricerca parte da sola mentre scrivi,
      e aggiungere/rimuovere una band aggiorna la lista all'istante, senza ricaricare la pagina.
    </p>
  </details>

  <form method="post" class="card" id="fb-search-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca una band/artista su Spotify</label>
    <input type="text" name="query" id="fb-search-input" value="<?= e($searchQuery) ?>" placeholder="es. nome della band" autocomplete="off">
    <button type="submit" class="btn">Cerca</button>
    <p id="fb-search-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
  </form>

  <div id="fb-search-results">
    <?php if ($searchResults): ?>
      <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
      <?php foreach ($searchResults as $r): ?>
        <div class="link-item" data-fb-result="<?= e($r['id']) ?>">
          <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($r['image']): ?>
              <img src="<?= e($r['image']) ?>" alt="" style="width:44px;height:44px;border-radius:50%;">
            <?php endif; ?>
            <strong><?= e($r['name']) ?></strong>
          </div>
          <?php if (in_array($r['id'], $favoriteIds, true)): ?>
            <span class="fb-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>
          <?php else: ?>
            <button type="button" class="btn small fb-add-btn"
              data-artist-id="<?= e($r['id']) ?>" data-artist-name="<?= e($r['name']) ?>" data-artist-image="<?= e($r['image'] ?? '') ?>">
              Aggiungi alla lista
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="card">Nessun risultato per questa ricerca.</div>
    <?php endif; ?>
  </div>

  <div class="section-title" id="fb-list-title">La tua lista (<?= count($favorites) ?>)</div>
  <div id="fb-list-empty" class="alert error" style="<?= $favorites ? 'display:none;' : '' ?>">Nessuna band aggiunta ancora — cercala qui sopra.</div>
  <div id="fb-list">
    <?php foreach ($favorites as $f): ?>
      <div class="link-item" data-fb-favorite="<?= (int)$f['id'] ?>" data-fb-artist-id="<?= e($f['spotify_artist_id']) ?>">
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($f['artist_image']): ?>
            <img src="<?= e($f['artist_image']) ?>" style="width:44px;height:44px;border-radius:50%;">
          <?php endif; ?>
          <strong><?= e($f['spotify_artist_name']) ?></strong>
        </div>
        <button type="button" class="btn small danger fb-remove-btn">Rimuovi</button>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function () {
    const csrfInput = document.querySelector('#fb-search-form input[name="csrf"]');
    const searchForm = document.getElementById('fb-search-form');
    const searchInput = document.getElementById('fb-search-input');
    const searchStatus = document.getElementById('fb-search-status');
    const resultsBox = document.getElementById('fb-search-results');
    const listBox = document.getElementById('fb-list');
    const listTitle = document.getElementById('fb-list-title');
    const listEmpty = document.getElementById('fb-list-empty');

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function updateListTitle() {
      const n = listBox.children.length;
      listTitle.textContent = 'La tua lista (' + n + ')';
      listEmpty.style.display = n === 0 ? 'block' : 'none';
    }

    function post(params) {
      params.set('csrf', csrfInput.value);
      params.set('ajax', '1');
      return fetch('/dashboard_fan_bands.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function markResultAsAdded(artistId) {
      const row = resultsBox.querySelector('[data-fb-result="' + CSS.escape(artistId) + '"]');
      if (!row) return;
      const btn = row.querySelector('.fb-add-btn');
      if (btn) btn.outerHTML = '<span class="fb-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
    }

    function markResultAsRemovable(artistId) {
      const row = resultsBox.querySelector('[data-fb-result="' + CSS.escape(artistId) + '"]');
      if (!row) return;
      const span = row.querySelector('.fb-already');
      if (!span) return;
      const name = row.querySelector('strong').textContent;
      const img = row.querySelector('img');
      span.outerHTML = '<button type="button" class="btn small fb-add-btn" data-artist-id="' + escapeHtml(artistId)
        + '" data-artist-name="' + escapeHtml(name) + '" data-artist-image="' + escapeHtml(img ? img.getAttribute('src') : '') + '">Aggiungi alla lista</button>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-fb-favorite', item.id);
      div.setAttribute('data-fb-artist-id', item.spotify_artist_id);
      div.innerHTML = '<div style="display:flex;align-items:center;gap:12px;">'
        + (item.artist_image ? '<img src="' + escapeHtml(item.artist_image) + '" style="width:44px;height:44px;border-radius:50%;">' : '')
        + '<strong>' + escapeHtml(item.spotify_artist_name) + '</strong></div>'
        + '<button type="button" class="btn small danger fb-remove-btn">Rimuovi</button>';
      listBox.prepend(div);
      updateListTitle();
    }

    // Aggiungi (delegato: i risultati di ricerca vengono ricreati a ogni ricerca)
    resultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.fb-add-btn');
      if (!btn) return;
      btn.disabled = true;
      const artistId = btn.dataset.artistId;
      const params = new URLSearchParams();
      params.set('action', 'add');
      params.set('artist_id', artistId);
      params.set('artist_name', btn.dataset.artistName);
      params.set('artist_image', btn.dataset.artistImage || '');
      post(params).then(function (data) {
        if (data.ok && data.item) {
          markResultAsAdded(artistId);
          addFavoriteRow(data.item);
        } else {
          btn.disabled = false;
        }
      }).catch(function () { btn.disabled = false; });
    });

    // Rimuovi (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.fb-remove-btn');
      if (!btn) return;
      if (!confirm('Rimuovere questa band dalla tua lista?')) return;
      const row = btn.closest('[data-fb-favorite]');
      const id = row.getAttribute('data-fb-favorite');
      const artistId = row.getAttribute('data-fb-artist-id');
      btn.disabled = true;
      const params = new URLSearchParams();
      params.set('action', 'remove');
      params.set('id', id);
      post(params).then(function (data) {
        if (data.ok) {
          row.remove();
          updateListTitle();
          markResultAsRemovable(artistId);
        } else {
          btn.disabled = false;
        }
      }).catch(function () { btn.disabled = false; });
    });

    // Ricerca live: parte da sola mentre scrivi (con una breve pausa), oltre al pulsante "Cerca"
    // per chi preferisce premere Invio o non ha JavaScript attivo.
    function renderResults(results, favIds) {
      if (!results.length) {
        resultsBox.innerHTML = '<div class="card">Nessun risultato per questa ricerca.</div>';
        return;
      }
      let html = '<div class="section-title">Risultati (' + results.length + ')</div>';
      results.forEach(function (r) {
        html += '<div class="link-item" data-fb-result="' + escapeHtml(r.id) + '">'
          + '<div style="display:flex;align-items:center;gap:12px;">'
          + (r.image ? '<img src="' + escapeHtml(r.image) + '" alt="" style="width:44px;height:44px;border-radius:50%;">' : '')
          + '<strong>' + escapeHtml(r.name) + '</strong></div>';
        if (favIds.indexOf(r.id) !== -1) {
          html += '<span class="fb-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
        } else {
          html += '<button type="button" class="btn small fb-add-btn" data-artist-id="' + escapeHtml(r.id)
            + '" data-artist-name="' + escapeHtml(r.name) + '" data-artist-image="' + escapeHtml(r.image || '') + '">Aggiungi alla lista</button>';
        }
        html += '</div>';
      });
      resultsBox.innerHTML = html;
    }

    function runSearch(query) {
      if (query.trim() === '') { resultsBox.innerHTML = ''; searchStatus.textContent = ''; return; }
      searchStatus.textContent = 'Ricerca in corso...';
      const params = new URLSearchParams();
      params.set('action', 'search');
      params.set('query', query);
      post(params).then(function (data) {
        searchStatus.textContent = '';
        renderResults(data.results || [], data.favoriteIds || []);
      }).catch(function () {
        searchStatus.textContent = 'Ricerca non riuscita, riprova.';
      });
    }

    let debounceTimer = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      const query = searchInput.value;
      debounceTimer = setTimeout(function () { runSearch(query); }, 400);
    });
    searchForm.addEventListener('submit', function (e) {
      e.preventDefault();
      clearTimeout(debounceTimer);
      runSearch(searchInput.value);
    });
  })();
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
