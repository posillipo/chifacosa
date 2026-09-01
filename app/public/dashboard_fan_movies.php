<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/tmdb.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'fan_movies';
$pageTitle = 'Film che amo';

$searchResults = [];
$searchQuery = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    $isAjax = !empty($_POST['ajax']);

    if ($action === 'add') {
        $movieId = trim($_POST['movie_id'] ?? '');
        $movieTitle = trim($_POST['movie_title'] ?? '');
        $movieImage = trim($_POST['movie_image'] ?? '');
        $addedRow = null;
        if ($movieId !== '') {
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_movies
                (user_id, tmdb_movie_id, movie_title, movie_image, sort_order)
                VALUES (?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_movies WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $movieId, $movieTitle, $movieImage ?: null, $profile['id']]);
            // Non ci si fida di lastInsertId(): con INSERT IGNORE su un duplicato resterebbe a 0
            // o non aggiornato — si rilegge sempre la riga vera dal database.
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_movies WHERE user_id=? AND tmdb_movie_id=?');
            $stmt->execute([$profile['id'], $movieId]);
            $addedRow = $stmt->fetch() ?: null;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $addedRow, 'item' => $addedRow]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_movies WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true]);
            exit;
        }
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = tmdbSearchMovie($searchQuery);
        }
        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT tmdb_movie_id FROM fan_favorite_movies WHERE user_id=?');
            $stmt->execute([$profile['id']]);
            $favIds = array_column($stmt->fetchAll(), 'tmdb_movie_id');
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $searchResults, 'favoriteIds' => $favIds]);
            exit;
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_movies WHERE user_id=? ORDER BY sort_order ASC');
$stmt->execute([$profile['id']]);
$favorites = $stmt->fetchAll();
$favoriteIds = array_column($favorites, 'tmdb_movie_id');

