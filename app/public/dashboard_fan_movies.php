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
$error = null;

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
            // show_in_feed=0 di proposito: un elemento appena aggiunto parte "Solo io", la
            // pubblicazione nel Feed va confermata a mano dal pannello di pubblicazione.
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_movies
                (user_id, tmdb_movie_id, movie_title, movie_image, show_in_feed, sort_order)
                VALUES (?, ?, ?, ?, 0, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_movies WHERE user_id=?) t))');
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
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_movies WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteCoverFile($row['image_thumb_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_movies WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true]);
            exit;
        }
    } elseif ($action === 'save_details') {
        // Pannello di pubblicazione per il singolo elemento: stessa logica della Timeline
        // (testo con AI, foto opzionale, Pubblico/Solo io, programmazione, link personalizzato
        // per il feed) — vedi dashboard_post.php per il modello originale.
        $id = (int) ($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $showInFeed = $visibility === 'public' ? 1 : 0;

        $scheduleRaw = trim($_POST['publish_at'] ?? '');
        $publishAt = null;
        if ($scheduleRaw !== '') {
            $ts = strtotime($scheduleRaw);
            if ($ts && $ts > time()) {
                $publishAt = date('Y-m-d H:i:s', $ts);
            }
        }

        // Link personalizzato per il feed: impostazione di profilo condivisa con la Timeline
        // (non del singolo elemento) — cambiarlo da qui vale per tutti i contenuti.
        $customFeedGuid = trim($_POST['custom_feed_guid'] ?? '');
        if ($customFeedGuid !== '' && !filter_var($customFeedGuid, FILTER_VALIDATE_URL)) {
            $error = 'Il link personalizzato per il feed non è un URL valido.';
        } else {
            $customFeedGuid = $customFeedGuid ?: null;
            $customFeedGuidSince = $profile['custom_feed_guid_since'] ?? null;
            if ($customFeedGuid !== ($profile['custom_feed_guid'] ?? null)) {
                $customFeedGuidSince = $customFeedGuid ? date('Y-m-d H:i:s') : null;
            }
            $stmt = getDB()->prepare('UPDATE profiles SET custom_feed_guid=?, custom_feed_guid_since=? WHERE user_id=?');
            $stmt->execute([$customFeedGuid, $customFeedGuidSince, $profile['id']]);
            $user = currentUser();
            $profile = getActingProfile($user);
        }

        $imagePath = handleCoverUpload($profile['slug'], 'image');
        $imageThumbPath = null;
        if ($imagePath) {
            $thumbData = $_POST['image_thumb_data'] ?? '';
            if ($thumbData !== '' && preg_match('#^data:image/jpeg;base64,#', $thumbData)) {
                $raw = base64_decode(substr($thumbData, strpos($thumbData, ',') + 1), true);
                if ($raw !== false && strlen($raw) > 0 && strlen($raw) < 2 * 1024 * 1024) {
                    $fname = 'thumb_' . bin2hex(random_bytes(6)) . '.jpg';
                    $dir = __DIR__ . '/uploads/images/' . $profile['slug'];
                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                    if (file_put_contents($dir . '/' . $fname, $raw) !== false) {
                        $imageThumbPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
                    }
                }
            }
            $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_movies WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            if ($old = $stmt->fetch()) {
                deleteCoverFile($old['image_path']);
                deleteCoverFile($old['image_thumb_path']);
            }
            $stmt = getDB()->prepare('UPDATE fan_favorite_movies SET note=?, show_in_feed=?, publish_at=?, image_path=?, image_thumb_path=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $showInFeed, $publishAt, $imagePath, $imageThumbPath, $id, $profile['id']]);
        } else {
            $stmt = getDB()->prepare('UPDATE fan_favorite_movies SET note=?, show_in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $showInFeed, $publishAt, $id, $profile['id']]);
        }

        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_movies WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $row, 'item' => $row, 'error' => $error]);
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

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_movies WHERE user_id=? ORDER BY sort_order DESC');
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
      condivisibile sui social con anteprima immagine/testo. Da "✏️ Gestisci pubblicazione" puoi
      scrivere perché ti piace (anche con l'aiuto dell'AI), aggiungere una foto, decidere se deve
      comparire nel Feed (Pubblico/Solo io), programmarne la comparsa per una data futura e
      impostare il link personalizzato per il feed — stessa logica della Timeline.
    </p>
    <p style="color:var(--text-muted)">
      Ogni nuovo elemento aggiunto parte impostato su <strong>Solo io</strong>: resta visibile
      nella tua lista e nella sua pagina, ma compare nel Feed solo dopo che lo confermi come
      Pubblico da "✏️ Gestisci pubblicazione".
    </p>
  </details>

  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

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
      <?php
        $note = trim($f['note'] ?? '');
        $isPrivate = !$f['show_in_feed'];
        $isScheduled = $f['publish_at'] && strtotime($f['publish_at']) > time();
      ?>
      <div class="link-item" data-fm-favorite="<?= (int)$f['id'] ?>" data-fm-movie-id="<?= e($f['tmdb_movie_id']) ?>"
           data-fm-note="<?= e($note) ?>" data-fm-has-image="<?= $f['image_path'] ? '1' : '0' ?>"
           style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <a href="/<?= e($profile['slug']) ?>/film-che-amo/<?= (int)$f['id'] ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
            <?php if ($f['movie_image']): ?>
              <img src="<?= e($f['movie_image']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
            <?php endif; ?>
            <strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($f['movie_title']) ?></strong>
          </a>
          <button type="button" class="btn small danger fm-remove-btn" style="flex-shrink:0;">Rimuovi</button>
        </div>
        <div class="fm-pub-badges" style="display:flex;gap:6px;flex-wrap:wrap;<?= (!$isScheduled && !$isPrivate) ? 'display:none;' : '' ?>">
          <?php if ($isScheduled): ?><span class="fm-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il <?= e(date('d/m/Y H:i', strtotime($f['publish_at']))) ?></span><?php endif; ?>
          <?php if ($isPrivate): ?><span class="fm-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span><?php endif; ?>
        </div>
        <div class="fm-pub-block">
          <?php if ($note !== ''): ?>
            <p class="fm-pub-text" style="margin:0;font-size:14px;"><?= nl2br(e($note)) ?></p>
          <?php else: ?>
            <p class="fm-pub-text" style="margin:0;font-size:14px;display:none;"></p>
          <?php endif; ?>
          <button type="button" class="btn small secondary fm-pub-toggle">✏️ Gestisci pubblicazione</button>
          <form class="fm-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">
            <label>Racconta perché ti piace</label>
            <textarea class="fm-pub-textarea" rows="3" placeholder="Racconta perché ti piace"><?= e($note) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">
              <button type="button" class="btn small secondary fm-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="fm-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="fm-ai-keywords" rows="2" placeholder="es. il finale mi ha lasciato senza parole"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small fm-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary fm-ai-cancel">Annulla</button>
              </div>
              <p class="fm-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>

            <label>Foto (opzionale)</label>
            <input type="file" class="fm-pub-image-input" accept="image/*">
            <input type="hidden" class="fm-pub-image-thumb-data">
            <?php if ($f['image_path']): ?><p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Hai già caricato una foto — seleziona un nuovo file per sostituirla.</p><?php endif; ?>

            <label>Privacy (comparsa nel Feed)</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="fm-pub-visibility" name="visibility" value="public" <?= $isPrivate ? '' : 'checked' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="fm-pub-visibility" name="visibility" value="private" <?= $isPrivate ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>

            <label>Programma la comparsa nel Feed (opzionale)</label>
            <input type="datetime-local" class="fm-pub-publish-at" value="<?= $f['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($f['publish_at']))) : '' ?>">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per mostrarlo subito nel Feed (se Pubblico). Resta comunque sempre visibile in questa lista e nella sua pagina.</p>

            <label>Link personalizzato per il feed (opzionale)</label>
            <input type="url" class="fm-pub-custom-link" value="<?= e($profile['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
            <?php if (!empty($profile['custom_feed_guid_since'])): ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Attivo dal <?= e(date('d/m/Y H:i', strtotime($profile['custom_feed_guid_since']))) ?>. Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php else: ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php endif; ?>

            <p class="fm-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small fm-pub-save">Salva</button>
              <button type="button" class="btn small secondary fm-pub-cancel">Annulla</button>
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

    function postForm(formData) {
      formData.set('csrf', csrfInput.value);
      formData.set('ajax', '1');
      return fetch('/dashboard_fan_movies.php', { method: 'POST', body: formData }).then(r => r.json());
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
      const badges = renderBadges(item);
      const img = item.movie_image ? '<img src="' + escapeHtml(item.movie_image) + '" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">' : '';
      const customLink = <?= json_encode($profile['custom_feed_guid'] ?? '') ?>;
      const customLinkSince = <?= json_encode($profile['custom_feed_guid_since'] ?? '') ?>;
      const sinceHint = customLinkSince
        ? 'Attivo dal ' + escapeHtml(customLinkSince) + '. Vale per tutti i contenuti del profilo, non solo per questo elemento.'
        : 'Vale per tutti i contenuti del profilo, non solo per questo elemento.';
      return '<div style="display:flex;align-items:center;gap:12px;">'
        + '<a href="/' + escapeHtml(profileSlug) + '/film-che-amo/' + item.id + '" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">'
        + img + '<strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(item.movie_title) + '</strong></a>'
        + '<button type="button" class="btn small danger fm-remove-btn" style="flex-shrink:0;">Rimuovi</button></div>'
        + '<div class="fm-pub-badges" style="display:' + (badges.visible ? 'flex' : 'none') + ';gap:6px;flex-wrap:wrap;">' + badges.html + '</div>'
        + '<div class="fm-pub-block">'
        + '<p class="fm-pub-text" style="margin:0;font-size:14px;display:none;"></p>'
        + '<button type="button" class="btn small secondary fm-pub-toggle">✏️ Gestisci pubblicazione</button>'
        + '<form class="fm-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">'
        + '<label>Racconta perché ti piace</label>'
        + '<textarea class="fm-pub-textarea" rows="3" placeholder="Racconta perché ti piace"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">'
        + '<button type="button" class="btn small secondary fm-ai-toggle">✨ Genera con AI</button></div>'
        + '<div class="fm-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">'
        + '<label>Qualche parola chiave o istruzione per l\'AI</label>'
        + '<textarea class="fm-ai-keywords" rows="2" placeholder="es. il finale mi ha lasciato senza parole"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
        + '<button type="button" class="btn small fm-ai-generate">Genera testo</button>'
        + '<button type="button" class="btn small secondary fm-ai-cancel">Annulla</button></div>'
        + '<p class="fm-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p></div>'
        + '<label>Foto (opzionale)</label>'
        + '<input type="file" class="fm-pub-image-input" accept="image/*">'
        + '<input type="hidden" class="fm-pub-image-thumb-data">'
        + '<label>Privacy (comparsa nel Feed)</label>'
        + '<div style="display:flex;gap:16px;margin-bottom:14px;">'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="fm-pub-visibility" name="visibility" value="public"' + (item.show_in_feed ? ' checked' : '') + ' style="width:auto;"> Pubblico</label>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="fm-pub-visibility" name="visibility" value="private"' + (!item.show_in_feed ? ' checked' : '') + ' style="width:auto;"> Solo io</label></div>'
        + '<label>Programma la comparsa nel Feed (opzionale)</label>'
        + '<input type="datetime-local" class="fm-pub-publish-at">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per mostrarlo subito nel Feed (se Pubblico). Resta comunque sempre visibile in questa lista e nella sua pagina.</p>'
        + '<label>Link personalizzato per il feed (opzionale)</label>'
        + '<input type="url" class="fm-pub-custom-link" value="' + escapeHtml(customLink) + '" placeholder="https://...">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">' + sinceHint + '</p>'
        + '<p class="fm-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>'
        + '<div style="display:flex;gap:8px;margin-top:4px;">'
        + '<button type="button" class="btn small fm-pub-save">Salva</button>'
        + '<button type="button" class="btn small secondary fm-pub-cancel">Annulla</button></div></form></div>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-fm-favorite', item.id);
      div.setAttribute('data-fm-movie-id', item.tmdb_movie_id);
      div.setAttribute('data-fm-note', '');
      div.setAttribute('data-fm-has-image', '0');
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

    // Genera una miniatura JPEG leggera (max 320px) dalla foto selezionata, nel browser — vedi
    // stessa logica in dashboard_post.php.
    function generateThumb(file, callback) {
      const img = new Image();
      const reader = new FileReader();
      reader.onload = function (e) {
        img.onload = function () {
          const maxDim = 320;
          const scale = Math.min(1, maxDim / Math.max(img.width, img.height));
          const canvas = document.createElement('canvas');
          canvas.width = Math.round(img.width * scale);
          canvas.height = Math.round(img.height * scale);
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
          callback(canvas.toDataURL('image/jpeg', 0.82));
        };
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);
    }

    function renderBadges(item) {
      const isScheduled = item.publish_at && new Date(item.publish_at.replace(' ', 'T')).getTime() > Date.now();
      const isPrivate = !item.show_in_feed || item.show_in_feed == 0;
      let html = '';
      if (isScheduled) html += '<span class="fm-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il ' + escapeHtml(item.publish_at) + '</span>';
      if (isPrivate) html += '<span class="fm-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span>';
      return { html: html, visible: isScheduled || isPrivate };
    }

    // Rimuovi/pubblicazione (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const removeBtn = e.target.closest('.fm-remove-btn');
      const pubToggleBtn = e.target.closest('.fm-pub-toggle');
      const pubCancelBtn = e.target.closest('.fm-pub-cancel');
      const pubSaveBtn = e.target.closest('.fm-pub-save');
      const aiToggleBtn = e.target.closest('.fm-ai-toggle');
      const aiCancelBtn = e.target.closest('.fm-ai-cancel');
      const aiGenerateBtn = e.target.closest('.fm-ai-generate');

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

      if (pubToggleBtn) {
        const block = pubToggleBtn.closest('.fm-pub-block');
        block.querySelector('.fm-pub-editor').style.display = 'block';
        block.querySelector('.fm-pub-textarea').focus();
        return;
      }

      if (pubCancelBtn) {
        const block = pubCancelBtn.closest('.fm-pub-block');
        const row = pubCancelBtn.closest('[data-fm-favorite]');
        block.querySelector('.fm-pub-textarea').value = row.getAttribute('data-fm-note') || '';
        block.querySelector('.fm-pub-editor').style.display = 'none';
        return;
      }

      if (aiToggleBtn) {
        const panel = aiToggleBtn.closest('.fm-pub-editor').querySelector('.fm-ai-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') panel.querySelector('.fm-ai-keywords').focus();
        return;
      }

      if (aiCancelBtn) {
        const panel = aiCancelBtn.closest('.fm-ai-panel');
        panel.style.display = 'none';
        panel.querySelector('.fm-ai-status').textContent = '';
        return;
      }

      if (aiGenerateBtn) {
        const panel = aiGenerateBtn.closest('.fm-ai-panel');
        const editor = aiGenerateBtn.closest('.fm-pub-editor');
        const keywords = panel.querySelector('.fm-ai-keywords').value.trim();
        const statusEl = panel.querySelector('.fm-ai-status');
        if (!keywords) {
          statusEl.textContent = 'Scrivi almeno qualche parola chiave.';
          return;
        }
        aiGenerateBtn.disabled = true;
        statusEl.textContent = 'Generazione in corso...';
        const body = new URLSearchParams();
        body.set('csrf', csrfInput.value);
        body.set('keywords', keywords);
        fetch('/dashboard_ai_caption.php', { method: 'POST', body: body })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            aiGenerateBtn.disabled = false;
            if (data.ok) {
              editor.querySelector('.fm-pub-textarea').value = data.text;
              statusEl.textContent = 'Fatto! Puoi modificare il testo prima di salvare.';
            } else {
              statusEl.textContent = data.error || 'Qualcosa è andato storto.';
            }
          })
          .catch(function () {
            aiGenerateBtn.disabled = false;
            statusEl.textContent = 'Errore di connessione. Riprova.';
          });
        return;
      }

      if (pubSaveBtn) {
        const editor = pubSaveBtn.closest('.fm-pub-editor');
        const block = pubSaveBtn.closest('.fm-pub-block');
        const row = pubSaveBtn.closest('[data-fm-favorite]');
        const id = row.getAttribute('data-fm-favorite');
        const note = editor.querySelector('.fm-pub-textarea').value;
        const visibility = editor.querySelector('.fm-pub-visibility:checked').value;
        const publishAt = editor.querySelector('.fm-pub-publish-at').value;
        const customLink = editor.querySelector('.fm-pub-custom-link').value;
        const imageInput = editor.querySelector('.fm-pub-image-input');
        const statusEl = editor.querySelector('.fm-pub-status');
        const file = imageInput.files && imageInput.files[0];

        function submit(thumbDataUrl) {
          pubSaveBtn.disabled = true;
          statusEl.textContent = 'Salvataggio...';
          const formData = new FormData();
          formData.set('action', 'save_details');
          formData.set('id', id);
          formData.set('note', note);
          formData.set('visibility', visibility);
          formData.set('publish_at', publishAt);
          formData.set('custom_feed_guid', customLink);
          if (file) {
            formData.set('image', file);
            formData.set('image_thumb_data', thumbDataUrl || '');
          }
          postForm(formData).then(function (data) {
            pubSaveBtn.disabled = false;
            if (!data.ok) {
              statusEl.textContent = data.error || 'Salvataggio non riuscito, riprova.';
              return;
            }
            statusEl.textContent = data.error ? data.error : '';
            row.setAttribute('data-fm-note', note);
            row.setAttribute('data-fm-has-image', data.item.image_path ? '1' : '0');
            const textEl = block.querySelector('.fm-pub-text');
            const toggleEl = block.querySelector('.fm-pub-toggle');
            if (note.trim() !== '') {
              textEl.innerHTML = escapeHtml(note).replace(/\n/g, '<br>');
              textEl.style.display = 'block';
            } else {
              textEl.style.display = 'none';
            }
            toggleEl.textContent = '✏️ Gestisci pubblicazione';
            const badges = renderBadges(data.item);
            const badgesBox = row.querySelector('.fm-pub-badges');
            badgesBox.innerHTML = badges.html;
            badgesBox.style.display = badges.visible ? 'flex' : 'none';
            editor.style.display = 'none';
          }).catch(function () {
            pubSaveBtn.disabled = false;
            statusEl.textContent = 'Errore di connessione. Riprova.';
          });
        }

        if (file) {
          generateThumb(file, submit);
        } else {
          submit(null);
        }
        return;
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
