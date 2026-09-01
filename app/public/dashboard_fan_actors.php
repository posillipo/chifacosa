<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/tmdb.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'fan_actors';
$pageTitle = 'Attori che amo';

$searchResults = [];
$searchQuery = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    $isAjax = !empty($_POST['ajax']);

    if ($action === 'add') {
        $personId = trim($_POST['person_id'] ?? '');
        $personName = trim($_POST['person_name'] ?? '');
        $personImage = trim($_POST['person_image'] ?? '');
        $addedRow = null;
        if ($personId !== '') {
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_actors
                (user_id, tmdb_person_id, actor_name, actor_image, sort_order)
                VALUES (?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_actors WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $personId, $personName, $personImage ?: null, $profile['id']]);
            // Non ci si fida di lastInsertId(): con INSERT IGNORE su un duplicato resterebbe a 0
            // o non aggiornato — si rilegge sempre la riga vera dal database.
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_actors WHERE user_id=? AND tmdb_person_id=?');
            $stmt->execute([$profile['id'], $personId]);
            $addedRow = $stmt->fetch() ?: null;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $addedRow, 'item' => $addedRow]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_actors WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true]);
            exit;
        }
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = tmdbSearchPerson($searchQuery);
        }
        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT tmdb_person_id FROM fan_favorite_actors WHERE user_id=?');
            $stmt->execute([$profile['id']]);
            $favIds = array_column($stmt->fetchAll(), 'tmdb_person_id');
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $searchResults, 'favoriteIds' => $favIds]);
            exit;
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_actors WHERE user_id=? ORDER BY sort_order ASC');
$stmt->execute([$profile['id']]);
$favorites = $stmt->fetchAll();
$favoriteIds = array_column($favorites, 'tmdb_person_id');

