<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/thesportsdb.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'che_amo';
$pageTitle = 'Squadre che amo';

$searchResults = [];
$searchQuery = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    $isAjax = !empty($_POST['ajax']);

    if ($action === 'add') {
        $teamId = trim($_POST['team_id'] ?? '');
        $teamName = trim($_POST['team_name'] ?? '');
        $teamImage = trim($_POST['team_image'] ?? '');
        $addedRow = null;
        if ($teamId !== '') {
            // is_public=0 di proposito: un elemento appena aggiunto parte "Solo io", la
            // pubblicazione nel Feed va confermata a mano dal pannello di pubblicazione.
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_teams
                (user_id, thesportsdb_team_id, team_name, team_badge, is_public, sort_order)
                VALUES (?, ?, ?, ?, 0, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_teams WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $teamId, $teamName, $teamImage ?: null, $profile['id']]);
            // Non ci si fida di lastInsertId(): con INSERT IGNORE su un duplicato resterebbe a 0
            // o non aggiornato — si rilegge sempre la riga vera dal database.
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_teams WHERE user_id=? AND thesportsdb_team_id=?');
            $stmt->execute([$profile['id'], $teamId]);
            $addedRow = $stmt->fetch() ?: null;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $addedRow, 'item' => $addedRow]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_teams WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteCoverFile($row['image_thumb_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_teams WHERE id=? AND user_id=?');
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
            $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_teams WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            if ($old = $stmt->fetch()) {
                deleteCoverFile($old['image_path']);
                deleteCoverFile($old['image_thumb_path']);
            }
            $stmt = getDB()->prepare('UPDATE fan_favorite_teams SET note=?, is_public=?, in_feed=?, publish_at=?, image_path=?, image_thumb_path=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $isPublic, $inFeed, $publishAt, $imagePath, $imageThumbPath, $id, $profile['id']]);
        } else {
            $stmt = getDB()->prepare('UPDATE fan_favorite_teams SET note=?, is_public=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $isPublic, $inFeed, $publishAt, $id, $profile['id']]);
        }

        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_teams WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $row, 'item' => $row, 'error' => $error]);
            exit;
        }
    } elseif ($action === 'search') {
        $searchQuery = trim($_POST['query'] ?? '');
        if ($searchQuery !== '') {
            $searchResults = thesportsdbSearchTeam($searchQuery);
        }
        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT thesportsdb_team_id FROM fan_favorite_teams WHERE user_id=?');
            $stmt->execute([$profile['id']]);
            $favIds = array_column($stmt->fetchAll(), 'thesportsdb_team_id');
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $searchResults, 'favoriteIds' => $favIds]);
            exit;
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_teams WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$favorites = $stmt->fetchAll();
$favoriteIds = array_column($favorites, 'thesportsdb_team_id');

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Cerca squadre sportive su TheSportsDB e aggiungile alla tua lista — qualsiasi squadra del
      suo catalogo. Comparirà sulla tua pagina pubblica come vetrina di ciò che ami seguire, con
      campionato, paese, stadio e la prossima partita in programma.
      La ricerca parte da sola mentre scrivi, e aggiungere/rimuovere una squadra aggiorna la lista
      all'istante, senza ricaricare la pagina.
    </p>
    <p style="color:var(--text-muted)">
      Ogni squadra aggiunta ha una sua pagina pubblica dedicata (raggiungibile cliccandoci sopra),
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

  <form method="post" class="card" id="tm-search-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search">
    <label>Cerca una squadra su TheSportsDB</label>
    <input type="text" name="query" id="tm-search-input" value="<?= e($searchQuery) ?>" placeholder="es. nome della squadra" autocomplete="off">
    <button type="submit" class="btn">Cerca</button>
    <p id="tm-search-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
  </form>

  <div id="tm-search-results">
    <?php if ($searchResults): ?>
      <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
      <?php foreach ($searchResults as $r): ?>
        <div class="link-item" data-tm-result="<?= e($r['id']) ?>">
          <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($r['image']): ?>
              <img src="<?= e($r['image']) ?>" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;">
            <?php endif; ?>
            <strong><?= e($r['name']) ?></strong>
          </div>
          <?php if (in_array($r['id'], $favoriteIds, true)): ?>
            <span class="tm-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>
          <?php else: ?>
            <button type="button" class="btn small tm-add-btn"
              data-team-id="<?= e($r['id']) ?>" data-team-name="<?= e($r['title']) ?>" data-team-image="<?= e($r['image'] ?? '') ?>">
              Aggiungi alla lista
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php elseif ($searchQuery !== ''): ?>
      <div class="card">Nessun risultato per questa ricerca.</div>
    <?php endif; ?>
  </div>

  <div class="section-title" id="tm-list-title">La tua lista (<?= count($favorites) ?>)</div>
  <div id="tm-list-empty" class="alert error" style="<?= $favorites ? 'display:none;' : '' ?>">Nessuna squadra aggiunta ancora — cercala qui sopra.</div>
  <div id="tm-list">
    <?php foreach ($favorites as $f): ?>
      <?php
        $note = trim($f['note'] ?? '');
        $isPrivate = !$f['is_public'];
        $isScheduled = $f['publish_at'] && strtotime($f['publish_at']) > time();
      ?>
      <div class="link-item" data-tm-favorite="<?= (int)$f['id'] ?>" data-tm-team-id="<?= e($f['thesportsdb_team_id']) ?>"
           data-tm-note="<?= e($note) ?>" data-tm-has-image="<?= $f['image_path'] ? '1' : '0' ?>"
           style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <a href="/<?= e($profile['slug']) ?>/squadre-che-amo/<?= (int)$f['id'] ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
            <?php if ($f['team_badge']): ?>
              <img src="<?= e($f['team_badge']) ?>" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;">
            <?php endif; ?>
            <strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($f['team_name']) ?></strong>
          </a>
          <button type="button" class="btn small danger tm-remove-btn" style="flex-shrink:0;">Rimuovi</button>
        </div>
        <div class="tm-pub-badges" style="display:flex;gap:6px;flex-wrap:wrap;<?= (!$isScheduled && !$isPrivate) ? 'display:none;' : '' ?>">
          <?php if ($isScheduled): ?><span class="tm-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il <?= e(formatLocalDateTime($f['publish_at'], $profile)) ?></span><?php endif; ?>
          <?php if ($isPrivate): ?><span class="tm-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span><?php endif; ?>
        </div>
        <div class="tm-pub-block">
          <?php if ($note !== ''): ?>
            <p class="tm-pub-text" style="margin:0;font-size:14px;"><?= nl2br(e($note)) ?></p>
          <?php else: ?>
            <p class="tm-pub-text" style="margin:0;font-size:14px;display:none;"></p>
          <?php endif; ?>
          <button type="button" class="btn small secondary tm-pub-toggle">✏️ Gestisci pubblicazione</button>
          <form class="tm-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">
            <label>Racconta perché ti piace</label>
            <textarea class="tm-pub-textarea" rows="3" placeholder="Racconta perché ti piace"><?= e($note) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">
              <button type="button" class="btn small secondary tm-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="tm-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="tm-ai-keywords" rows="2" placeholder="es. la squadra che tifo da sempre"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small tm-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary tm-ai-cancel">Annulla</button>
              </div>
              <p class="tm-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>

            <label>Foto (opzionale)</label>
            <input type="file" class="tm-pub-image-input" accept="image/*">
            <input type="hidden" class="tm-pub-image-thumb-data">
            <?php if ($f['image_path']): ?><p style="color:var(--text-muted);font-size:12.5px;margin-top:6px;">Hai già caricato una foto — seleziona un nuovo file per sostituirla.</p><?php endif; ?>

            <label>Privacy</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="tm-pub-visibility" name="visibility" value="public" <?= $isPrivate ? '' : 'checked' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="tm-pub-visibility" name="visibility" value="private" <?= $isPrivate ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>

            <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
              <input type="checkbox" class="tm-pub-in-feed" name="in_feed" value="1" <?= ($f['in_feed'] ?? 1) ? 'checked' : '' ?> style="width:auto;"> Includi nel Feed
            </label>
            <p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Non riguarda la Timeline del sito (quella segue solo Pubblico/Solo io): serve solo per le automazioni social (es. Metricool) che leggono il feed RSS del profilo.</p>

            <label>Programma la pubblicazione (opzionale)</label>
            <input type="datetime-local" class="tm-pub-publish-at" value="<?= $f['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($f['publish_at']))) : '' ?>">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>

            <p class="tm-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small tm-pub-save">Salva</button>
              <button type="button" class="btn small secondary tm-pub-cancel">Annulla</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function () {
    const csrfInput = document.querySelector('#tm-search-form input[name="csrf"]');
    const searchForm = document.getElementById('tm-search-form');
    const searchInput = document.getElementById('tm-search-input');
    const searchStatus = document.getElementById('tm-search-status');
    const resultsBox = document.getElementById('tm-search-results');
    const listBox = document.getElementById('tm-list');
    const listTitle = document.getElementById('tm-list-title');
    const listEmpty = document.getElementById('tm-list-empty');
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
      return fetch('/dashboard_fan_teams.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function postForm(formData) {
      formData.set('csrf', csrfInput.value);
      formData.set('ajax', '1');
      return fetch('/dashboard_fan_teams.php', { method: 'POST', body: formData }).then(r => r.json());
    }

    function markResultAsAdded(teamId) {
      const row = resultsBox.querySelector('[data-tm-result="' + CSS.escape(teamId) + '"]');
      if (!row) return;
      const btn = row.querySelector('.tm-add-btn');
      if (btn) btn.outerHTML = '<span class="tm-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
    }

    function markResultAsRemovable(teamId) {
      const row = resultsBox.querySelector('[data-tm-result="' + CSS.escape(teamId) + '"]');
      if (!row) return;
      const span = row.querySelector('.tm-already');
      if (!span) return;
      const title = row.querySelector('strong').textContent;
      const img = row.querySelector('img');
      span.outerHTML = '<button type="button" class="btn small tm-add-btn" data-team-id="' + escapeHtml(teamId)
        + '" data-team-name="' + escapeHtml(title) + '" data-team-image="' + escapeHtml(img ? img.getAttribute('src') : '') + '">Aggiungi alla lista</button>';
    }

    function favoriteRowHtml(item) {
      const badges = renderBadges(item);
      const img = item.team_badge ? '<img src="' + escapeHtml(item.team_badge) + '" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;">' : '';
      return '<div style="display:flex;align-items:center;gap:12px;">'
        + '<a href="/' + escapeHtml(profileSlug) + '/squadre-che-amo/' + item.id + '" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">'
        + img + '<strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(item.team_name) + '</strong></a>'
        + '<button type="button" class="btn small danger tm-remove-btn" style="flex-shrink:0;">Rimuovi</button></div>'
        + '<div class="tm-pub-badges" style="display:' + (badges.visible ? 'flex' : 'none') + ';gap:6px;flex-wrap:wrap;">' + badges.html + '</div>'
        + '<div class="tm-pub-block">'
        + '<p class="tm-pub-text" style="margin:0;font-size:14px;display:none;"></p>'
        + '<button type="button" class="btn small secondary tm-pub-toggle">✏️ Gestisci pubblicazione</button>'
        + '<form class="tm-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">'
        + '<label>Racconta perché ti piace</label>'
        + '<textarea class="tm-pub-textarea" rows="3" placeholder="Racconta perché ti piace"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">'
        + '<button type="button" class="btn small secondary tm-ai-toggle">✨ Genera con AI</button></div>'
        + '<div class="tm-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">'
        + '<label>Qualche parola chiave o istruzione per l\'AI</label>'
        + '<textarea class="tm-ai-keywords" rows="2" placeholder="es. la squadra che tifo da sempre"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
        + '<button type="button" class="btn small tm-ai-generate">Genera testo</button>'
        + '<button type="button" class="btn small secondary tm-ai-cancel">Annulla</button></div>'
        + '<p class="tm-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p></div>'
        + '<label>Foto (opzionale)</label>'
        + '<input type="file" class="tm-pub-image-input" accept="image/*">'
        + '<input type="hidden" class="tm-pub-image-thumb-data">'
        + '<label>Privacy</label>'
        + '<div style="display:flex;gap:16px;margin-bottom:14px;">'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="tm-pub-visibility" name="visibility" value="public"' + (item.is_public ? ' checked' : '') + ' style="width:auto;"> Pubblico</label>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="tm-pub-visibility" name="visibility" value="private"' + (!item.is_public ? ' checked' : '') + ' style="width:auto;"> Solo io</label></div>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;"><input type="checkbox" class="tm-pub-in-feed" name="in_feed" value="1"' + (item.in_feed == 1 ? ' checked' : '') + ' style="width:auto;"> Includi nel Feed</label>'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Non riguarda la Timeline del sito (quella segue solo Pubblico/Solo io): serve solo per le automazioni social (es. Metricool) che leggono il feed RSS del profilo.</p>'
        + '<label>Programma la pubblicazione (opzionale)</label>'
        + '<input type="datetime-local" class="tm-pub-publish-at">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>'
        + '<p class="tm-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>'
        + '<div style="display:flex;gap:8px;margin-top:4px;">'
        + '<button type="button" class="btn small tm-pub-save">Salva</button>'
        + '<button type="button" class="btn small secondary tm-pub-cancel">Annulla</button></div></form></div>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-tm-favorite', item.id);
      div.setAttribute('data-tm-team-id', item.thesportsdb_team_id);
      div.setAttribute('data-tm-note', '');
      div.setAttribute('data-tm-has-image', '0');
      div.style.flexDirection = 'column';
      div.style.alignItems = 'stretch';
      div.style.gap = '8px';
      div.innerHTML = favoriteRowHtml(item);
      listBox.prepend(div);
      updateListTitle();
    }

    // Aggiungi (delegato: i risultati di ricerca vengono ricreati a ogni ricerca)
    resultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.tm-add-btn');
      if (!btn) return;
      btn.disabled = true;
      const teamId = btn.dataset.teamId;
      const params = new URLSearchParams();
      params.set('action', 'add');
      params.set('team_id', teamId);
      params.set('team_name', btn.dataset.teamName);
      params.set('team_image', btn.dataset.teamImage || '');
      post(params).then(function (data) {
        if (data.ok && data.item) {
          markResultAsAdded(teamId);
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
      if (isScheduled) html += '<span class="tm-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il ' + escapeHtml(item.publish_at) + '</span>';
      if (isPrivate) html += '<span class="tm-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span>';
      return { html: html, visible: isScheduled || isPrivate };
    }

    // Rimuovi/pubblicazione (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const removeBtn = e.target.closest('.tm-remove-btn');
      const pubToggleBtn = e.target.closest('.tm-pub-toggle');
      const pubCancelBtn = e.target.closest('.tm-pub-cancel');
      const pubSaveBtn = e.target.closest('.tm-pub-save');
      const aiToggleBtn = e.target.closest('.tm-ai-toggle');
      const aiCancelBtn = e.target.closest('.tm-ai-cancel');
      const aiGenerateBtn = e.target.closest('.tm-ai-generate');

      if (removeBtn) {
        if (!confirm('Rimuovere questa squadra dalla tua lista?')) return;
        const row = removeBtn.closest('[data-tm-favorite]');
        const id = row.getAttribute('data-tm-favorite');
        const teamId = row.getAttribute('data-tm-team-id');
        removeBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'remove');
        params.set('id', id);
        post(params).then(function (data) {
          if (data.ok) {
            row.remove();
            updateListTitle();
            markResultAsRemovable(teamId);
          } else {
            removeBtn.disabled = false;
          }
        }).catch(function () { removeBtn.disabled = false; });
        return;
      }

      if (pubToggleBtn) {
        const block = pubToggleBtn.closest('.tm-pub-block');
        block.querySelector('.tm-pub-editor').style.display = 'block';
        block.querySelector('.tm-pub-textarea').focus();
        return;
      }

      if (pubCancelBtn) {
        const block = pubCancelBtn.closest('.tm-pub-block');
        const row = pubCancelBtn.closest('[data-tm-favorite]');
        block.querySelector('.tm-pub-textarea').value = row.getAttribute('data-tm-note') || '';
        block.querySelector('.tm-pub-editor').style.display = 'none';
        return;
      }

      if (aiToggleBtn) {
        const panel = aiToggleBtn.closest('.tm-pub-editor').querySelector('.tm-ai-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') panel.querySelector('.tm-ai-keywords').focus();
        return;
      }

      if (aiCancelBtn) {
        const panel = aiCancelBtn.closest('.tm-ai-panel');
        panel.style.display = 'none';
        panel.querySelector('.tm-ai-status').textContent = '';
        return;
      }

      if (aiGenerateBtn) {
        const panel = aiGenerateBtn.closest('.tm-ai-panel');
        const editor = aiGenerateBtn.closest('.tm-pub-editor');
        const keywords = panel.querySelector('.tm-ai-keywords').value.trim();
        const statusEl = panel.querySelector('.tm-ai-status');
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
              editor.querySelector('.tm-pub-textarea').value = data.text;
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
        const editor = pubSaveBtn.closest('.tm-pub-editor');
        const block = pubSaveBtn.closest('.tm-pub-block');
        const row = pubSaveBtn.closest('[data-tm-favorite]');
        const id = row.getAttribute('data-tm-favorite');
        const note = editor.querySelector('.tm-pub-textarea').value;
        const visibility = editor.querySelector('.tm-pub-visibility:checked').value;
        const inFeed = editor.querySelector('.tm-pub-in-feed').checked;
        const publishAt = editor.querySelector('.tm-pub-publish-at').value;
        const imageInput = editor.querySelector('.tm-pub-image-input');
        const statusEl = editor.querySelector('.tm-pub-status');
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
            row.setAttribute('data-tm-note', note);
            row.setAttribute('data-tm-has-image', data.item.image_path ? '1' : '0');
            const textEl = block.querySelector('.tm-pub-text');
            const toggleEl = block.querySelector('.tm-pub-toggle');
            if (note.trim() !== '') {
              textEl.innerHTML = escapeHtml(note).replace(/\n/g, '<br>');
              textEl.style.display = 'block';
            } else {
              textEl.style.display = 'none';
            }
            toggleEl.textContent = '✏️ Gestisci pubblicazione';
            const badges = renderBadges(data.item);
            const badgesBox = row.querySelector('.tm-pub-badges');
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
        html += '<div class="link-item" data-tm-result="' + escapeHtml(r.id) + '">'
          + '<div style="display:flex;align-items:center;gap:12px;">'
          + (r.image ? '<img src="' + escapeHtml(r.image) + '" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;">' : '')
          + '<strong>' + escapeHtml(r.name) + '</strong></div>';
        if (favIds.indexOf(r.id) !== -1) {
          html += '<span class="tm-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
        } else {
          html += '<button type="button" class="btn small tm-add-btn" data-team-id="' + escapeHtml(r.id)
            + '" data-team-name="' + escapeHtml(r.title) + '" data-team-image="' + escapeHtml(r.image || '') + '">Aggiungi alla lista</button>';
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
