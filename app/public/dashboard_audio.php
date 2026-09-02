<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'audio';
$pageTitle = 'Brani che amo';

$searchResults = [];
$searchQuery = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    $isAjax = !empty($_POST['ajax']);

    if ($action === 'add') {
        $trackId = trim($_POST['track_id'] ?? '');
        $trackName = trim($_POST['track_name'] ?? '');
        $artistName = trim($_POST['artist_name'] ?? '');
        $trackImage = trim($_POST['track_image'] ?? '');
        $spotifyUrl = trim($_POST['spotify_url'] ?? '');
        $addedRow = null;
        if ($trackId !== '') {
            $stmt = getDB()->prepare('INSERT IGNORE INTO favorite_tracks
                (user_id, spotify_track_id, track_name, artist_name, track_image, spotify_url, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM favorite_tracks WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $trackId, $trackName, $artistName, $trackImage ?: null, $spotifyUrl ?: null, $profile['id']]);
            // Non ci si fida di lastInsertId(): con INSERT IGNORE su un duplicato resterebbe a 0
            // o non aggiornato — si rilegge sempre la riga vera dal database.
            $stmt = getDB()->prepare('SELECT * FROM favorite_tracks WHERE user_id=? AND spotify_track_id=?');
            $stmt->execute([$profile['id'], $trackId]);
            $addedRow = $stmt->fetch() ?: null;
            if ($addedRow) {
                logAdminAction((int) $profile['id'], (int) $user['id'], 'Nuovo brano aggiunto', $trackName);
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $addedRow, 'item' => $addedRow]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM favorite_tracks WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteCoverFile($row['image_thumb_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM favorite_tracks WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        logAdminAction((int) $profile['id'], (int) $user['id'], 'Brano rimosso');
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true]);
            exit;
        }
    } elseif ($action === 'save_lyrics') {
        $id = (int) ($_POST['id'] ?? 0);
        $lyrics = trim($_POST['lyrics'] ?? '');
        $stmt = getDB()->prepare('UPDATE favorite_tracks SET lyrics=? WHERE id=? AND user_id=?');
        $stmt->execute([$lyrics ?: null, $id, $profile['id']]);
        logAdminAction((int) $profile['id'], (int) $user['id'], 'Testo del brano aggiornato');
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
            $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM favorite_tracks WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            if ($old = $stmt->fetch()) {
                deleteCoverFile($old['image_path']);
                deleteCoverFile($old['image_thumb_path']);
            }
            $stmt = getDB()->prepare('UPDATE favorite_tracks SET note=?, show_in_feed=?, publish_at=?, image_path=?, image_thumb_path=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $showInFeed, $publishAt, $imagePath, $imageThumbPath, $id, $profile['id']]);
        } else {
            $stmt = getDB()->prepare('UPDATE favorite_tracks SET note=?, show_in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $showInFeed, $publishAt, $id, $profile['id']]);
        }

        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT * FROM favorite_tracks WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $row, 'item' => $row, 'error' => $error]);
            exit;
        }
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = spotifySearchTrack($searchQuery);
        }
        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT spotify_track_id FROM favorite_tracks WHERE user_id=?');
            $stmt->execute([$profile['id']]);
            $favIds = array_column($stmt->fetchAll(), 'spotify_track_id');
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $searchResults, 'favoriteIds' => $favIds]);
            exit;
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM favorite_tracks WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$tracks = $stmt->fetchAll();
$trackIds = array_column($tracks, 'spotify_track_id');

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca su Spotify i brani da mostrare sulla tua pagina (i tuoi, le tue cover, o qualsiasi
      brano ti rappresenti) e aggiungili — compariranno nella sezione "Brani che amo" della tua
      pagina pubblica. La ricerca parte da sola mentre scrivi, e aggiungere/rimuovere un brano
      aggiorna la lista all'istante, senza ricaricare la pagina.
    </p>
    <p style="color:var(--text-muted)">
      Ogni brano aggiunto ha una sua pagina pubblica dedicata (raggiungibile cliccandoci sopra),
      condivisibile sui social con anteprima immagine/testo. Da "✏️ Gestisci pubblicazione" puoi
      scrivere perché ti piace (anche con l'aiuto dell'AI), aggiungere una foto, decidere se deve
      comparire nel Feed (Pubblico/Solo io), programmarne la comparsa per una data futura e
      impostare il link personalizzato per il feed — stessa logica della Timeline. Puoi anche
      aggiungere il testo del brano da "📝 Testo".
    </p>
  </details>

  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card" id="ft-search-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca un brano su Spotify</label>
    <input type="text" name="query" id="ft-search-input" value="<?= e($searchQuery) ?>" placeholder="titolo, artista..." autocomplete="off">
    <button type="submit" class="btn">Cerca</button>
    <p id="ft-search-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
  </form>

  <div id="ft-search-results">
    <?php if ($searchResults): ?>
      <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
      <?php foreach ($searchResults as $r): ?>
        <div class="link-item" data-ft-result="<?= e($r['id']) ?>">
          <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($r['image']): ?>
              <img src="<?= e($r['image']) ?>" alt="" style="width:48px;height:48px;border-radius:6px;">
            <?php endif; ?>
            <div>
              <strong><?= e($r['name']) ?></strong><br>
              <small style="color:var(--text-muted)"><?= e($r['artist_name']) ?></small>
            </div>
          </div>
          <?php if (in_array($r['id'], $trackIds, true)): ?>
            <span class="ft-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>
          <?php else: ?>
            <button type="button" class="btn small ft-add-btn"
              data-track-id="<?= e($r['id']) ?>" data-track-name="<?= e($r['name']) ?>"
              data-artist-name="<?= e($r['artist_name']) ?>" data-track-image="<?= e($r['image'] ?? '') ?>"
              data-spotify-url="<?= e($r['spotify_url'] ?? '') ?>">
              Aggiungi
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="card">Nessun risultato per questa ricerca.</div>
    <?php endif; ?>
  </div>

  <div class="section-title" id="ft-list-title">I tuoi brani (<?= count($tracks) ?>)</div>
  <div id="ft-list-empty" class="alert error" style="<?= $tracks ? 'display:none;' : '' ?>">Nessun brano aggiunto ancora — cercalo qui sopra.</div>
  <div id="ft-list">
    <?php foreach ($tracks as $t): ?>
      <?php
        $note = trim($t['note'] ?? '');
        $isPrivate = !$t['show_in_feed'];
        $isScheduled = $t['publish_at'] && strtotime($t['publish_at']) > time();
      ?>
      <div class="link-item" data-ft-favorite="<?= (int)$t['id'] ?>" data-ft-track-id="<?= e($t['spotify_track_id']) ?>"
           data-ft-note="<?= e($note) ?>" data-ft-has-image="<?= $t['image_path'] ? '1' : '0' ?>"
           style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <a href="/<?= e($profile['slug']) ?>/brani/<?= (int)$t['id'] ?>/scheda" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
            <?php if ($t['track_image']): ?>
              <img src="<?= e($t['track_image']) ?>" style="width:48px;height:48px;border-radius:6px;object-fit:cover;flex-shrink:0;">
            <?php endif; ?>
            <div style="min-width:0;overflow:hidden;">
              <strong style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;"><?= e($t['track_name']) ?></strong>
              <small style="color:var(--text-muted)"><?= e($t['artist_name']) ?></small>
            </div>
          </a>
          <div style="display:flex;gap:8px;flex-shrink:0;">
            <button type="button" class="btn small ft-lyrics-toggle" style="background:#1DB954;color:#fff;">
              <?= $t['lyrics'] ? '📝 Testo' : '➕ Lyrics' ?>
            </button>
            <button type="button" class="btn small danger ft-remove-btn">Rimuovi</button>
          </div>
        </div>
        <div class="ft-pub-badges" style="display:flex;gap:6px;flex-wrap:wrap;<?= (!$isScheduled && !$isPrivate) ? 'display:none;' : '' ?>">
          <?php if ($isScheduled): ?><span class="ft-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il <?= e(date('d/m/Y H:i', strtotime($t['publish_at']))) ?></span><?php endif; ?>
          <?php if ($isPrivate): ?><span class="ft-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span><?php endif; ?>
        </div>

        <div class="ft-lyrics-block" style="display:none;">
          <label>Testo del brano</label>
          <textarea class="ft-lyrics-textarea" rows="6" placeholder="Incolla o scrivi qui il testo..."><?= e($t['lyrics'] ?? '') ?></textarea>
          <div style="display:flex;gap:8px;">
            <button type="button" class="btn small ft-lyrics-save">Salva testo</button>
            <button type="button" class="btn small secondary ft-lyrics-cancel">Annulla</button>
          </div>
        </div>

        <div class="ft-pub-block">
          <?php if ($note !== ''): ?>
            <p class="ft-pub-text" style="margin:0;font-size:14px;"><?= nl2br(e($note)) ?></p>
          <?php else: ?>
            <p class="ft-pub-text" style="margin:0;font-size:14px;display:none;"></p>
          <?php endif; ?>
          <button type="button" class="btn small secondary ft-pub-toggle">✏️ Gestisci pubblicazione</button>
          <form class="ft-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">
            <label>Racconta perché ti piace</label>
            <textarea class="ft-pub-textarea" rows="3" placeholder="Racconta perché ti piace"><?= e($note) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">
              <button type="button" class="btn small secondary ft-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="ft-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="ft-ai-keywords" rows="2" placeholder="es. questa canzone mi ricorda l'estate scorsa"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small ft-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary ft-ai-cancel">Annulla</button>
              </div>
              <p class="ft-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>

            <label>Foto (opzionale)</label>
            <input type="file" class="ft-pub-image-input" accept="image/*">
            <input type="hidden" class="ft-pub-image-thumb-data">
            <?php if ($t['image_path']): ?><p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Hai già caricato una foto — seleziona un nuovo file per sostituirla.</p><?php endif; ?>

            <label>Privacy (comparsa nel Feed)</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="ft-pub-visibility" name="visibility" value="public" <?= $isPrivate ? '' : 'checked' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="ft-pub-visibility" name="visibility" value="private" <?= $isPrivate ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>

            <label>Programma la comparsa nel Feed (opzionale)</label>
            <input type="datetime-local" class="ft-pub-publish-at" value="<?= $t['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($t['publish_at']))) : '' ?>">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per mostrarlo subito nel Feed (se Pubblico). Resta comunque sempre visibile in questa lista e nella sua pagina.</p>

            <label>Link personalizzato per il feed (opzionale)</label>
            <input type="url" class="ft-pub-custom-link" value="<?= e($profile['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
            <?php if (!empty($profile['custom_feed_guid_since'])): ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Attivo dal <?= e(date('d/m/Y H:i', strtotime($profile['custom_feed_guid_since']))) ?>. Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php else: ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php endif; ?>

            <p class="ft-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small ft-pub-save">Salva</button>
              <button type="button" class="btn small secondary ft-pub-cancel">Annulla</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function () {
    const csrfInput = document.querySelector('#ft-search-form input[name="csrf"]');
    const searchForm = document.getElementById('ft-search-form');
    const searchInput = document.getElementById('ft-search-input');
    const searchStatus = document.getElementById('ft-search-status');
    const resultsBox = document.getElementById('ft-search-results');
    const listBox = document.getElementById('ft-list');
    const listTitle = document.getElementById('ft-list-title');
    const listEmpty = document.getElementById('ft-list-empty');
    const profileSlug = <?= json_encode($profile['slug']) ?>;

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function updateListTitle() {
      const n = listBox.children.length;
      listTitle.textContent = 'I tuoi brani (' + n + ')';
      listEmpty.style.display = n === 0 ? 'block' : 'none';
    }

    function post(params) {
      params.set('csrf', csrfInput.value);
      params.set('ajax', '1');
      return fetch('/dashboard_audio.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function postForm(formData) {
      formData.set('csrf', csrfInput.value);
      formData.set('ajax', '1');
      return fetch('/dashboard_audio.php', { method: 'POST', body: formData }).then(r => r.json());
    }

    function markResultAsAdded(trackId) {
      const row = resultsBox.querySelector('[data-ft-result="' + CSS.escape(trackId) + '"]');
      if (!row) return;
      const btn = row.querySelector('.ft-add-btn');
      if (btn) btn.outerHTML = '<span class="ft-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
    }

    function markResultAsRemovable(trackId) {
      const row = resultsBox.querySelector('[data-ft-result="' + CSS.escape(trackId) + '"]');
      if (!row) return;
      const span = row.querySelector('.ft-already');
      if (!span) return;
      const name = row.querySelector('strong').textContent;
      const artist = row.querySelector('small') ? row.querySelector('small').textContent : '';
      const img = row.querySelector('img');
      span.outerHTML = '<button type="button" class="btn small ft-add-btn" data-track-id="' + escapeHtml(trackId)
        + '" data-track-name="' + escapeHtml(name) + '" data-artist-name="' + escapeHtml(artist)
        + '" data-track-image="' + escapeHtml(img ? img.getAttribute('src') : '') + '" data-spotify-url="">Aggiungi</button>';
    }

    function favoriteRowHtml(item) {
      const img = item.track_image ? '<img src="' + escapeHtml(item.track_image) + '" style="width:48px;height:48px;border-radius:6px;object-fit:cover;flex-shrink:0;">' : '';
      const customLink = <?= json_encode($profile['custom_feed_guid'] ?? '') ?>;
      const customLinkSince = <?= json_encode($profile['custom_feed_guid_since'] ?? '') ?>;
      const sinceHint = customLinkSince
        ? 'Attivo dal ' + escapeHtml(customLinkSince) + '. Vale per tutti i contenuti del profilo, non solo per questo elemento.'
        : 'Vale per tutti i contenuti del profilo, non solo per questo elemento.';
      return '<div style="display:flex;align-items:center;gap:12px;">'
        + '<a href="/' + escapeHtml(profileSlug) + '/brani/' + item.id + '/scheda" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">'
        + img + '<div style="min-width:0;overflow:hidden;"><strong style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;">' + escapeHtml(item.track_name) + '</strong>'
        + '<small style="color:var(--text-muted)">' + escapeHtml(item.artist_name || '') + '</small></div></a>'
        + '<div style="display:flex;gap:8px;flex-shrink:0;">'
        + '<button type="button" class="btn small ft-lyrics-toggle" style="background:#1DB954;color:#fff;">➕ Lyrics</button>'
        + '<button type="button" class="btn small danger ft-remove-btn">Rimuovi</button></div></div>'
        + '<div class="ft-pub-badges" style="display:none;"></div>'
        + '<div class="ft-lyrics-block" style="display:none;">'
        + '<label>Testo del brano</label>'
        + '<textarea class="ft-lyrics-textarea" rows="6" placeholder="Incolla o scrivi qui il testo..."></textarea>'
        + '<div style="display:flex;gap:8px;">'
        + '<button type="button" class="btn small ft-lyrics-save">Salva testo</button>'
        + '<button type="button" class="btn small secondary ft-lyrics-cancel">Annulla</button></div></div>'
        + '<div class="ft-pub-block">'
        + '<p class="ft-pub-text" style="margin:0;font-size:14px;display:none;"></p>'
        + '<button type="button" class="btn small secondary ft-pub-toggle">✏️ Gestisci pubblicazione</button>'
        + '<form class="ft-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">'
        + '<label>Racconta perché ti piace</label>'
        + '<textarea class="ft-pub-textarea" rows="3" placeholder="Racconta perché ti piace"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">'
        + '<button type="button" class="btn small secondary ft-ai-toggle">✨ Genera con AI</button></div>'
        + '<div class="ft-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">'
        + '<label>Qualche parola chiave o istruzione per l\'AI</label>'
        + '<textarea class="ft-ai-keywords" rows="2" placeholder="es. questa canzone mi ricorda l\'estate scorsa"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
        + '<button type="button" class="btn small ft-ai-generate">Genera testo</button>'
        + '<button type="button" class="btn small secondary ft-ai-cancel">Annulla</button></div>'
        + '<p class="ft-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p></div>'
        + '<label>Foto (opzionale)</label>'
        + '<input type="file" class="ft-pub-image-input" accept="image/*">'
        + '<input type="hidden" class="ft-pub-image-thumb-data">'
        + '<label>Privacy (comparsa nel Feed)</label>'
        + '<div style="display:flex;gap:16px;margin-bottom:14px;">'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="ft-pub-visibility" name="visibility" value="public" checked style="width:auto;"> Pubblico</label>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="ft-pub-visibility" name="visibility" value="private" style="width:auto;"> Solo io</label></div>'
        + '<label>Programma la comparsa nel Feed (opzionale)</label>'
        + '<input type="datetime-local" class="ft-pub-publish-at">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per mostrarlo subito nel Feed (se Pubblico). Resta comunque sempre visibile in questa lista e nella sua pagina.</p>'
        + '<label>Link personalizzato per il feed (opzionale)</label>'
        + '<input type="url" class="ft-pub-custom-link" value="' + escapeHtml(customLink) + '" placeholder="https://...">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">' + sinceHint + '</p>'
        + '<p class="ft-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>'
        + '<div style="display:flex;gap:8px;margin-top:4px;">'
        + '<button type="button" class="btn small ft-pub-save">Salva</button>'
        + '<button type="button" class="btn small secondary ft-pub-cancel">Annulla</button></div></form></div>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-ft-favorite', item.id);
      div.setAttribute('data-ft-track-id', item.spotify_track_id);
      div.setAttribute('data-ft-note', '');
      div.setAttribute('data-ft-has-image', '0');
      div.style.flexDirection = 'column';
      div.style.alignItems = 'stretch';
      div.style.gap = '8px';
      div.innerHTML = favoriteRowHtml(item);
      listBox.prepend(div);
      updateListTitle();
    }

    // Aggiungi (delegato: i risultati di ricerca vengono ricreati a ogni ricerca)
    resultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.ft-add-btn');
      if (!btn) return;
      btn.disabled = true;
      const trackId = btn.dataset.trackId;
      const params = new URLSearchParams();
      params.set('action', 'add');
      params.set('track_id', trackId);
      params.set('track_name', btn.dataset.trackName);
      params.set('artist_name', btn.dataset.artistName || '');
      params.set('track_image', btn.dataset.trackImage || '');
      params.set('spotify_url', btn.dataset.spotifyUrl || '');
      post(params).then(function (data) {
        if (data.ok && data.item) {
          markResultAsAdded(trackId);
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
      if (isScheduled) html += '<span class="ft-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il ' + escapeHtml(item.publish_at) + '</span>';
      if (isPrivate) html += '<span class="ft-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span>';
      return { html: html, visible: isScheduled || isPrivate };
    }

    // Rimuovi/lyrics/pubblicazione (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const removeBtn = e.target.closest('.ft-remove-btn');
      const lyricsToggleBtn = e.target.closest('.ft-lyrics-toggle');
      const lyricsCancelBtn = e.target.closest('.ft-lyrics-cancel');
      const lyricsSaveBtn = e.target.closest('.ft-lyrics-save');
      const pubToggleBtn = e.target.closest('.ft-pub-toggle');
      const pubCancelBtn = e.target.closest('.ft-pub-cancel');
      const pubSaveBtn = e.target.closest('.ft-pub-save');
      const aiToggleBtn = e.target.closest('.ft-ai-toggle');
      const aiCancelBtn = e.target.closest('.ft-ai-cancel');
      const aiGenerateBtn = e.target.closest('.ft-ai-generate');

      if (removeBtn) {
        if (!confirm('Rimuovere questo brano dalla tua lista?')) return;
        const row = removeBtn.closest('[data-ft-favorite]');
        const id = row.getAttribute('data-ft-favorite');
        const trackId = row.getAttribute('data-ft-track-id');
        removeBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'remove');
        params.set('id', id);
        post(params).then(function (data) {
          if (data.ok) {
            row.remove();
            updateListTitle();
            markResultAsRemovable(trackId);
          } else {
            removeBtn.disabled = false;
          }
        }).catch(function () { removeBtn.disabled = false; });
        return;
      }

      if (lyricsToggleBtn) {
        const row = lyricsToggleBtn.closest('[data-ft-favorite]');
        row.querySelector('.ft-lyrics-block').style.display = 'block';
        return;
      }

      if (lyricsCancelBtn) {
        lyricsCancelBtn.closest('.ft-lyrics-block').style.display = 'none';
        return;
      }

      if (lyricsSaveBtn) {
        const block = lyricsSaveBtn.closest('.ft-lyrics-block');
        const row = lyricsSaveBtn.closest('[data-ft-favorite]');
        const id = row.getAttribute('data-ft-favorite');
        const lyrics = block.querySelector('.ft-lyrics-textarea').value;
        lyricsSaveBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'save_lyrics');
        params.set('id', id);
        params.set('lyrics', lyrics);
        post(params).then(function (data) {
          lyricsSaveBtn.disabled = false;
          if (!data.ok) return;
          row.querySelector('.ft-lyrics-toggle').textContent = lyrics.trim() !== '' ? '📝 Testo' : '➕ Lyrics';
          block.style.display = 'none';
        }).catch(function () { lyricsSaveBtn.disabled = false; });
        return;
      }

      if (pubToggleBtn) {
        const block = pubToggleBtn.closest('.ft-pub-block');
        block.querySelector('.ft-pub-editor').style.display = 'block';
        block.querySelector('.ft-pub-textarea').focus();
        return;
      }

      if (pubCancelBtn) {
        const block = pubCancelBtn.closest('.ft-pub-block');
        const row = pubCancelBtn.closest('[data-ft-favorite]');
        block.querySelector('.ft-pub-textarea').value = row.getAttribute('data-ft-note') || '';
        block.querySelector('.ft-pub-editor').style.display = 'none';
        return;
      }

      if (aiToggleBtn) {
        const panel = aiToggleBtn.closest('.ft-pub-editor').querySelector('.ft-ai-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') panel.querySelector('.ft-ai-keywords').focus();
        return;
      }

      if (aiCancelBtn) {
        const panel = aiCancelBtn.closest('.ft-ai-panel');
        panel.style.display = 'none';
        panel.querySelector('.ft-ai-status').textContent = '';
        return;
      }

      if (aiGenerateBtn) {
        const panel = aiGenerateBtn.closest('.ft-ai-panel');
        const editor = aiGenerateBtn.closest('.ft-pub-editor');
        const keywords = panel.querySelector('.ft-ai-keywords').value.trim();
        const statusEl = panel.querySelector('.ft-ai-status');
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
              editor.querySelector('.ft-pub-textarea').value = data.text;
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
        const editor = pubSaveBtn.closest('.ft-pub-editor');
        const block = pubSaveBtn.closest('.ft-pub-block');
        const row = pubSaveBtn.closest('[data-ft-favorite]');
        const id = row.getAttribute('data-ft-favorite');
        const note = editor.querySelector('.ft-pub-textarea').value;
        const visibility = editor.querySelector('.ft-pub-visibility:checked').value;
        const publishAt = editor.querySelector('.ft-pub-publish-at').value;
        const customLink = editor.querySelector('.ft-pub-custom-link').value;
        const imageInput = editor.querySelector('.ft-pub-image-input');
        const statusEl = editor.querySelector('.ft-pub-status');
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
            row.setAttribute('data-ft-note', note);
            row.setAttribute('data-ft-has-image', data.item.image_path ? '1' : '0');
            const textEl = block.querySelector('.ft-pub-text');
            const toggleEl = block.querySelector('.ft-pub-toggle');
            if (note.trim() !== '') {
              textEl.innerHTML = escapeHtml(note).replace(/\n/g, '<br>');
              textEl.style.display = 'block';
            } else {
              textEl.style.display = 'none';
            }
            toggleEl.textContent = '✏️ Gestisci pubblicazione';
            const badges = renderBadges(data.item);
            const badgesBox = row.querySelector('.ft-pub-badges');
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
        html += '<div class="link-item" data-ft-result="' + escapeHtml(r.id) + '">'
          + '<div style="display:flex;align-items:center;gap:12px;">'
          + (r.image ? '<img src="' + escapeHtml(r.image) + '" alt="" style="width:48px;height:48px;border-radius:6px;">' : '')
          + '<div><strong>' + escapeHtml(r.name) + '</strong><br><small style="color:var(--text-muted)">' + escapeHtml(r.artist_name || '') + '</small></div></div>';
        if (favIds.indexOf(r.id) !== -1) {
          html += '<span class="ft-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
        } else {
          html += '<button type="button" class="btn small ft-add-btn" data-track-id="' + escapeHtml(r.id)
            + '" data-track-name="' + escapeHtml(r.name) + '" data-artist-name="' + escapeHtml(r.artist_name || '')
            + '" data-track-image="' + escapeHtml(r.image || '') + '" data-spotify-url="' + escapeHtml(r.spotify_url || '') + '">Aggiungi</button>';
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