include __DIR__ . '/_dash_header.php';
?>
  <?php if (!getTmdbApiKey()): ?>
    <div class="alert error">
      La ricerca attori non è ancora attiva sul sito — manca la chiave API TMDb.
      Chiedi all'amministratore di configurarla (ADMIN → TMDb).
    </div>
  <?php endif; ?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca attori e attrici (catalogo TMDb) e aggiungili alla tua lista — chiunque esista su
      TMDb, non solo persone registrate su <?= e(siteName()) ?>. Comparirà sulla tua pagina
      pubblica come vetrina di ciò che ami guardare. La ricerca parte da sola mentre scrivi,
      e aggiungere/rimuovere un attore aggiorna la lista all'istante, senza ricaricare la pagina.
    </p>
  </details>

  <form method="post" class="card" id="fa-search-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca un attore/attrice su TMDb</label>
    <input type="text" name="query" id="fa-search-input" value="<?= e($searchQuery) ?>" placeholder="es. nome dell'attore" autocomplete="off">
    <button type="submit" class="btn">Cerca</button>
    <p id="fa-search-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
  </form>

  <div id="fa-search-results">
    <?php if ($searchResults): ?>
      <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
      <?php foreach ($searchResults as $r): ?>
        <div class="link-item" data-fa-result="<?= e($r['id']) ?>">
          <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($r['image']): ?>
              <img src="<?= e($r['image']) ?>" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
            <?php endif; ?>
            <strong><?= e($r['name']) ?></strong>
          </div>
          <?php if (in_array($r['id'], $favoriteIds, true)): ?>
            <span class="fa-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>
          <?php else: ?>
            <button type="button" class="btn small fa-add-btn"
              data-person-id="<?= e($r['id']) ?>" data-person-name="<?= e($r['name']) ?>" data-person-image="<?= e($r['image'] ?? '') ?>">
              Aggiungi alla lista
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="card">Nessun risultato per questa ricerca.</div>
    <?php endif; ?>
  </div>

  <div class="section-title" id="fa-list-title">La tua lista (<?= count($favorites) ?>)</div>
  <div id="fa-list-empty" class="alert error" style="<?= $favorites ? 'display:none;' : '' ?>">Nessun attore aggiunto ancora — cercalo qui sopra.</div>
  <div id="fa-list">
    <?php foreach ($favorites as $f): ?>
      <div class="link-item" data-fa-favorite="<?= (int)$f['id'] ?>" data-fa-person-id="<?= e($f['tmdb_person_id']) ?>">
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($f['actor_image']): ?>
            <img src="<?= e($f['actor_image']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
          <?php endif; ?>
          <strong><?= e($f['actor_name']) ?></strong>
        </div>
        <button type="button" class="btn small danger fa-remove-btn">Rimuovi</button>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function () {
    const csrfInput = document.querySelector('#fa-search-form input[name="csrf"]');
    const searchForm = document.getElementById('fa-search-form');
    const searchInput = document.getElementById('fa-search-input');
    const searchStatus = document.getElementById('fa-search-status');
    const resultsBox = document.getElementById('fa-search-results');
    const listBox = document.getElementById('fa-list');
    const listTitle = document.getElementById('fa-list-title');
    const listEmpty = document.getElementById('fa-list-empty');

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
      return fetch('/dashboard_fan_actors.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function markResultAsAdded(personId) {
      const row = resultsBox.querySelector('[data-fa-result="' + CSS.escape(personId) + '"]');
      if (!row) return;
      const btn = row.querySelector('.fa-add-btn');
      if (btn) btn.outerHTML = '<span class="fa-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
    }

    function markResultAsRemovable(personId) {
      const row = resultsBox.querySelector('[data-fa-result="' + CSS.escape(personId) + '"]');
      if (!row) return;
      const span = row.querySelector('.fa-already');
      if (!span) return;
      const name = row.querySelector('strong').textContent;
      const img = row.querySelector('img');
      span.outerHTML = '<button type="button" class="btn small fa-add-btn" data-person-id="' + escapeHtml(personId)
        + '" data-person-name="' + escapeHtml(name) + '" data-person-image="' + escapeHtml(img ? img.getAttribute('src') : '') + '">Aggiungi alla lista</button>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-fa-favorite', item.id);
      div.setAttribute('data-fa-person-id', item.tmdb_person_id);
      div.innerHTML = '<div style="display:flex;align-items:center;gap:12px;">'
        + (item.actor_image ? '<img src="' + escapeHtml(item.actor_image) + '" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">' : '')
        + '<strong>' + escapeHtml(item.actor_name) + '</strong></div>'
        + '<button type="button" class="btn small danger fa-remove-btn">Rimuovi</button>';
      listBox.prepend(div);
      updateListTitle();
    }

    // Aggiungi (delegato: i risultati di ricerca vengono ricreati a ogni ricerca)
    resultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.fa-add-btn');
      if (!btn) return;
      btn.disabled = true;
      const personId = btn.dataset.personId;
      const params = new URLSearchParams();
      params.set('action', 'add');
      params.set('person_id', personId);
      params.set('person_name', btn.dataset.personName);
      params.set('person_image', btn.dataset.personImage || '');
      post(params).then(function (data) {
        if (data.ok && data.item) {
          markResultAsAdded(personId);
          addFavoriteRow(data.item);
        } else {
          btn.disabled = false;
        }
      }).catch(function () { btn.disabled = false; });
    });

    // Rimuovi (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.fa-remove-btn');
      if (!btn) return;
      if (!confirm('Rimuovere questo attore dalla tua lista?')) return;
      const row = btn.closest('[data-fa-favorite]');
      const id = row.getAttribute('data-fa-favorite');
      const personId = row.getAttribute('data-fa-person-id');
      btn.disabled = true;
      const params = new URLSearchParams();
      params.set('action', 'remove');
      params.set('id', id);
      post(params).then(function (data) {
        if (data.ok) {
          row.remove();
          updateListTitle();
          markResultAsRemovable(personId);
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
        html += '<div class="link-item" data-fa-result="' + escapeHtml(r.id) + '">'
          + '<div style="display:flex;align-items:center;gap:12px;">'
          + (r.image ? '<img src="' + escapeHtml(r.image) + '" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">' : '')
          + '<strong>' + escapeHtml(r.name) + '</strong></div>';
        if (favIds.indexOf(r.id) !== -1) {
          html += '<span class="fa-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
        } else {
          html += '<button type="button" class="btn small fa-add-btn" data-person-id="' + escapeHtml(r.id)
            + '" data-person-name="' + escapeHtml(r.name) + '" data-person-image="' + escapeHtml(r.image || '') + '">Aggiungi alla lista</button>';
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
