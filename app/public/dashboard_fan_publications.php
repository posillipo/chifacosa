<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/crossref.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'che_amo';
$pageTitle = 'Pubblicazioni che amo';

$searchResults = [];
$searchQuery = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    $isAjax = !empty($_POST['ajax']);

    if ($action === 'add') {
        $doi = trim($_POST['doi'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $addedRow = null;
        if ($doi !== '' && $title !== '') {
            // is_public=0 di proposito: un elemento appena aggiunto parte "Solo io", la
            // pubblicazione nel Feed va confermata a mano dal pannello di pubblicazione.
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_publications
                (user_id, crossref_doi, publication_title, is_public, sort_order)
                VALUES (?, ?, ?, 0, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_publications WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $doi, $title, $profile['id']]);
            // Non ci si fida di lastInsertId(): con INSERT IGNORE su un duplicato resterebbe a 0
            // o non aggiornato — si rilegge sempre la riga vera dal database.
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_publications WHERE user_id=? AND crossref_doi=?');
            $stmt->execute([$profile['id'], $doi]);
            $addedRow = $stmt->fetch() ?: null;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $addedRow, 'item' => $addedRow]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_publications WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteCoverFile($row['image_thumb_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_publications WHERE id=? AND user_id=?');
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
        $isPublic = $visibility === 'public' ? 1 : 0;
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;

        // Interpretato nel fuso orario scelto dal profilo (Dashboard -> Profilo e anagrafica),
        // non in quello del server — vedi parseLocalDateTime() in functions.php.
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        if ($publishAt && strtotime($publishAt) <= time()) {
            $publishAt = null;
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
            $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_publications WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            if ($old = $stmt->fetch()) {
                deleteCoverFile($old['image_path']);
                deleteCoverFile($old['image_thumb_path']);
            }
            $stmt = getDB()->prepare('UPDATE fan_favorite_publications SET note=?, is_public=?, in_feed=?, publish_at=?, image_path=?, image_thumb_path=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $isPublic, $inFeed, $publishAt, $imagePath, $imageThumbPath, $id, $profile['id']]);
        } else {
            $stmt = getDB()->prepare('UPDATE fan_favorite_publications SET note=?, is_public=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $isPublic, $inFeed, $publishAt, $id, $profile['id']]);
        }

        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_publications WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $row, 'item' => $row, 'error' => $error]);
            exit;
        }
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = crossrefSearch($searchQuery);
        }
        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT crossref_doi FROM fan_favorite_publications WHERE user_id=?');
            $stmt->execute([$profile['id']]);
            $favIds = array_column($stmt->fetchAll(), 'crossref_doi');
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $searchResults, 'favoriteIds' => $favIds]);
            exit;
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_publications WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$favorites = $stmt->fetchAll();
$favoriteIds = array_column($favorites, 'crossref_doi');

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca pubblicazioni scientifiche/accademiche (catalogo CrossRef — oltre 150 milioni di
      articoli, atti di convegni e altri lavori indicizzati con DOI) e aggiungile alla tua lista.
      Comparirà sulla tua pagina pubblica come vetrina di ciò che ami leggere. La ricerca parte da
      sola mentre scrivi, e aggiungere/rimuovere una pubblicazione aggiorna la lista all'istante,
      senza ricaricare la pagina.
    </p>
    <p style="color:var(--text-muted)">
      Ogni pubblicazione aggiunta ha una sua pagina pubblica dedicata (raggiungibile cliccandoci
      sopra), condivisibile sui social con anteprima titolo/testo. Da "✏️ Gestisci pubblicazione"
      puoi scrivere perché ti interessa (anche con l'aiuto dell'AI), aggiungere una foto, decidere
      se deve comparire nel Feed (Pubblico/Solo io), programmarne la comparsa per una data futura
      e impostare il link personalizzato per il feed — stessa logica della Timeline.
    </p>
    <p style="color:var(--text-muted)">
      Ogni nuovo elemento aggiunto parte impostato su <strong>Solo io</strong>: resta visibile
      nella tua lista e nella sua pagina, ma compare nel Feed solo dopo che lo confermi come
      Pubblico da "✏️ Gestisci pubblicazione".
    </p>
  </details>

  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card" id="pb-search-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca una pubblicazione su CrossRef</label>
    <input type="text" name="query" id="pb-search-input" value="<?= e($searchQuery) ?>" placeholder="es. titolo, autore o argomento" autocomplete="off">
    <button type="submit" class="btn">Cerca</button>
    <p id="pb-search-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
  </form>

  <div id="pb-search-results">
    <?php if ($searchResults): ?>
      <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
      <?php foreach ($searchResults as $r): ?>
        <div class="link-item" data-pb-result="<?= e($r['id']) ?>">
          <div style="display:flex;align-items:center;gap:12px;min-width:0;">
            <strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;"><?= e($r['name']) ?></strong>
          </div>
          <?php if (in_array($r['id'], $favoriteIds, true)): ?>
            <span class="pb-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>
          <?php else: ?>
            <button type="button" class="btn small pb-add-btn"
              data-doi="<?= e($r['id']) ?>" data-title="<?= e($r['title']) ?>">
              Aggiungi alla lista
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="card">Nessun risultato per questa ricerca.</div>
    <?php endif; ?>
  </div>

  <div class="section-title" id="pb-list-title">La tua lista (<?= count($favorites) ?>)</div>
  <div id="pb-list-empty" class="alert error" style="<?= $favorites ? 'display:none;' : '' ?>">Nessuna pubblicazione aggiunta ancora — cercala qui sopra.</div>
  <div id="pb-list">
    <?php foreach ($favorites as $f): ?>
      <?php
        $note = trim($f['note'] ?? '');
        $isPrivate = !$f['is_public'];
        $isScheduled = $f['publish_at'] && strtotime($f['publish_at']) > time();
      ?>
      <div class="link-item" data-pb-favorite="<?= (int)$f['id'] ?>" data-pb-doi="<?= e($f['crossref_doi']) ?>"
           data-pb-note="<?= e($note) ?>" data-pb-has-image="<?= $f['image_path'] ? '1' : '0' ?>"
           style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <a href="/<?= e($profile['slug']) ?>/pubblicazioni-che-amo/<?= (int)$f['id'] ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
            <strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($f['publication_title']) ?></strong>
          </a>
          <button type="button" class="btn small danger pb-remove-btn" style="flex-shrink:0;">Rimuovi</button>
        </div>
        <div class="pb-pub-badges" style="display:flex;gap:6px;flex-wrap:wrap;<?= (!$isScheduled && !$isPrivate) ? 'display:none;' : '' ?>">
          <?php if ($isScheduled): ?><span class="pb-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il <?= e(formatLocalDateTime($f['publish_at'], $profile)) ?></span><?php endif; ?>
          <?php if ($isPrivate): ?><span class="pb-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span><?php endif; ?>
        </div>
        <div class="pb-pub-block">
          <?php if ($note !== ''): ?>
            <p class="pb-pub-text" style="margin:0;font-size:14px;"><?= nl2br(e($note)) ?></p>
          <?php else: ?>
            <p class="pb-pub-text" style="margin:0;font-size:14px;display:none;"></p>
          <?php endif; ?>
          <button type="button" class="btn small secondary pb-pub-toggle">✏️ Gestisci pubblicazione</button>
          <form class="pb-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">
            <label>Racconta perché ti interessa</label>
            <textarea class="pb-pub-textarea" rows="3" placeholder="Racconta perché ti interessa"><?= e($note) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">
              <button type="button" class="btn small secondary pb-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="pb-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="pb-ai-keywords" rows="2" placeholder="es. uno studio che mi ha fatto vedere l'argomento in modo diverso"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small pb-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary pb-ai-cancel">Annulla</button>
              </div>
              <p class="pb-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>

            <label>Foto (opzionale)</label>
            <input type="file" class="pb-pub-image-input" accept="image/*">
            <input type="hidden" class="pb-pub-image-thumb-data">
            <?php if ($f['image_path']): ?><p style="color:var(--text-muted);font-size:12.5px;margin-top:6px;">Hai già caricato una foto — seleziona un nuovo file per sostituirla.</p><?php endif; ?>

            <label>Privacy</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="pb-pub-visibility" name="visibility" value="public" <?= $isPrivate ? '' : 'checked' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="pb-pub-visibility" name="visibility" value="private" <?= $isPrivate ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>

            <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
              <input type="checkbox" class="pb-pub-in-feed" name="in_feed" value="1" <?= ($f['in_feed'] ?? 1) ? 'checked' : '' ?> style="width:auto;"> Includi nel Feed
            </label>
            <p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Non riguarda la Timeline del sito (quella segue solo Pubblico/Solo io): serve solo per le automazioni social (es. Metricool) che leggono il feed RSS del profilo.</p>

            <label>Programma la pubblicazione (opzionale)</label>
            <input type="datetime-local" class="pb-pub-publish-at" value="<?= $f['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($f['publish_at']))) : '' ?>">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>

            <p class="pb-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small pb-pub-save">Salva</button>
              <button type="button" class="btn small secondary pb-pub-cancel">Annulla</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function () {
    const csrfInput = document.querySelector('#pb-search-form input[name="csrf"]');
    const searchForm = document.getElementById('pb-search-form');
    const searchInput = document.getElementById('pb-search-input');
    const searchStatus = document.getElementById('pb-search-status');
    const resultsBox = document.getElementById('pb-search-results');
    const listBox = document.getElementById('pb-list');
    const listTitle = document.getElementById('pb-list-title');
    const listEmpty = document.getElementById('pb-list-empty');
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
      return fetch('/dashboard_fan_publications.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function postForm(formData) {
      formData.set('csrf', csrfInput.value);
      formData.set('ajax', '1');
      return fetch('/dashboard_fan_publications.php', { method: 'POST', body: formData }).then(r => r.json());
    }

    function markResultAsAdded(doi) {
      const row = resultsBox.querySelector('[data-pb-result="' + CSS.escape(doi) + '"]');
      if (!row) return;
      const btn = row.querySelector('.pb-add-btn');
      if (btn) btn.outerHTML = '<span class="pb-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
    }

    function markResultAsRemovable(doi) {
      const row = resultsBox.querySelector('[data-pb-result="' + CSS.escape(doi) + '"]');
      if (!row) return;
      const span = row.querySelector('.pb-already');
      if (!span) return;
      const title = row.querySelector('strong').textContent;
      span.outerHTML = '<button type="button" class="btn small pb-add-btn" data-doi="' + escapeHtml(doi)
        + '" data-title="' + escapeHtml(title) + '">Aggiungi alla lista</button>';
    }

    function favoriteRowHtml(item) {
      const badges = renderBadges(item);
      return '<div style="display:flex;align-items:center;gap:12px;">'
        + '<a href="/' + escapeHtml(profileSlug) + '/pubblicazioni-che-amo/' + item.id + '" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">'
        + '<strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(item.publication_title) + '</strong></a>'
        + '<button type="button" class="btn small danger pb-remove-btn" style="flex-shrink:0;">Rimuovi</button></div>'
        + '<div class="pb-pub-badges" style="display:' + (badges.visible ? 'flex' : 'none') + ';gap:6px;flex-wrap:wrap;">' + badges.html + '</div>'
        + '<div class="pb-pub-block">'
        + '<p class="pb-pub-text" style="margin:0;font-size:14px;display:none;"></p>'
        + '<button type="button" class="btn small secondary pb-pub-toggle">✏️ Gestisci pubblicazione</button>'
        + '<form class="pb-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">'
        + '<label>Racconta perché ti interessa</label>'
        + '<textarea class="pb-pub-textarea" rows="3" placeholder="Racconta perché ti interessa"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">'
        + '<button type="button" class="btn small secondary pb-ai-toggle">✨ Genera con AI</button></div>'
        + '<div class="pb-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">'
        + '<label>Qualche parola chiave o istruzione per l\'AI</label>'
        + '<textarea class="pb-ai-keywords" rows="2" placeholder="es. uno studio che mi ha fatto vedere l\'argomento in modo diverso"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
        + '<button type="button" class="btn small pb-ai-generate">Genera testo</button>'
        + '<button type="button" class="btn small secondary pb-ai-cancel">Annulla</button></div>'
        + '<p class="pb-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p></div>'
        + '<label>Foto (opzionale)</label>'
        + '<input type="file" class="pb-pub-image-input" accept="image/*">'
        + '<input type="hidden" class="pb-pub-image-thumb-data">'
        + '<label>Privacy</label>'
        + '<div style="display:flex;gap:16px;margin-bottom:14px;">'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="pb-pub-visibility" name="visibility" value="public"' + (item.is_public ? ' checked' : '') + ' style="width:auto;"> Pubblico</label>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="pb-pub-visibility" name="visibility" value="private"' + (!item.is_public ? ' checked' : '') + ' style="width:auto;"> Solo io</label></div>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;"><input type="checkbox" class="pb-pub-in-feed" name="in_feed" value="1"' + (item.in_feed == 1 ? ' checked' : '') + ' style="width:auto;"> Includi nel Feed</label>'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Non riguarda la Timeline del sito (quella segue solo Pubblico/Solo io): serve solo per le automazioni social (es. Metricool) che leggono il feed RSS del profilo.</p>'
        + '<label>Programma la pubblicazione (opzionale)</label>'
        + '<input type="datetime-local" class="pb-pub-publish-at">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>'
        + '<p class="pb-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>'
        + '<div style="display:flex;gap:8px;margin-top:4px;">'
        + '<button type="button" class="btn small pb-pub-save">Salva</button>'
        + '<button type="button" class="btn small secondary pb-pub-cancel">Annulla</button></div></form></div>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-pb-favorite', item.id);
      div.setAttribute('data-pb-doi', item.crossref_doi);
      div.setAttribute('data-pb-note', '');
      div.setAttribute('data-pb-has-image', '0');
      div.style.flexDirection = 'column';
      div.style.alignItems = 'stretch';
      div.style.gap = '8px';
      div.innerHTML = favoriteRowHtml(item);
      listBox.prepend(div);
      updateListTitle();
    }

    // Aggiungi (delegato: i risultati di ricerca vengono ricreati a ogni ricerca)
    resultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.pb-add-btn');
      if (!btn) return;
      btn.disabled = true;
      const doi = btn.dataset.doi;
      const params = new URLSearchParams();
      params.set('action', 'add');
      params.set('doi', doi);
      params.set('title', btn.dataset.title);
      post(params).then(function (data) {
        if (data.ok && data.item) {
          markResultAsAdded(doi);
          addFavoriteRow(data.item);
        } else {
          btn.disabled = false;
        }
      }).catch(function () { btn.disabled = false; });
    });

    // Genera una miniatura JPEG leggera (max 600px) dalla foto selezionata, nel browser — vedi
    // stessa logica in dashboard_post.php.
    function generateThumb(file, callback) {
      const img = new Image();
      const reader = new FileReader();
      reader.onload = function (e) {
        img.onload = function () {
          const maxDim = 600;
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
      const isPrivate = !item.is_public || item.is_public == 0;
      let html = '';
      if (isScheduled) html += '<span class="pb-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il ' + escapeHtml(item.publish_at) + '</span>';
      if (isPrivate) html += '<span class="pb-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span>';
      return { html: html, visible: isScheduled || isPrivate };
    }

    // Rimuovi/pubblicazione (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const removeBtn = e.target.closest('.pb-remove-btn');
      const pubToggleBtn = e.target.closest('.pb-pub-toggle');
      const pubCancelBtn = e.target.closest('.pb-pub-cancel');
      const pubSaveBtn = e.target.closest('.pb-pub-save');
      const aiToggleBtn = e.target.closest('.pb-ai-toggle');
      const aiCancelBtn = e.target.closest('.pb-ai-cancel');
      const aiGenerateBtn = e.target.closest('.pb-ai-generate');

      if (removeBtn) {
        if (!confirm('Rimuovere questa pubblicazione dalla tua lista?')) return;
        const row = removeBtn.closest('[data-pb-favorite]');
        const id = row.getAttribute('data-pb-favorite');
        const doi = row.getAttribute('data-pb-doi');
        removeBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'remove');
        params.set('id', id);
        post(params).then(function (data) {
          if (data.ok) {
            row.remove();
            updateListTitle();
            markResultAsRemovable(doi);
          } else {
            removeBtn.disabled = false;
          }
        }).catch(function () { removeBtn.disabled = false; });
        return;
      }

      if (pubToggleBtn) {
        const block = pubToggleBtn.closest('.pb-pub-block');
        block.querySelector('.pb-pub-editor').style.display = 'block';
        block.querySelector('.pb-pub-textarea').focus();
        return;
      }

      if (pubCancelBtn) {
        const block = pubCancelBtn.closest('.pb-pub-block');
        const row = pubCancelBtn.closest('[data-pb-favorite]');
        block.querySelector('.pb-pub-textarea').value = row.getAttribute('data-pb-note') || '';
        block.querySelector('.pb-pub-editor').style.display = 'none';
        return;
      }

      if (aiToggleBtn) {
        const panel = aiToggleBtn.closest('.pb-pub-editor').querySelector('.pb-ai-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') panel.querySelector('.pb-ai-keywords').focus();
        return;
      }

      if (aiCancelBtn) {
        const panel = aiCancelBtn.closest('.pb-ai-panel');
        panel.style.display = 'none';
        panel.querySelector('.pb-ai-status').textContent = '';
        return;
      }

      if (aiGenerateBtn) {
        const panel = aiGenerateBtn.closest('.pb-ai-panel');
        const editor = aiGenerateBtn.closest('.pb-pub-editor');
        const keywords = panel.querySelector('.pb-ai-keywords').value.trim();
        const statusEl = panel.querySelector('.pb-ai-status');
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
              editor.querySelector('.pb-pub-textarea').value = data.text;
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
        const editor = pubSaveBtn.closest('.pb-pub-editor');
        const block = pubSaveBtn.closest('.pb-pub-block');
        const row = pubSaveBtn.closest('[data-pb-favorite]');
        const id = row.getAttribute('data-pb-favorite');
        const note = editor.querySelector('.pb-pub-textarea').value;
        const visibility = editor.querySelector('.pb-pub-visibility:checked').value;
        const inFeed = editor.querySelector('.pb-pub-in-feed').checked;
        const publishAt = editor.querySelector('.pb-pub-publish-at').value;
        const imageInput = editor.querySelector('.pb-pub-image-input');
        const statusEl = editor.querySelector('.pb-pub-status');
        const file = imageInput.files && imageInput.files[0];

        function submit(thumbDataUrl) {
          pubSaveBtn.disabled = true;
          statusEl.textContent = 'Salvataggio...';
          const formData = new FormData();
          formData.set('action', 'save_details');
          formData.set('id', id);
          formData.set('note', note);
          formData.set('visibility', visibility);
          formData.set('in_feed', inFeed ? '1' : '');
          formData.set('publish_at', publishAt);
          // Il fuso orario del profilo (Dashboard -> Profilo e anagrafica) descrive come va
          // MOSTRATO il contenuto pubblicato, non necessariamente dove si trova chi lo sta
          // programmando in questo momento (es. in viaggio) — l'offset del browser riflette
          // invece l'orologio reale di chi sta digitando, quindi ha sempre la precedenza in
          // lettura (vedi parseLocalDateTime() in functions.php).
          formData.set('tz_offset_minutes', new Date().getTimezoneOffset());
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
            row.setAttribute('data-pb-note', note);
            row.setAttribute('data-pb-has-image', data.item.image_path ? '1' : '0');
            const textEl = block.querySelector('.pb-pub-text');
            const toggleEl = block.querySelector('.pb-pub-toggle');
            if (note.trim() !== '') {
              textEl.innerHTML = escapeHtml(note).replace(/\n/g, '<br>');
              textEl.style.display = 'block';
            } else {
              textEl.style.display = 'none';
            }
            toggleEl.textContent = '✏️ Gestisci pubblicazione';
            const badges = renderBadges(data.item);
            const badgesBox = row.querySelector('.pb-pub-badges');
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
        html += '<div class="link-item" data-pb-result="' + escapeHtml(r.id) + '">'
          + '<div style="display:flex;align-items:center;gap:12px;min-width:0;">'
          + '<strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(r.name) + '</strong></div>';
        if (favIds.indexOf(r.id) !== -1) {
          html += '<span class="pb-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
        } else {
          html += '<button type="button" class="btn small pb-add-btn" data-doi="' + escapeHtml(r.id)
            + '" data-title="' + escapeHtml(r.title) + '">Aggiungi alla lista</button>';
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
