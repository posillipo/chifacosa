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
    } elseif ($action === 'toggle_feed') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE fan_favorite_movies SET show_in_feed = 1 - show_in_feed WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT show_in_feed FROM fan_favorite_movies WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $row, 'show_in_feed' => $row ? (int) $row['show_in_feed'] : null]);
            exit;
        }
    } elseif ($action === 'save_note') {
        $id = (int) ($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $stmt = getDB()->prepare('UPDATE fan_favorite_movies SET note=? WHERE id=? AND user_id=?');
        $stmt->execute([$note !== '' ? $note : null, $id, $profile['id']]);
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'note' => $note]);
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
    <p style="color:var(--text-muted)">
      Ogni film aggiunto ha una sua pagina pubblica dedicata (raggiungibile cliccandoci sopra),
      condivisibile sui social con anteprima immagine/testo — puoi aggiungere una nota per
      spiegare perché ti piace, mostrata proprio lì.
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
      <?php $note = trim($f['note'] ?? ''); ?>
      <div class="link-item" data-fm-favorite="<?= (int)$f['id'] ?>" data-fm-movie-id="<?= e($f['tmdb_movie_id']) ?>" data-fm-note="<?= e($note) ?>" style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <a href="/<?= e($profile['slug']) ?>/film-che-amo/<?= (int)$f['id'] ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
            <?php if ($f['movie_image']): ?>
              <img src="<?= e($f['movie_image']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
            <?php endif; ?>
            <strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($f['movie_title']) ?></strong>
          </a>
          <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;">
            <button type="button" class="btn small danger fm-remove-btn">Rimuovi</button>
            <button type="button" class="btn small<?= $f['show_in_feed'] ? '' : ' secondary' ?> fm-feed-toggle" title="Mostra/nascondi questo elemento nella Timeline/Feed">+Feed</button>
          </div>
        </div>
        <div class="fm-note-block">
          <?php if ($note !== ''): ?>
            <p class="fm-note-text" style="margin:0;font-size:13px;color:var(--text-muted);"><?= nl2br(e($note)) ?></p>
            <button type="button" class="btn small secondary fm-note-toggle" style="margin-top:4px;">Modifica nota</button>
          <?php else: ?>
            <p class="fm-note-text" style="margin:0;font-size:13px;color:var(--text-muted);display:none;"></p>
            <button type="button" class="btn small secondary fm-note-toggle">+ Aggiungi una nota (perché ti piace)</button>
          <?php endif; ?>
          <form class="fm-note-editor" onsubmit="return false;" style="display:none;margin-top:6px;">
            <textarea class="fm-note-textarea" rows="2" placeholder="Racconta perché ti piace"><?= e($note) ?></textarea>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small fm-note-save">Salva nota</button>
              <button type="button" class="btn small secondary fm-note-cancel">Annulla</button>
            </div>
          </form>
        </div>
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
    const profileSlug = <?= json_encode($profile['slug']) ?>;

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

    function favoriteRowHtml(item) {
      const img = item.movie_image ? '<img src="' + escapeHtml(item.movie_image) + '" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">' : '';
      return '<div style="display:flex;align-items:center;gap:12px;">'
        + '<a href="/' + escapeHtml(profileSlug) + '/film-che-amo/' + item.id + '" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">'
        + img + '<strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(item.movie_title) + '</strong></a>'
        + '<div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;">'
        + '<button type="button" class="btn small danger fm-remove-btn">Rimuovi</button>'
        + '<button type="button" class="btn small fm-feed-toggle" title="Mostra/nascondi questo elemento nella Timeline/Feed">+Feed</button>'
        + '</div></div>'
        + '<div class="fm-note-block">'
        + '<p class="fm-note-text" style="margin:0;font-size:13px;color:var(--text-muted);display:none;"></p>'
        + '<button type="button" class="btn small secondary fm-note-toggle">+ Aggiungi una nota (perché ti piace)</button>'
        + '<form class="fm-note-editor" onsubmit="return false;" style="display:none;margin-top:6px;">'
        + '<textarea class="fm-note-textarea" rows="2" placeholder="Racconta perché ti piace"></textarea>'
        + '<div style="display:flex;gap:8px;margin-top:4px;">'
        + '<button type="button" class="btn small fm-note-save">Salva nota</button>'
        + '<button type="button" class="btn small secondary fm-note-cancel">Annulla</button></div></form></div>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-fm-favorite', item.id);
      div.setAttribute('data-fm-movie-id', item.tmdb_movie_id);
      div.setAttribute('data-fm-note', '');
      div.style.flexDirection = 'column';
      div.style.alignItems = 'stretch';
      div.style.gap = '8px';
      div.innerHTML = favoriteRowHtml(item);
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

    // Rimuovi/nota (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const removeBtn = e.target.closest('.fm-remove-btn');
      const feedToggleBtn = e.target.closest('.fm-feed-toggle');
      const noteToggleBtn = e.target.closest('.fm-note-toggle');
      const noteCancelBtn = e.target.closest('.fm-note-cancel');
      const noteSaveBtn = e.target.closest('.fm-note-save');

      if (removeBtn) {
        if (!confirm('Rimuovere questo film dalla tua lista?')) return;
        const row = removeBtn.closest('[data-fm-favorite]');
        const id = row.getAttribute('data-fm-favorite');
        const movieId = row.getAttribute('data-fm-movie-id');
        removeBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'remove');
        params.set('id', id);
        post(params).then(function (data) {
          if (data.ok) {
            row.remove();
            updateListTitle();
            markResultAsRemovable(movieId);
          } else {
            removeBtn.disabled = false;
          }
        }).catch(function () { removeBtn.disabled = false; });
        return;
      }

      if (feedToggleBtn) {
        const row = feedToggleBtn.closest('[data-fm-favorite]');
        const id = row.getAttribute('data-fm-favorite');
        feedToggleBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'toggle_feed');
        params.set('id', id);
        post(params).then(function (data) {
          feedToggleBtn.disabled = false;
          if (!data.ok) return;
          feedToggleBtn.classList.toggle('secondary', !data.show_in_feed);
        }).catch(function () { feedToggleBtn.disabled = false; });
        return;
      }

      if (noteToggleBtn) {
        const block = noteToggleBtn.closest('.fm-note-block');
        block.querySelector('.fm-note-editor').style.display = 'block';
        block.querySelector('.fm-note-textarea').focus();
        return;
      }

      if (noteCancelBtn) {
        const block = noteCancelBtn.closest('.fm-note-block');
        const row = noteCancelBtn.closest('[data-fm-favorite]');
        block.querySelector('.fm-note-textarea').value = row.getAttribute('data-fm-note') || '';
        block.querySelector('.fm-note-editor').style.display = 'none';
        return;
      }

      if (noteSaveBtn) {
        const block = noteSaveBtn.closest('.fm-note-block');
        const row = noteSaveBtn.closest('[data-fm-favorite]');
        const id = row.getAttribute('data-fm-favorite');
        const note = block.querySelector('.fm-note-textarea').value;
        noteSaveBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'save_note');
        params.set('id', id);
        params.set('note', note);
        post(params).then(function (data) {
          noteSaveBtn.disabled = false;
          if (!data.ok) return;
          row.setAttribute('data-fm-note', note);
          const textEl = block.querySelector('.fm-note-text');
          const toggleEl = block.querySelector('.fm-note-toggle');
          if (note.trim() !== '') {
            textEl.innerHTML = escapeHtml(note).replace(/\n/g, '<br>');
            textEl.style.display = 'block';
            toggleEl.textContent = 'Modifica nota';
          } else {
            textEl.style.display = 'none';
            toggleEl.textContent = '+ Aggiungi una nota (perché ti piace)';
          }
          block.querySelector('.fm-note-editor').style.display = 'none';
        }).catch(function () { noteSaveBtn.disabled = false; });
      }
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