include __DIR__ . '/_dash_header.php';
?>
  <?php if (!getTmdbApiKey()): ?>
    <div class="alert error">
      La ricerca film non è ancora attiva sul sito — manca la chiave API TMDb.
      Chiedi all'amministratore di configurarla (ADMIN → TMDb).
    </div>
  <?php endif; ?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca film (catalogo TMDb) e aggiungili alla tua lista — qualsiasi film esista su TMDb.
      Comparirà sulla tua pagina pubblica come vetrina di ciò che ami guardare. La ricerca parte
      da sola mentre scrivi, e aggiungere/rimuovere un film aggiorna la lista all'istante, senza
      ricaricare la pagina.
    </p>
  </details>

  <form method="post" class="card" id="fm-search-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca un film su TMDb</label>
    <input type="text" name="query" id="fm-search-input" value="<?= e($searchQuery) ?>" placeholder="es. titolo del film" autocomplete="off">
    <button type="submit" class="btn">Cerca</button>
    <p id="fm-search-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
  </form>

  <div id="fm-search-results">
    <?php if ($searchResults): ?>
      <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
      <?php foreach ($searchResults as $r): ?>
        <div class="link-item" data-fm-result="<?= e($r['id']) ?>">
          <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($r['image']): ?>
              <img src="<?= e($r['image']) ?>" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
            <?php endif; ?>
            <strong><?= e($r['name']) ?></strong>
          </div>
          <?php if (in_array($r['id'], $favoriteIds, true)): ?>
            <span class="fm-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>
          <?php else: ?>
            <button type="button" class="btn small fm-add-btn"
              data-movie-id="<?= e($r['id']) ?>" data-movie-title="<?= e($r['name']) ?>" data-movie-image="<?= e($r['image'] ?? '') ?>">
              Aggiungi alla lista
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="card">Nessun risultato per questa ricerca.</div>
    <?php endif; ?>
  </div>

  <div class="section-title" id="fm-list-title">La tua lista (<?= count($favorites) ?>)</div>
  <div id="fm-list-empty" class="alert error" style="<?= $favorites ? 'display:none;' : '' ?>">Nessun film aggiunto ancora — cercalo qui sopra.</div>
  <div id="fm-list">
    <?php foreach ($favorites as $f): ?>
      <div class="link-item" data-fm-favorite="<?= (int)$f['id'] ?>" data-fm-movie-id="<?= e($f['tmdb_movie_id']) ?>">
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($f['movie_image']): ?>
            <img src="<?= e($f['movie_image']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
          <?php endif; ?>
          <strong><?= e($f['movie_title']) ?></strong>
        </div>
        <button type="button" class="btn small danger fm-remove-btn">Rimuovi</button>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function () {
    const csrfInput = document.querySelector('#fm-search-form input[name="csrf"]');
    const searchForm = document.getElementById('fm-search-form');
    const searchInput = document.getElementById('fm-search-input');
    const searchStatus = document.getElementById('fm-search-status');
    const resultsBox = document.getElementById('fm-search-results');
    const listBox = document.getElementById('fm-list');
    const listTitle = document.getElementById('fm-list-title');
    const listEmpty = document.getElementById('fm-list-empty');

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
      return fetch('/dashboard_fan_movies.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function markResultAsAdded(movieId) {
      const row = resultsBox.querySelector('[data-fm-result="' + CSS.escape(movieId) + '"]');
      if (!row) return;
      const btn = row.querySelector('.fm-add-btn');
      if (btn) btn.outerHTML = '<span class="fm-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
    }

    function markResultAsRemovable(movieId) {
      const row = resultsBox.querySelector('[data-fm-result="' + CSS.escape(movieId) + '"]');
      if (!row) return;
      const span = row.querySelector('.fm-already');
      if (!span) return;
      const title = row.querySelector('strong').textContent;
      const img = row.querySelector('img');
      span.outerHTML = '<button type="button" class="btn small fm-add-btn" data-movie-id="' + escapeHtml(movieId)
        + '" data-movie-title="' + escapeHtml(title) + '" data-movie-image="' + escapeHtml(img ? img.getAttribute('src') : '') + '">Aggiungi alla lista</button>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-fm-favorite', item.id);
      div.setAttribute('data-fm-movie-id', item.tmdb_movie_id);
      div.innerHTML = '<div style="display:flex;align-items:center;gap:12px;">'
        + (item.movie_image ? '<img src="' + escapeHtml(item.movie_image) + '" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">' : '')
        + '<strong>' + escapeHtml(item.movie_title) + '</strong></div>'
        + '<button type="button" class="btn small danger fm-remove-btn">Rimuovi</button>';
      listBox.prepend(div);
      updateListTitle();
    }

    // Aggiungi (delegato: i risultati di ricerca vengono ricreati a ogni ricerca)
    resultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.fm-add-btn');
      if (!btn) return;
      btn.disabled = true;
      const movieId = btn.dataset.movieId;
      const params = new URLSearchParams();
      params.set('action', 'add');
      params.set('movie_id', movieId);
      params.set('movie_title', btn.dataset.movieTitle);
      params.set('movie_image', btn.dataset.movieImage || '');
      post(params).then(function (data) {
        if (data.ok && data.item) {
          markResultAsAdded(movieId);
          addFavoriteRow(data.item);
        } else {
          btn.disabled = false;
        }
      }).catch(function () { btn.disabled = false; });
    });

    // Rimuovi (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.fm-remove-btn');
      if (!btn) return;
      if (!confirm('Rimuovere questo film dalla tua lista?')) return;
      const row = btn.closest('[data-fm-favorite]');
      const id = row.getAttribute('data-fm-favorite');
      const movieId = row.getAttribute('data-fm-movie-id');
      btn.disabled = true;
      const params = new URLSearchParams();
      params.set('action', 'remove');
      params.set('id', id);
      post(params).then(function (data) {
        if (data.ok) {
          row.remove();
          updateListTitle();
          markResultAsRemovable(movieId);
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
        html += '<div class="link-item" data-fm-result="' + escapeHtml(r.id) + '">'
          + '<div style="display:flex;align-items:center;gap:12px;">'
          + (r.image ? '<img src="' + escapeHtml(r.image) + '" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">' : '')
          + '<strong>' + escapeHtml(r.name) + '</strong></div>';
        if (favIds.indexOf(r.id) !== -1) {
          html += '<span class="fm-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
        } else {
          html += '<button type="button" class="btn small fm-add-btn" data-movie-id="' + escapeHtml(r.id)
            + '" data-movie-title="' + escapeHtml(r.name) + '" data-movie-image="' + escapeHtml(r.image || '') + '">Aggiungi alla lista</button>';
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
