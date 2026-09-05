<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/geocoding.php';
require_once __DIR__ . '/../src/geoapify.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'che_amo';
$pageTitle = 'Viaggi';

$searchResults = [];
$searchQuery = '';
$error = null;

// Primo pezzo (prima della virgola) del display_name di Nominatim, usato come nome breve del
// luogo — l'indirizzo completo resta comunque salvato a parte.
function tripsShortPlaceName(string $displayName): string {
    $parts = explode(',', $displayName, 2);
    return trim($parts[0]) ?: $displayName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    $isAjax = !empty($_POST['ajax']);

    if ($action === 'add') {
        $placeName = trim($_POST['place_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $latRaw = str_replace(',', '.', trim($_POST['lat'] ?? ''));
        $lngRaw = str_replace(',', '.', trim($_POST['lng'] ?? ''));
        $addedRow = null;
        if (is_numeric($latRaw) && is_numeric($lngRaw)) {
            $lat = (float) $latRaw;
            $lng = (float) $lngRaw;

            // Nessun nome del posto (caso "Condividi la tua posizione attuale": arrivano solo le
            // coordinate dal GPS del telefono) — si prova a ricavarlo con la geocodifica inversa,
            // altrimenti si ricade su un'etichetta generica con data/ora, senza mai bloccare il
            // salvataggio per questo.
            if ($placeName === '') {
                $reverse = reverseGeocode($lat, $lng);
                if ($reverse) {
                    $placeName = tripsShortPlaceName($reverse);
                    $address = $reverse;
                } else {
                    $placeName = 'Posizione del ' . date('d/m/Y H:i');
                }
            }

            // show_in_feed=0 di proposito: un elemento appena aggiunto parte "Solo io", la
            // pubblicazione nel Feed va confermata a mano dal pannello di pubblicazione.
            $stmt = getDB()->prepare('INSERT INTO fan_favorite_trips
                (user_id, place_name, address, lat, lng, show_in_feed, sort_order)
                VALUES (?, ?, ?, ?, ?, 0, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_trips WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $placeName, $address ?: null, $lat, $lng, $profile['id']]);
            $newId = (int) getDB()->lastInsertId();

            // Miniatura mappa generata subito, usata come og:image finché non carichi una foto
            // tua dal pannello di pubblicazione — se Geoapify non è configurato o la richiesta
            // fallisce il viaggio resta comunque salvato, semplicemente senza quell'immagine di
            // riserva (stesso comportamento "degrada senza rompersi" di tutto il resto del sito).
            $mapPath = geoapifyGenerateStaticMap($lat, $lng, $profile['slug']);
            if ($mapPath) {
                $stmt = getDB()->prepare('UPDATE fan_favorite_trips SET map_image_path=? WHERE id=?');
                $stmt->execute([$mapPath, $newId]);
            }

            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_trips WHERE id=? AND user_id=?');
            $stmt->execute([$newId, $profile['id']]);
            $addedRow = $stmt->fetch() ?: null;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $addedRow, 'item' => $addedRow]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path, map_image_path FROM fan_favorite_trips WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteCoverFile($row['image_thumb_path']);
            deleteCoverFile($row['map_image_path']); // generata da noi (Geoapify), non un URL esterno: va ripulita anche lei
            foreach (getTripPhotos($id) as $extraPath) {
                deleteCoverFile($extraPath);
            }
        }
        // fan_favorite_trip_photos ha ON DELETE CASCADE: le righe spariscono da sole, qui sopra
        // servivano solo per cancellare i FILE dal disco prima che spariscano i riferimenti.
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_trips WHERE id=? AND user_id=?');
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

        // Fino a 10 foto: la prima resta su image_path (quella sola che compare nel Feed/nelle
        // anteprime, come sempre), le eventuali altre alimentano il carosello nella pagina di
        // dettaglio pubblica. Caricarne di nuove sostituisce l'intero set precedente (stessa
        // semantica di "sostituzione" già in uso per la singola foto).
        $uploadedPhotos = handleMultiCoverUpload($profile['slug'], 'images', 10);
        $imagePath = $uploadedPhotos[0] ?? null;
        $extraPhotos = array_slice($uploadedPhotos, 1);
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
            $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_trips WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            if ($old = $stmt->fetch()) {
                deleteCoverFile($old['image_path']);
                deleteCoverFile($old['image_thumb_path']);
            }
            foreach (getTripPhotos($id) as $oldExtra) {
                deleteCoverFile($oldExtra);
            }
            getDB()->prepare('DELETE FROM fan_favorite_trip_photos WHERE trip_id=?')->execute([$id]);

            $stmt = getDB()->prepare('UPDATE fan_favorite_trips SET note=?, show_in_feed=?, publish_at=?, image_path=?, image_thumb_path=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $showInFeed, $publishAt, $imagePath, $imageThumbPath, $id, $profile['id']]);

            if ($extraPhotos) {
                $insPhoto = getDB()->prepare('INSERT INTO fan_favorite_trip_photos (trip_id, image_path, sort_order) VALUES (?,?,?)');
                foreach ($extraPhotos as $photoIndex => $photoPath) {
                    $insPhoto->execute([$id, $photoPath, $photoIndex]);
                }
            }
        } else {
            $stmt = getDB()->prepare('UPDATE fan_favorite_trips SET note=?, show_in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $showInFeed, $publishAt, $id, $profile['id']]);
        }

        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_trips WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $row, 'item' => $row, 'error' => $error, 'extra_photo_count' => $row ? count(getTripPhotos($id)) : 0]);
            exit;
        }
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = geocodeAddressMultiple($searchQuery, 6);
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $searchResults]);
            exit;
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_trips WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$favorites = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <?php if (!getGeoapifyApiKey()): ?>
    <div class="alert error">
      La miniatura mappa automatica non è ancora attiva sul sito — manca la chiave API Geoapify.
      Puoi comunque aggiungere viaggi, ma senza quella chiave i nuovi viaggi avranno un'immagine
      di anteprima social solo se carichi tu una foto dal pannello di pubblicazione. Chiedi
      all'amministratore di configurarla (ADMIN → Geoapify).
    </div>
  <?php endif; ?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca un luogo (via OpenStreetMap, gratuito) o inserisci le coordinate a mano se non lo
      trovi, e aggiungilo alla tua lista di viaggi. Comparirà sulla tua pagina pubblica come
      diario dei posti che hai visitato.
    </p>
    <p style="color:var(--text-muted)">
      Ogni viaggio aggiunto ha una sua pagina pubblica dedicata (raggiungibile cliccandoci sopra),
      con una mappa e condivisibile sui social. Da "✏️ Gestisci pubblicazione" puoi scrivere il
      racconto (anche con l'aiuto dell'AI), aggiungere una foto, decidere se deve comparire nel
      Feed (Pubblico/Solo io), programmarne la comparsa per una data futura e impostare il link
      personalizzato per il feed — stessa logica della Timeline.
    </p>
    <p style="color:var(--text-muted)">
      Se non carichi una foto tua, l'anteprima social usa automaticamente una miniatura della
      mappa del posto, così ogni viaggio ha sempre un'immagine condivisibile.
    </p>
    <p style="color:var(--text-muted)">
      Ogni nuovo elemento aggiunto parte impostato su <strong>Solo io</strong>: resta visibile
      nella tua lista e nella sua pagina, ma compare nel Feed solo dopo che lo confermi come
      Pubblico da "✏️ Gestisci pubblicazione".
    </p>
  </details>

  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="card" style="text-align:center;">
    <button type="button" class="btn" id="tr-geolocate-btn">📍 Condividi la tua posizione attuale</button>
    <p id="tr-geolocate-status" style="color:var(--text-muted);font-size:12.5px;margin:10px 0 0;">
      Il telefono chiederà il permesso di accedere alla posizione — il viaggio viene aggiunto subito, col nome del posto ricavato in automatico.
    </p>
  </div>

  <form method="post" class="card" id="tr-search-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca un luogo</label>
    <input type="text" name="query" id="tr-search-input" value="<?= e($searchQuery) ?>" placeholder="es. nome del posto, città, indirizzo" autocomplete="off">
    <button type="submit" class="btn">Cerca</button>
    <p id="tr-search-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
  </form>

  <details class="help-box" id="tr-manual-box">
    <summary>Non trovi il posto esatto? Inserisci le coordinate manualmente</summary>
    <div style="margin-top:10px;">
      <label>Nome del luogo</label>
      <input type="text" id="tr-manual-name" placeholder="es. Rifugio in montagna">
      <label>Latitudine</label>
      <input type="text" id="tr-manual-lat" inputmode="decimal" placeholder="es. 45.4642">
      <label>Longitudine</label>
      <input type="text" id="tr-manual-lng" inputmode="decimal" placeholder="es. 9.1900">
      <button type="button" class="btn small" id="tr-manual-add">Aggiungi alla lista</button>
      <p id="tr-manual-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
    </div>
  </details>

  <div id="tr-search-results">
    <?php if ($searchResults): ?>
      <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
      <?php foreach ($searchResults as $r): ?>
        <div class="link-item">
          <strong><?= e($r['display_name']) ?></strong>
          <button type="button" class="btn small tr-add-btn"
            data-place-name="<?= e(tripsShortPlaceName($r['display_name'])) ?>" data-address="<?= e($r['display_name']) ?>"
            data-lat="<?= e((string)$r['lat']) ?>" data-lng="<?= e((string)$r['lng']) ?>">
            Aggiungi alla lista
          </button>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="card">Nessun risultato per questa ricerca.</div>
    <?php endif; ?>
  </div>

  <div class="section-title" id="tr-list-title">I tuoi viaggi (<?= count($favorites) ?>)</div>
  <div id="tr-list-empty" class="alert error" style="<?= $favorites ? 'display:none;' : '' ?>">Nessun viaggio aggiunto ancora — cercalo qui sopra.</div>
  <div id="tr-list">
    <?php foreach ($favorites as $f): ?>
      <?php
        $note = trim($f['note'] ?? '');
        $isPrivate = !$f['show_in_feed'];
        $isScheduled = $f['publish_at'] && strtotime($f['publish_at']) > time();
        $thumb = $f['image_path'] ?: $f['map_image_path'];
        $extraPhotoCount = $f['image_path'] ? count(getTripPhotos((int) $f['id'])) : 0;
      ?>
      <div class="link-item" data-tr-favorite="<?= (int)$f['id'] ?>"
           data-tr-note="<?= e($note) ?>" data-tr-has-image="<?= $f['image_path'] ? '1' : '0' ?>"
           style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <a href="/<?= e($profile['slug']) ?>/viaggi/<?= (int)$f['id'] ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
            <?php if ($thumb): ?>
              <img src="/<?= e($thumb) ?>" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;">
            <?php endif; ?>
            <strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($f['place_name']) ?></strong>
          </a>
          <button type="button" class="btn small danger tr-remove-btn" style="flex-shrink:0;">Rimuovi</button>
        </div>
        <div class="tr-pub-badges" style="display:flex;gap:6px;flex-wrap:wrap;<?= (!$isScheduled && !$isPrivate && !$extraPhotoCount) ? 'display:none;' : '' ?>">
          <?php if ($isScheduled): ?><span class="tr-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il <?= e(date('d/m/Y H:i', strtotime($f['publish_at']))) ?></span><?php endif; ?>
          <?php if ($isPrivate): ?><span class="tr-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span><?php endif; ?>
          <?php if ($extraPhotoCount > 0): ?><span class="tr-badge-photos" style="background:var(--accent);color:var(--accent-text);font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">📷 +<?= $extraPhotoCount ?> foto</span><?php endif; ?>
        </div>
        <div class="tr-pub-block">
          <?php if ($note !== ''): ?>
            <p class="tr-pub-text" style="margin:0;font-size:14px;"><?= nl2br(e($note)) ?></p>
          <?php else: ?>
            <p class="tr-pub-text" style="margin:0;font-size:14px;display:none;"></p>
          <?php endif; ?>
          <button type="button" class="btn small secondary tr-pub-toggle">✏️ Gestisci pubblicazione</button>
          <form class="tr-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">
            <label>Racconta questo viaggio</label>
            <textarea class="tr-pub-textarea" rows="3" placeholder="Racconta questo viaggio"><?= e($note) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">
              <button type="button" class="btn small secondary tr-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="tr-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="tr-ai-keywords" rows="2" placeholder="es. viaggio in montagna con gli amici, tono divertente"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small tr-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary tr-ai-cancel">Annulla</button>
              </div>
              <p class="tr-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>

            <label>Foto (fino a 10, opzionale)</label>
            <input type="file" class="tr-pub-image-input" accept="image/*" multiple>
            <input type="hidden" class="tr-pub-image-thumb-data">
            <?php if ($f['image_path']): ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Hai già caricato una foto — seleziona un nuovo file per sostituirla.</p>
            <?php else: ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Senza foto, l'anteprima social usa una miniatura della mappa.</p>
            <?php endif; ?>

            <label>Privacy (comparsa nel Feed)</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="tr-pub-visibility" name="visibility" value="public" <?= $isPrivate ? '' : 'checked' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="tr-pub-visibility" name="visibility" value="private" <?= $isPrivate ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>

            <label>Programma la comparsa nel Feed (opzionale)</label>
            <input type="datetime-local" class="tr-pub-publish-at" value="<?= $f['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($f['publish_at']))) : '' ?>">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per mostrarlo subito nel Feed (se Pubblico). Resta comunque sempre visibile in questa lista e nella sua pagina.</p>

            <label>Link personalizzato per il feed (opzionale)</label>
            <input type="url" class="tr-pub-custom-link" value="<?= e($profile['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
            <?php if (!empty($profile['custom_feed_guid_since'])): ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Attivo dal <?= e(date('d/m/Y H:i', strtotime($profile['custom_feed_guid_since']))) ?>. Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php else: ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php endif; ?>

            <p class="tr-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small tr-pub-save">Salva</button>
              <button type="button" class="btn small secondary tr-pub-cancel">Annulla</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function () {
    const csrfInput = document.querySelector('#tr-search-form input[name="csrf"]');
    const searchForm = document.getElementById('tr-search-form');
    const searchInput = document.getElementById('tr-search-input');
    const searchStatus = document.getElementById('tr-search-status');
    const resultsBox = document.getElementById('tr-search-results');
    const listBox = document.getElementById('tr-list');
    const listTitle = document.getElementById('tr-list-title');
    const listEmpty = document.getElementById('tr-list-empty');
    const profileSlug = <?= json_encode($profile['slug']) ?>;

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function updateListTitle() {
      const n = listBox.children.length;
      listTitle.textContent = 'I tuoi viaggi (' + n + ')';
      listEmpty.style.display = n === 0 ? 'block' : 'none';
    }

    function post(params) {
      params.set('csrf', csrfInput.value);
      params.set('ajax', '1');
      return fetch('/dashboard_fan_trips.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function postForm(formData) {
      formData.set('csrf', csrfInput.value);
      formData.set('ajax', '1');
      return fetch('/dashboard_fan_trips.php', { method: 'POST', body: formData }).then(r => r.json());
    }

    function renderBadges(item, extraPhotoCount) {
      const isScheduled = item.publish_at && new Date(item.publish_at.replace(' ', 'T')).getTime() > Date.now();
      const isPrivate = !item.show_in_feed || item.show_in_feed == 0;
      const hasExtraPhotos = (extraPhotoCount || 0) > 0;
      let html = '';
      if (isScheduled) html += '<span class="tr-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il ' + escapeHtml(item.publish_at) + '</span>';
      if (isPrivate) html += '<span class="tr-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span>';
      if (hasExtraPhotos) html += '<span class="tr-badge-photos" style="background:var(--accent);color:var(--accent-text);font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">📷 +' + extraPhotoCount + ' foto</span>';
      return { html: html, visible: isScheduled || isPrivate || hasExtraPhotos };
    }

    function favoriteRowHtml(item) {
      const badges = renderBadges(item);
      const thumb = item.image_path || item.map_image_path;
      const img = thumb ? '<img src="/' + escapeHtml(thumb) + '" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;">' : '';
      const customLink = <?= json_encode($profile['custom_feed_guid'] ?? '') ?>;
      const customLinkSince = <?= json_encode($profile['custom_feed_guid_since'] ?? '') ?>;
      const sinceHint = customLinkSince
        ? 'Attivo dal ' + escapeHtml(customLinkSince) + '. Vale per tutti i contenuti del profilo, non solo per questo elemento.'
        : 'Vale per tutti i contenuti del profilo, non solo per questo elemento.';
      return '<div style="display:flex;align-items:center;gap:12px;">'
        + '<a href="/' + escapeHtml(profileSlug) + '/viaggi/' + item.id + '" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">'
        + img + '<strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(item.place_name) + '</strong></a>'
        + '<button type="button" class="btn small danger tr-remove-btn" style="flex-shrink:0;">Rimuovi</button></div>'
        + '<div class="tr-pub-badges" style="display:' + (badges.visible ? 'flex' : 'none') + ';gap:6px;flex-wrap:wrap;">' + badges.html + '</div>'
        + '<div class="tr-pub-block">'
        + '<p class="tr-pub-text" style="margin:0;font-size:14px;display:none;"></p>'
        + '<button type="button" class="btn small secondary tr-pub-toggle">✏️ Gestisci pubblicazione</button>'
        + '<form class="tr-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">'
        + '<label>Racconta questo viaggio</label>'
        + '<textarea class="tr-pub-textarea" rows="3" placeholder="Racconta questo viaggio"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">'
        + '<button type="button" class="btn small secondary tr-ai-toggle">✨ Genera con AI</button></div>'
        + '<div class="tr-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">'
        + '<label>Qualche parola chiave o istruzione per l\'AI</label>'
        + '<textarea class="tr-ai-keywords" rows="2" placeholder="es. viaggio in montagna con gli amici, tono divertente"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
        + '<button type="button" class="btn small tr-ai-generate">Genera testo</button>'
        + '<button type="button" class="btn small secondary tr-ai-cancel">Annulla</button></div>'
        + '<p class="tr-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p></div>'
        + '<label>Foto (fino a 10, opzionale)</label>'
        + '<input type="file" class="tr-pub-image-input" accept="image/*" multiple>'
        + '<input type="hidden" class="tr-pub-image-thumb-data">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Senza foto, l\'anteprima social usa una miniatura della mappa.</p>'
        + '<label>Privacy (comparsa nel Feed)</label>'
        + '<div style="display:flex;gap:16px;margin-bottom:14px;">'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="tr-pub-visibility" name="visibility" value="public"' + (item.show_in_feed ? ' checked' : '') + ' style="width:auto;"> Pubblico</label>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="tr-pub-visibility" name="visibility" value="private"' + (!item.show_in_feed ? ' checked' : '') + ' style="width:auto;"> Solo io</label></div>'
        + '<label>Programma la comparsa nel Feed (opzionale)</label>'
        + '<input type="datetime-local" class="tr-pub-publish-at">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per mostrarlo subito nel Feed (se Pubblico). Resta comunque sempre visibile in questa lista e nella sua pagina.</p>'
        + '<label>Link personalizzato per il feed (opzionale)</label>'
        + '<input type="url" class="tr-pub-custom-link" value="' + escapeHtml(customLink) + '" placeholder="https://...">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">' + sinceHint + '</p>'
        + '<p class="tr-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>'
        + '<div style="display:flex;gap:8px;margin-top:4px;">'
        + '<button type="button" class="btn small tr-pub-save">Salva</button>'
        + '<button type="button" class="btn small secondary tr-pub-cancel">Annulla</button></div></form></div>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-tr-favorite', item.id);
      div.setAttribute('data-tr-note', '');
      div.setAttribute('data-tr-has-image', '0');
      div.style.flexDirection = 'column';
      div.style.alignItems = 'stretch';
      div.style.gap = '8px';
      div.innerHTML = favoriteRowHtml(item);
      listBox.prepend(div);
      updateListTitle();
    }

    function addFromFields(placeName, address, lat, lng, btn, statusEl) {
      if (btn) btn.disabled = true;
      if (statusEl) statusEl.textContent = 'Aggiunta in corso...';
      const params = new URLSearchParams();
      params.set('action', 'add');
      params.set('place_name', placeName);
      params.set('address', address || '');
      params.set('lat', lat);
      params.set('lng', lng);
      return post(params).then(function (data) {
        if (btn) btn.disabled = false;
        if (data.ok && data.item) {
          addFavoriteRow(data.item);
          if (statusEl) statusEl.textContent = 'Aggiunto! Lo trovi qui sotto nella tua lista.';
        } else if (statusEl) {
          statusEl.textContent = 'Qualcosa è andato storto, riprova.';
        }
        return data;
      }).catch(function () {
        if (btn) btn.disabled = false;
        if (statusEl) statusEl.textContent = 'Errore di connessione. Riprova.';
      });
    }

    // Aggiungi da un risultato di ricerca (delegato: i risultati vengono ricreati a ogni ricerca)
    resultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.tr-add-btn');
      if (!btn) return;
      addFromFields(btn.dataset.placeName, btn.dataset.address, btn.dataset.lat, btn.dataset.lng, btn, null).then(function (data) {
        if (data && data.ok) {
          btn.closest('.link-item').remove();
        }
      });
    });

    // Aggiungi tramite coordinate manuali
    document.getElementById('tr-manual-add').addEventListener('click', function () {
      const nameInput = document.getElementById('tr-manual-name');
      const latInput = document.getElementById('tr-manual-lat');
      const lngInput = document.getElementById('tr-manual-lng');
      const statusEl = document.getElementById('tr-manual-status');
      const name = nameInput.value.trim();
      const lat = latInput.value.trim().replace(',', '.');
      const lng = lngInput.value.trim().replace(',', '.');
      if (!name || !lat || !lng || isNaN(parseFloat(lat)) || isNaN(parseFloat(lng))) {
        statusEl.textContent = 'Compila nome, latitudine e longitudine (numeriche).';
        return;
      }
      addFromFields(name, '', lat, lng, this, statusEl).then(function (data) {
        if (data && data.ok) {
          nameInput.value = '';
          latInput.value = '';
          lngInput.value = '';
        }
      });
    });

    // Condividi la posizione attuale: il browser chiede il permesso di accedere al GPS del
    // telefono (richiede HTTPS, già attivo sul sito) e il viaggio si aggiunge subito con le
    // coordinate ricevute — il nome del posto lo ricava il server con la geocodifica inversa,
    // non serve digitare nulla.
    document.getElementById('tr-geolocate-btn').addEventListener('click', function () {
      const btn = this;
      const statusEl = document.getElementById('tr-geolocate-status');
      if (!('geolocation' in navigator)) {
        statusEl.textContent = 'Il tuo browser non supporta la geolocalizzazione.';
        return;
      }
      btn.disabled = true;
      statusEl.textContent = 'Recupero della posizione in corso...';
      navigator.geolocation.getCurrentPosition(function (pos) {
        addFromFields('', '', pos.coords.latitude, pos.coords.longitude, btn, statusEl).then(function (data) {
          if (data && data.ok) {
            statusEl.textContent = 'Fatto! Il viaggio con la tua posizione attuale è nella lista qui sotto.';
          }
        });
      }, function (err) {
        btn.disabled = false;
        statusEl.textContent = err && err.code === 1
          ? 'Permesso negato: consenti l\'accesso alla posizione dalle impostazioni del browser per usare questa funzione.'
          : 'Non è stato possibile recuperare la posizione. Riprova.';
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
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

    // Rimuovi/pubblicazione (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const removeBtn = e.target.closest('.tr-remove-btn');
      const pubToggleBtn = e.target.closest('.tr-pub-toggle');
      const pubCancelBtn = e.target.closest('.tr-pub-cancel');
      const pubSaveBtn = e.target.closest('.tr-pub-save');
      const aiToggleBtn = e.target.closest('.tr-ai-toggle');
      const aiCancelBtn = e.target.closest('.tr-ai-cancel');
      const aiGenerateBtn = e.target.closest('.tr-ai-generate');

      if (removeBtn) {
        if (!confirm('Rimuovere questo viaggio dalla tua lista?')) return;
        const row = removeBtn.closest('[data-tr-favorite]');
        const id = row.getAttribute('data-tr-favorite');
        removeBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'remove');
        params.set('id', id);
        post(params).then(function (data) {
          if (data.ok) {
            row.remove();
            updateListTitle();
          } else {
            removeBtn.disabled = false;
          }
        }).catch(function () { removeBtn.disabled = false; });
        return;
      }

      if (pubToggleBtn) {
        const block = pubToggleBtn.closest('.tr-pub-block');
        block.querySelector('.tr-pub-editor').style.display = 'block';
        block.querySelector('.tr-pub-textarea').focus();
        return;
      }

      if (pubCancelBtn) {
        const block = pubCancelBtn.closest('.tr-pub-block');
        const row = pubCancelBtn.closest('[data-tr-favorite]');
        block.querySelector('.tr-pub-textarea').value = row.getAttribute('data-tr-note') || '';
        block.querySelector('.tr-pub-editor').style.display = 'none';
        return;
      }

      if (aiToggleBtn) {
        const panel = aiToggleBtn.closest('.tr-pub-editor').querySelector('.tr-ai-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') panel.querySelector('.tr-ai-keywords').focus();
        return;
      }

      if (aiCancelBtn) {
        const panel = aiCancelBtn.closest('.tr-ai-panel');
        panel.style.display = 'none';
        panel.querySelector('.tr-ai-status').textContent = '';
        return;
      }

      if (aiGenerateBtn) {
        const panel = aiGenerateBtn.closest('.tr-ai-panel');
        const editor = aiGenerateBtn.closest('.tr-pub-editor');
        const keywords = panel.querySelector('.tr-ai-keywords').value.trim();
        const statusEl = panel.querySelector('.tr-ai-status');
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
              editor.querySelector('.tr-pub-textarea').value = data.text;
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
        const editor = pubSaveBtn.closest('.tr-pub-editor');
        const block = pubSaveBtn.closest('.tr-pub-block');
        const row = pubSaveBtn.closest('[data-tr-favorite]');
        const id = row.getAttribute('data-tr-favorite');
        const note = editor.querySelector('.tr-pub-textarea').value;
        const visibility = editor.querySelector('.tr-pub-visibility:checked').value;
        const publishAt = editor.querySelector('.tr-pub-publish-at').value;
        const customLink = editor.querySelector('.tr-pub-custom-link').value;
        const imageInput = editor.querySelector('.tr-pub-image-input');
        const statusEl = editor.querySelector('.tr-pub-status');
        // Fino a 10 foto: solo la prima genera la miniatura leggera (è l'unica che serve nel
        // Feed/nelle liste), le altre viaggiano intere così come sono state selezionate.
        const files = imageInput.files ? Array.from(imageInput.files).slice(0, 10) : [];
        const file = files[0] || null;

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
          if (files.length) {
            files.forEach(function (f) { formData.append('images[]', f); });
            formData.set('image_thumb_data', thumbDataUrl || '');
          }
          postForm(formData).then(function (data) {
            pubSaveBtn.disabled = false;
            if (!data.ok) {
              statusEl.textContent = data.error || 'Salvataggio non riuscito, riprova.';
              return;
            }
            statusEl.textContent = data.error ? data.error : '';
            row.setAttribute('data-tr-note', note);
            row.setAttribute('data-tr-has-image', data.item.image_path ? '1' : '0');
            const textEl = block.querySelector('.tr-pub-text');
            const toggleEl = block.querySelector('.tr-pub-toggle');
            if (note.trim() !== '') {
              textEl.innerHTML = escapeHtml(note).replace(/\n/g, '<br>');
              textEl.style.display = 'block';
            } else {
              textEl.style.display = 'none';
            }
            toggleEl.textContent = '✏️ Gestisci pubblicazione';
            const badges = renderBadges(data.item, data.extra_photo_count);
            const badgesBox = row.querySelector('.tr-pub-badges');
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
    function renderResults(results) {
      if (!results.length) {
        resultsBox.innerHTML = '<div class="card">Nessun risultato per questa ricerca.</div>';
        return;
      }
      let html = '<div class="section-title">Risultati (' + results.length + ')</div>';
      results.forEach(function (r) {
        const shortName = r.display_name.split(',')[0].trim() || r.display_name;
        html += '<div class="link-item"><strong>' + escapeHtml(r.display_name) + '</strong>'
          + '<button type="button" class="btn small tr-add-btn" data-place-name="' + escapeHtml(shortName)
          + '" data-address="' + escapeHtml(r.display_name) + '" data-lat="' + escapeHtml(r.lat) + '" data-lng="' + escapeHtml(r.lng) + '">Aggiungi alla lista</button></div>';
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
        renderResults(data.results || []);
      }).catch(function () {
        searchStatus.textContent = 'Ricerca non riuscita, riprova.';
      });
    }

    let debounceTimer = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      const query = searchInput.value;
      debounceTimer = setTimeout(function () { runSearch(query); }, 500);
    });
    searchForm.addEventListener('submit', function (e) {
      e.preventDefault();
      clearTimeout(debounceTimer);
      runSearch(searchInput.value);
    });
  })();
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
