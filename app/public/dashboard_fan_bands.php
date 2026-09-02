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
$error = null;

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
            // show_in_feed=0 di proposito: un elemento appena aggiunto parte "Solo io", la
            // pubblicazione nel Feed va confermata a mano dal pannello di pubblicazione.
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_bands
                (user_id, spotify_artist_id, spotify_artist_name, artist_image, show_in_feed, sort_order)
                VALUES (?, ?, ?, ?, 0, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_bands WHERE user_id=?) t))');
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
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_bands WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteCoverFile($row['image_thumb_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_bands WHERE id=? AND user_id=?');
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
            $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_bands WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            if ($old = $stmt->fetch()) {
                deleteCoverFile($old['image_path']);
                deleteCoverFile($old['image_thumb_path']);
            }
            $stmt = getDB()->prepare('UPDATE fan_favorite_bands SET note=?, show_in_feed=?, publish_at=?, image_path=?, image_thumb_path=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $showInFeed, $publishAt, $imagePath, $imageThumbPath, $id, $profile['id']]);
        } else {
            $stmt = getDB()->prepare('UPDATE fan_favorite_bands SET note=?, show_in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $showInFeed, $publishAt, $id, $profile['id']]);
        }

        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_bands WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $row, 'item' => $row, 'error' => $error]);
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

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_bands WHERE user_id=? ORDER BY sort_order DESC');
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
    <p style="color:var(--text-muted)">
      Ogni band aggiunta ha una sua pagina pubblica dedicata (raggiungibile cliccandoci sopra),
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
      <?php
        $note = trim($f['note'] ?? '');
        $isPrivate = !$f['show_in_feed'];
        $isScheduled = $f['publish_at'] && strtotime($f['publish_at']) > time();
      ?>
      <div class="link-item" data-fb-favorite="<?= (int)$f['id'] ?>" data-fb-artist-id="<?= e($f['spotify_artist_id']) ?>"
           data-fb-note="<?= e($note) ?>" data-fb-has-image="<?= $f['image_path'] ? '1' : '0' ?>"
           style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <a href="/<?= e($profile['slug']) ?>/band-che-amo/<?= (int)$f['id'] ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
            <?php if ($f['artist_image']): ?>
              <img src="<?= e($f['artist_image']) ?>" style="width:44px;height:44px;border-radius:50%;flex-shrink:0;">
            <?php endif; ?>
            <strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($f['spotify_artist_name']) ?></strong>
          </a>
          <button type="button" class="btn small danger fb-remove-btn" style="flex-shrink:0;">Rimuovi</button>
        </div>
        <div class="fb-pub-badges" style="display:flex;gap:6px;flex-wrap:wrap;<?= (!$isScheduled && !$isPrivate) ? 'display:none;' : '' ?>">
          <?php if ($isScheduled): ?><span class="fb-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il <?= e(date('d/m/Y H:i', strtotime($f['publish_at']))) ?></span><?php endif; ?>
          <?php if ($isPrivate): ?><span class="fb-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span><?php endif; ?>
        </div>
        <div class="fb-pub-block">
          <?php if ($note !== ''): ?>
            <p class="fb-pub-text" style="margin:0;font-size:14px;"><?= nl2br(e($note)) ?></p>
          <?php else: ?>
            <p class="fb-pub-text" style="margin:0;font-size:14px;display:none;"></p>
          <?php endif; ?>
          <button type="button" class="btn small secondary fb-pub-toggle">✏️ Gestisci pubblicazione</button>
          <form class="fb-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">
            <label>Racconta perché ti piace</label>
            <textarea class="fb-pub-textarea" rows="3" placeholder="Racconta perché ti piace"><?= e($note) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">
              <button type="button" class="btn small secondary fb-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="fb-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="fb-ai-keywords" rows="2" placeholder="es. il loro ultimo album mi ha conquistato"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small fb-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary fb-ai-cancel">Annulla</button>
              </div>
              <p class="fb-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>

            <label>Foto (opzionale)</label>
            <input type="file" class="fb-pub-image-input" accept="image/*">
            <input type="hidden" class="fb-pub-image-thumb-data">
            <?php if ($f['image_path']): ?><p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Hai già caricato una foto — seleziona un nuovo file per sostituirla.</p><?php endif; ?>

            <label>Privacy (comparsa nel Feed)</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="fb-pub-visibility" name="visibility" value="public" <?= $isPrivate ? '' : 'checked' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="fb-pub-visibility" name="visibility" value="private" <?= $isPrivate ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>

            <label>Programma la comparsa nel Feed (opzionale)</label>
            <input type="datetime-local" class="fb-pub-publish-at" value="<?= $f['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($f['publish_at']))) : '' ?>">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per mostrarlo subito nel Feed (se Pubblico). Resta comunque sempre visibile in questa lista e nella sua pagina.</p>

            <label>Link personalizzato per il feed (opzionale)</label>
            <input type="url" class="fb-pub-custom-link" value="<?= e($profile['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
            <?php if (!empty($profile['custom_feed_guid_since'])): ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Attivo dal <?= e(date('d/m/Y H:i', strtotime($profile['custom_feed_guid_since']))) ?>. Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php else: ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php endif; ?>

            <p class="fb-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small fb-pub-save">Salva</button>
              <button type="button" class="btn small secondary fb-pub-cancel">Annulla</button>
            </div>
          </form>
        </div>
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
      return fetch('/dashboard_fan_bands.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function postForm(formData) {
      formData.set('csrf', csrfInput.value);
      formData.set('ajax', '1');
      return fetch('/dashboard_fan_bands.php', { method: 'POST', body: formData }).then(r => r.json());
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

    function favoriteRowHtml(item) {
      const badges = renderBadges(item);
      const img = item.artist_image ? '<img src="' + escapeHtml(item.artist_image) + '" style="width:44px;height:44px;border-radius:50%;flex-shrink:0;">' : '';
      const customLink = <?= json_encode($profile['custom_feed_guid'] ?? '') ?>;
      const customLinkSince = <?= json_encode($profile['custom_feed_guid_since'] ?? '') ?>;
      const sinceHint = customLinkSince
        ? 'Attivo dal ' + escapeHtml(customLinkSince) + '. Vale per tutti i contenuti del profilo, non solo per questo elemento.'
        : 'Vale per tutti i contenuti del profilo, non solo per questo elemento.';
      return '<div style="display:flex;align-items:center;gap:12px;">'
        + '<a href="/' + escapeHtml(profileSlug) + '/band-che-amo/' + item.id + '" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">'
        + img + '<strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(item.spotify_artist_name) + '</strong></a>'
        + '<button type="button" class="btn small danger fb-remove-btn" style="flex-shrink:0;">Rimuovi</button></div>'
        + '<div class="fb-pub-badges" style="display:' + (badges.visible ? 'flex' : 'none') + ';gap:6px;flex-wrap:wrap;">' + badges.html + '</div>'
        + '<div class="fb-pub-block">'
        + '<p class="fb-pub-text" style="margin:0;font-size:14px;display:none;"></p>'
        + '<button type="button" class="btn small secondary fb-pub-toggle">✏️ Gestisci pubblicazione</button>'
        + '<form class="fb-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">'
        + '<label>Racconta perché ti piace</label>'
        + '<textarea class="fb-pub-textarea" rows="3" placeholder="Racconta perché ti piace"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">'
        + '<button type="button" class="btn small secondary fb-ai-toggle">✨ Genera con AI</button></div>'
        + '<div class="fb-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">'
        + '<label>Qualche parola chiave o istruzione per l\'AI</label>'
        + '<textarea class="fb-ai-keywords" rows="2" placeholder="es. il loro ultimo album mi ha conquistato"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
        + '<button type="button" class="btn small fb-ai-generate">Genera testo</button>'
        + '<button type="button" class="btn small secondary fb-ai-cancel">Annulla</button></div>'
        + '<p class="fb-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p></div>'
        + '<label>Foto (opzionale)</label>'
        + '<input type="file" class="fb-pub-image-input" accept="image/*">'
        + '<input type="hidden" class="fb-pub-image-thumb-data">'
        + '<label>Privacy (comparsa nel Feed)</label>'
        + '<div style="display:flex;gap:16px;margin-bottom:14px;">'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="fb-pub-visibility" name="visibility" value="public"' + (item.show_in_feed ? ' checked' : '') + ' style="width:auto;"> Pubblico</label>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="fb-pub-visibility" name="visibility" value="private"' + (!item.show_in_feed ? ' checked' : '') + ' style="width:auto;"> Solo io</label></div>'
        + '<label>Programma la comparsa nel Feed (opzionale)</label>'
        + '<input type="datetime-local" class="fb-pub-publish-at">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per mostrarlo subito nel Feed (se Pubblico). Resta comunque sempre visibile in questa lista e nella sua pagina.</p>'
        + '<label>Link personalizzato per il feed (opzionale)</label>'
        + '<input type="url" class="fb-pub-custom-link" value="' + escapeHtml(customLink) + '" placeholder="https://...">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">' + sinceHint + '</p>'
        + '<p class="fb-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>'
        + '<div style="display:flex;gap:8px;margin-top:4px;">'
        + '<button type="button" class="btn small fb-pub-save">Salva</button>'
        + '<button type="button" class="btn small secondary fb-pub-cancel">Annulla</button></div></form></div>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-fb-favorite', item.id);
      div.setAttribute('data-fb-artist-id', item.spotify_artist_id);
      div.setAttribute('data-fb-note', '');
      div.setAttribute('data-fb-has-image', '0');
      div.style.flexDirection = 'column';
      div.style.alignItems = 'stretch';
      div.style.gap = '8px';
      div.innerHTML = favoriteRowHtml(item);
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
      if (isScheduled) html += '<span class="fb-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il ' + escapeHtml(item.publish_at) + '</span>';
      if (isPrivate) html += '<span class="fb-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span>';
      return { html: html, visible: isScheduled || isPrivate };
    }

    // Rimuovi/pubblicazione (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const removeBtn = e.target.closest('.fb-remove-btn');
      const pubToggleBtn = e.target.closest('.fb-pub-toggle');
      const pubCancelBtn = e.target.closest('.fb-pub-cancel');
      const pubSaveBtn = e.target.closest('.fb-pub-save');
      const aiToggleBtn = e.target.closest('.fb-ai-toggle');
      const aiCancelBtn = e.target.closest('.fb-ai-cancel');
      const aiGenerateBtn = e.target.closest('.fb-ai-generate');

      if (removeBtn) {
        if (!confirm('Rimuovere questa band dalla tua lista?')) return;
        const row = removeBtn.closest('[data-fb-favorite]');
        const id = row.getAttribute('data-fb-favorite');
        const artistId = row.getAttribute('data-fb-artist-id');
        removeBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'remove');
        params.set('id', id);
        post(params).then(function (data) {
          if (data.ok) {
            row.remove();
            updateListTitle();
            markResultAsRemovable(artistId);
          } else {
            removeBtn.disabled = false;
          }
        }).catch(function () { removeBtn.disabled = false; });
        return;
      }

      if (pubToggleBtn) {
        const block = pubToggleBtn.closest('.fb-pub-block');
        block.querySelector('.fb-pub-editor').style.display = 'block';
        block.querySelector('.fb-pub-textarea').focus();
        return;
      }

      if (pubCancelBtn) {
        const block = pubCancelBtn.closest('.fb-pub-block');
        const row = pubCancelBtn.closest('[data-fb-favorite]');
        block.querySelector('.fb-pub-textarea').value = row.getAttribute('data-fb-note') || '';
        block.querySelector('.fb-pub-editor').style.display = 'none';
        return;
      }

      if (aiToggleBtn) {
        const panel = aiToggleBtn.closest('.fb-pub-editor').querySelector('.fb-ai-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') panel.querySelector('.fb-ai-keywords').focus();
        return;
      }

      if (aiCancelBtn) {
        const panel = aiCancelBtn.closest('.fb-ai-panel');
        panel.style.display = 'none';
        panel.querySelector('.fb-ai-status').textContent = '';
        return;
      }

      if (aiGenerateBtn) {
        const panel = aiGenerateBtn.closest('.fb-ai-panel');
        const editor = aiGenerateBtn.closest('.fb-pub-editor');
        const keywords = panel.querySelector('.fb-ai-keywords').value.trim();
        const statusEl = panel.querySelector('.fb-ai-status');
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
              editor.querySelector('.fb-pub-textarea').value = data.text;
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
        const editor = pubSaveBtn.closest('.fb-pub-editor');
        const block = pubSaveBtn.closest('.fb-pub-block');
        const row = pubSaveBtn.closest('[data-fb-favorite]');
        const id = row.getAttribute('data-fb-favorite');
        const note = editor.querySelector('.fb-pub-textarea').value;
        const visibility = editor.querySelector('.fb-pub-visibility:checked').value;
        const publishAt = editor.querySelector('.fb-pub-publish-at').value;
        const customLink = editor.querySelector('.fb-pub-custom-link').value;
        const imageInput = editor.querySelector('.fb-pub-image-input');
        const statusEl = editor.querySelector('.fb-pub-status');
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
            row.setAttribute('data-fb-note', note);
            row.setAttribute('data-fb-has-image', data.item.image_path ? '1' : '0');
            const textEl = block.querySelector('.fb-pub-text');
            const toggleEl = block.querySelector('.fb-pub-toggle');
            if (note.trim() !== '') {
              textEl.innerHTML = escapeHtml(note).replace(/\n/g, '<br>');
              textEl.style.display = 'block';
            } else {
              textEl.style.display = 'none';
            }
            toggleEl.textContent = '✏️ Gestisci pubblicazione';
            const badges = renderBadges(data.item);
            const badgesBox = row.querySelector('.fb-pub-badges');
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
