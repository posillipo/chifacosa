<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/thesportsdb.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'che_amo';
$pageTitle = 'Partite che amo';

// A differenza degli altri moduli "che amo" con catalogo esterno, qui la ricerca è a due passi:
// TheSportsDB non permette di cercare una partita per nome, solo di sfogliare il calendario
// (prossime/ultime) di una squadra — quindi prima si cerca e si sceglie una squadra, poi si
// sceglie una partita dal suo calendario. Vedi thesportsdbGetTeamEvents() in thesportsdb.php.

$teamQuery = '';
$teamResults = [];
$eventResults = [];
$selectedTeamName = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_POST['ajax']);

    if ($action === 'search_teams') {
        $teamQuery = trim($_POST['query'] ?? '');
        if ($teamQuery !== '') {
            $teamResults = thesportsdbSearchTeam($teamQuery);
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $teamResults]);
            exit;
        }
    } elseif ($action === 'list_events') {
        $teamId = trim($_POST['team_id'] ?? '');
        $selectedTeamName = trim($_POST['team_name'] ?? '');
        $teamImage = trim($_POST['team_image'] ?? '');
        if ($teamId !== '') {
            $eventResults = thesportsdbGetTeamEvents($teamId);
            // Le partite normali spesso non hanno una foto propria (strThumb vuoto): lo stemma
            // della squadra appena scelta fa da ripiego, così l'immagine di condivisione social
            // non resta mai vuota.
            foreach ($eventResults as &$ev) {
                if (!$ev['image']) {
                    $ev['image'] = $teamImage ?: null;
                }
            }
            unset($ev);
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['results' => $eventResults, 'teamName' => $selectedTeamName]);
            exit;
        }
    } elseif ($action === 'add') {
        $eventId = trim($_POST['event_id'] ?? '');
        $matchTitle = trim($_POST['match_title'] ?? '');
        $matchImage = trim($_POST['match_image'] ?? '');
        $addedRow = null;
        if ($eventId !== '') {
            // is_public=0 di proposito: un elemento appena aggiunto parte "Solo io", la
            // pubblicazione nel Feed va confermata a mano dal pannello di pubblicazione.
            $stmt = getDB()->prepare('INSERT IGNORE INTO fan_favorite_matches
                (user_id, thesportsdb_event_id, match_title, match_image, is_public, sort_order)
                VALUES (?, ?, ?, ?, 0, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM fan_favorite_matches WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $eventId, $matchTitle, $matchImage ?: null, $profile['id']]);
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_matches WHERE user_id=? AND thesportsdb_event_id=?');
            $stmt->execute([$profile['id'], $eventId]);
            $addedRow = $stmt->fetch() ?: null;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $addedRow, 'item' => $addedRow]);
            exit;
        }
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_matches WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['image_path']);
            deleteCoverFile($row['image_thumb_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM fan_favorite_matches WHERE id=? AND user_id=?');
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

        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        if ($publishAt && strtotime($publishAt) <= time()) {
            $publishAt = null;
        }

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
            $stmt = getDB()->prepare('SELECT image_path, image_thumb_path FROM fan_favorite_matches WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            if ($old = $stmt->fetch()) {
                deleteCoverFile($old['image_path']);
                deleteCoverFile($old['image_thumb_path']);
            }
            $stmt = getDB()->prepare('UPDATE fan_favorite_matches SET note=?, is_public=?, in_feed=?, publish_at=?, image_path=?, image_thumb_path=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $isPublic, $inFeed, $publishAt, $imagePath, $imageThumbPath, $id, $profile['id']]);
        } else {
            $stmt = getDB()->prepare('UPDATE fan_favorite_matches SET note=?, is_public=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$note !== '' ? $note : null, $isPublic, $inFeed, $publishAt, $id, $profile['id']]);
        }

        if ($isAjax) {
            $stmt = getDB()->prepare('SELECT * FROM fan_favorite_matches WHERE id=? AND user_id=?');
            $stmt->execute([$id, $profile['id']]);
            $row = $stmt->fetch();
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => (bool) $row, 'item' => $row, 'error' => $error]);
            exit;
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM fan_favorite_matches WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$favorites = $stmt->fetchAll();
$favoriteIds = array_column($favorites, 'thesportsdb_event_id');

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      TheSportsDB non permette di cercare una partita per nome: prima cerca e scegli una squadra,
      poi scegli una partita dal suo calendario (prossime partite in programma e ultime giocate).
      Comparirà sulla tua pagina pubblica come vetrina di ciò che ami seguire, con risultato (se
      già giocata), campionato, stadio e data.
    </p>
    <p style="color:var(--text-muted)">
      Ogni partita aggiunta ha una sua pagina pubblica dedicata (raggiungibile cliccandoci sopra),
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

  <form method="post" class="card" id="mt-search-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="search_teams">
    <label>1. Cerca la squadra su TheSportsDB</label>
    <input type="text" name="query" id="mt-search-input" value="<?= e($teamQuery) ?>" placeholder="es. nome della squadra" autocomplete="off">
    <button type="submit" class="btn">Cerca</button>
    <p id="mt-search-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
  </form>

  <div id="mt-team-results">
    <?php if ($teamResults): ?>
      <div class="section-title">Squadre trovate (<?= count($teamResults) ?>)</div>
      <?php foreach ($teamResults as $t): ?>
        <div class="link-item" data-mt-team="<?= e($t['id']) ?>">
          <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($t['image']): ?><img src="<?= e($t['image']) ?>" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;"><?php endif; ?>
            <strong><?= e($t['name']) ?></strong>
          </div>
          <button type="button" class="btn small mt-select-team-btn"
            data-team-id="<?= e($t['id']) ?>" data-team-name="<?= e($t['title']) ?>" data-team-image="<?= e($t['image'] ?? '') ?>">
            Vedi partite
          </button>
        </div>
      <?php endforeach; ?>
    <?php elseif ($teamQuery !== ''): ?>
      <div class="card">Nessuna squadra trovata per questa ricerca.</div>
    <?php endif; ?>
  </div>

  <div id="mt-event-section" style="<?= $eventResults ? '' : 'display:none;' ?>">
    <div class="section-title">2. Scegli una partita di <span id="mt-event-team-name"><?= e($selectedTeamName) ?></span></div>
    <div id="mt-event-results">
      <?php foreach ($eventResults as $ev): ?>
        <div class="link-item" data-mt-event="<?= e($ev['id']) ?>">
          <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($ev['image']): ?><img src="<?= e($ev['image']) ?>" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;"><?php endif; ?>
            <span>
              <strong style="display:block;"><?= e($ev['title']) ?></strong>
              <small style="color:var(--text-muted);"><?= e($ev['date']) ?><?= $ev['time'] ? ' · ' . e($ev['time']) : '' ?></small>
            </span>
          </div>
          <?php if (in_array($ev['id'], $favoriteIds, true)): ?>
            <span class="mt-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>
          <?php else: ?>
            <button type="button" class="btn small mt-add-btn"
              data-event-id="<?= e($ev['id']) ?>" data-match-title="<?= e($ev['title']) ?>" data-match-image="<?= e($ev['image'] ?? '') ?>">
              Aggiungi alla lista
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$eventResults): ?><div class="card">Nessuna partita trovata (né in programma né già giocata di recente) per questa squadra.</div><?php endif; ?>
    </div>
  </div>

  <div class="section-title" id="mt-list-title">La tua lista (<?= count($favorites) ?>)</div>
  <div id="mt-list-empty" class="alert error" style="<?= $favorites ? 'display:none;' : '' ?>">Nessuna partita aggiunta ancora — cercala qui sopra.</div>
  <div id="mt-list">
    <?php foreach ($favorites as $f): ?>
      <?php
        $note = trim($f['note'] ?? '');
        $isPrivate = !$f['is_public'];
        $isScheduled = $f['publish_at'] && strtotime($f['publish_at']) > time();
      ?>
      <div class="link-item" data-mt-favorite="<?= (int)$f['id'] ?>" data-mt-event-id="<?= e($f['thesportsdb_event_id']) ?>"
           data-mt-note="<?= e($note) ?>" data-mt-has-image="<?= $f['image_path'] ? '1' : '0' ?>"
           style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <a href="/<?= e($profile['slug']) ?>/partite-che-amo/<?= (int)$f['id'] ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
            <?php if ($f['match_image']): ?>
              <img src="<?= e($f['match_image']) ?>" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;">
            <?php endif; ?>
            <strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($f['match_title']) ?></strong>
          </a>
          <button type="button" class="btn small danger mt-remove-btn" style="flex-shrink:0;">Rimuovi</button>
        </div>
        <div class="mt-pub-badges" style="display:flex;gap:6px;flex-wrap:wrap;<?= (!$isScheduled && !$isPrivate) ? 'display:none;' : '' ?>">
          <?php if ($isScheduled): ?><span class="mt-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il <?= e(formatLocalDateTime($f['publish_at'], $profile)) ?></span><?php endif; ?>
          <?php if ($isPrivate): ?><span class="mt-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span><?php endif; ?>
        </div>
        <div class="mt-pub-block">
          <?php if ($note !== ''): ?>
            <p class="mt-pub-text" style="margin:0;font-size:14px;"><?= nl2br(e($note)) ?></p>
          <?php else: ?>
            <p class="mt-pub-text" style="margin:0;font-size:14px;display:none;"></p>
          <?php endif; ?>
          <button type="button" class="btn small secondary mt-pub-toggle">✏️ Gestisci pubblicazione</button>
          <form class="mt-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">
            <label>Racconta perché ti piace</label>
            <textarea class="mt-pub-textarea" rows="3" placeholder="Racconta perché ti piace"><?= e($note) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">
              <button type="button" class="btn small secondary mt-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="mt-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="mt-ai-keywords" rows="2" placeholder="es. la partita che ricordo con più emozione"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small mt-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary mt-ai-cancel">Annulla</button>
              </div>
              <p class="mt-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>

            <label>Foto (opzionale)</label>
            <input type="file" class="mt-pub-image-input" accept="image/*">
            <input type="hidden" class="mt-pub-image-thumb-data">
            <?php if ($f['image_path']): ?><p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Hai già caricato una foto — seleziona un nuovo file per sostituirla.</p><?php endif; ?>

            <label>Privacy</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="mt-pub-visibility" name="visibility" value="public" <?= $isPrivate ? '' : 'checked' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" class="mt-pub-visibility" name="visibility" value="private" <?= $isPrivate ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>

            <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
              <input type="checkbox" class="mt-pub-in-feed" name="in_feed" value="1" <?= ($f['in_feed'] ?? 1) ? 'checked' : '' ?> style="width:auto;"> Includi nel Feed
            </label>
            <p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Se spuntato, compare anche nel flusso degli aggiornamenti (oltre che nella sua pagina), all'orario di pubblicazione.</p>

            <label>Programma la pubblicazione (opzionale)</label>
            <input type="datetime-local" class="mt-pub-publish-at" value="<?= $f['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($f['publish_at']))) : '' ?>">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>

            <label>Link personalizzato per il feed (opzionale)</label>
            <input type="url" class="mt-pub-custom-link" value="<?= e($profile['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
            <?php if (!empty($profile['custom_feed_guid_since'])): ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Attivo dal <?= e(formatLocalDateTime($profile['custom_feed_guid_since'], $profile)) ?>. Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php else: ?>
              <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Vale per tutti i contenuti del profilo, non solo per questo elemento.</p>
            <?php endif; ?>

            <p class="mt-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button type="button" class="btn small mt-pub-save">Salva</button>
              <button type="button" class="btn small secondary mt-pub-cancel">Annulla</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script>
  (function () {
    const csrfInput = document.querySelector('#mt-search-form input[name="csrf"]');
    const searchForm = document.getElementById('mt-search-form');
    const searchInput = document.getElementById('mt-search-input');
    const searchStatus = document.getElementById('mt-search-status');
    const teamResultsBox = document.getElementById('mt-team-results');
    const eventSection = document.getElementById('mt-event-section');
    const eventResultsBox = document.getElementById('mt-event-results');
    const eventTeamNameEl = document.getElementById('mt-event-team-name');
    const listBox = document.getElementById('mt-list');
    const listTitle = document.getElementById('mt-list-title');
    const listEmpty = document.getElementById('mt-list-empty');
    const profileSlug = <?= json_encode($profile['slug']) ?>;

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function post(params) {
      params.set('csrf', csrfInput.value);
      params.set('ajax', '1');
      return fetch('/dashboard_fan_matches.php', { method: 'POST', body: params }).then(r => r.json());
    }

    function postForm(formData) {
      formData.set('csrf', csrfInput.value);
      formData.set('ajax', '1');
      return fetch('/dashboard_fan_matches.php', { method: 'POST', body: formData }).then(r => r.json());
    }

    function updateListTitle() {
      const n = listBox.children.length;
      listTitle.textContent = 'La tua lista (' + n + ')';
      listEmpty.style.display = n === 0 ? 'block' : 'none';
    }

    // Passo 1: cerca squadre (live mentre scrivi, come negli altri moduli)
    function renderTeamResults(results) {
      if (!results.length) {
        teamResultsBox.innerHTML = '<div class="card">Nessuna squadra trovata per questa ricerca.</div>';
        return;
      }
      let html = '<div class="section-title">Squadre trovate (' + results.length + ')</div>';
      results.forEach(function (t) {
        html += '<div class="link-item" data-mt-team="' + escapeHtml(t.id) + '">'
          + '<div style="display:flex;align-items:center;gap:12px;">'
          + (t.image ? '<img src="' + escapeHtml(t.image) + '" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;">' : '')
          + '<strong>' + escapeHtml(t.name) + '</strong></div>'
          + '<button type="button" class="btn small mt-select-team-btn" data-team-id="' + escapeHtml(t.id)
          + '" data-team-name="' + escapeHtml(t.title) + '" data-team-image="' + escapeHtml(t.image || '') + '">Vedi partite</button></div>';
      });
      teamResultsBox.innerHTML = html;
    }

    function runTeamSearch(query) {
      if (query.trim() === '') { teamResultsBox.innerHTML = ''; searchStatus.textContent = ''; return; }
      searchStatus.textContent = 'Ricerca in corso...';
      const params = new URLSearchParams();
      params.set('action', 'search_teams');
      params.set('query', query);
      post(params).then(function (data) {
        searchStatus.textContent = '';
        renderTeamResults(data.results || []);
      }).catch(function () {
        searchStatus.textContent = 'Ricerca non riuscita, riprova.';
      });
    }

    let debounceTimer = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      const query = searchInput.value;
      debounceTimer = setTimeout(function () { runTeamSearch(query); }, 400);
    });
    searchForm.addEventListener('submit', function (e) {
      e.preventDefault();
      clearTimeout(debounceTimer);
      runTeamSearch(searchInput.value);
    });

    // Passo 2: scelta la squadra, carica il suo calendario (prossime + ultime partite)
    function renderEventResults(results, favIds) {
      if (!results.length) {
        eventResultsBox.innerHTML = '<div class="card">Nessuna partita trovata (né in programma né già giocata di recente) per questa squadra.</div>';
        return;
      }
      let html = '';
      results.forEach(function (ev) {
        html += '<div class="link-item" data-mt-event="' + escapeHtml(ev.id) + '">'
          + '<div style="display:flex;align-items:center;gap:12px;">'
          + (ev.image ? '<img src="' + escapeHtml(ev.image) + '" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;">' : '')
          + '<span><strong style="display:block;">' + escapeHtml(ev.title) + '</strong>'
          + '<small style="color:var(--text-muted);">' + escapeHtml(ev.date) + (ev.time ? ' · ' + escapeHtml(ev.time) : '') + '</small></span></div>';
        if (favIds.indexOf(ev.id) !== -1) {
          html += '<span class="mt-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
        } else {
          html += '<button type="button" class="btn small mt-add-btn" data-event-id="' + escapeHtml(ev.id)
            + '" data-match-title="' + escapeHtml(ev.title) + '" data-match-image="' + escapeHtml(ev.image || '') + '">Aggiungi alla lista</button>';
        }
        html += '</div>';
      });
      eventResultsBox.innerHTML = html;
    }

    const favoriteEventIds = <?= json_encode($favoriteIds) ?>;

    teamResultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.mt-select-team-btn');
      if (!btn) return;
      btn.disabled = true;
      const params = new URLSearchParams();
      params.set('action', 'list_events');
      params.set('team_id', btn.dataset.teamId);
      params.set('team_name', btn.dataset.teamName);
      params.set('team_image', btn.dataset.teamImage || '');
      post(params).then(function (data) {
        btn.disabled = false;
        eventTeamNameEl.textContent = data.teamName || btn.dataset.teamName;
        eventSection.style.display = 'block';
        renderEventResults(data.results || [], favoriteEventIds);
        eventSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
      const isPrivate = !item.is_public || item.is_public == 0;
      let html = '';
      if (isScheduled) html += '<span class="mt-badge-scheduled" style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">⏰ Programmato per il ' + escapeHtml(item.publish_at) + '</span>';
      if (isPrivate) html += '<span class="mt-badge-private" style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">🔒 Solo io (non nel Feed)</span>';
      return { html: html, visible: isScheduled || isPrivate };
    }

    function favoriteRowHtml(item) {
      const badges = renderBadges(item);
      const img = item.match_image ? '<img src="' + escapeHtml(item.match_image) + '" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;">' : '';
      const customLink = <?= json_encode($profile['custom_feed_guid'] ?? '') ?>;
      const customLinkSince = <?= json_encode($profile['custom_feed_guid_since'] ?? '') ?>;
      const sinceHint = customLinkSince
        ? 'Attivo dal ' + escapeHtml(customLinkSince) + '. Vale per tutti i contenuti del profilo, non solo per questo elemento.'
        : 'Vale per tutti i contenuti del profilo, non solo per questo elemento.';
      return '<div style="display:flex;align-items:center;gap:12px;">'
        + '<a href="/' + escapeHtml(profileSlug) + '/partite-che-amo/' + item.id + '" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">'
        + img + '<strong style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(item.match_title) + '</strong></a>'
        + '<button type="button" class="btn small danger mt-remove-btn" style="flex-shrink:0;">Rimuovi</button></div>'
        + '<div class="mt-pub-badges" style="display:' + (badges.visible ? 'flex' : 'none') + ';gap:6px;flex-wrap:wrap;">' + badges.html + '</div>'
        + '<div class="mt-pub-block">'
        + '<p class="mt-pub-text" style="margin:0;font-size:14px;display:none;"></p>'
        + '<button type="button" class="btn small secondary mt-pub-toggle">✏️ Gestisci pubblicazione</button>'
        + '<form class="mt-pub-editor" onsubmit="return false;" style="display:none;margin-top:8px;">'
        + '<label>Racconta perché ti piace</label>'
        + '<textarea class="mt-pub-textarea" rows="3" placeholder="Racconta perché ti piace"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 12px;">'
        + '<button type="button" class="btn small secondary mt-ai-toggle">✨ Genera con AI</button></div>'
        + '<div class="mt-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-4px 0 12px;">'
        + '<label>Qualche parola chiave o istruzione per l\'AI</label>'
        + '<textarea class="mt-ai-keywords" rows="2" placeholder="es. la partita che ricordo con più emozione"></textarea>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
        + '<button type="button" class="btn small mt-ai-generate">Genera testo</button>'
        + '<button type="button" class="btn small secondary mt-ai-cancel">Annulla</button></div>'
        + '<p class="mt-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p></div>'
        + '<label>Foto (opzionale)</label>'
        + '<input type="file" class="mt-pub-image-input" accept="image/*">'
        + '<input type="hidden" class="mt-pub-image-thumb-data">'
        + '<label>Privacy</label>'
        + '<div style="display:flex;gap:16px;margin-bottom:14px;">'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="mt-pub-visibility" name="visibility" value="public"' + (item.is_public ? ' checked' : '') + ' style="width:auto;"> Pubblico</label>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;"><input type="radio" class="mt-pub-visibility" name="visibility" value="private"' + (!item.is_public ? ' checked' : '') + ' style="width:auto;"> Solo io</label></div>'
        + '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;"><input type="checkbox" class="mt-pub-in-feed" name="in_feed" value="1"' + (item.in_feed == 1 ? ' checked' : '') + ' style="width:auto;"> Includi nel Feed</label>'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin:-8px 0 14px;">Se spuntato, compare anche nel flusso degli aggiornamenti (oltre che nella sua pagina), all\'orario di pubblicazione.</p>'
        + '<label>Programma la pubblicazione (opzionale)</label>'
        + '<input type="datetime-local" class="mt-pub-publish-at">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>'
        + '<label>Link personalizzato per il feed (opzionale)</label>'
        + '<input type="url" class="mt-pub-custom-link" value="' + escapeHtml(customLink) + '" placeholder="https://...">'
        + '<p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">' + sinceHint + '</p>'
        + '<p class="mt-pub-status" style="color:var(--text-muted);font-size:12.5px;"></p>'
        + '<div style="display:flex;gap:8px;margin-top:4px;">'
        + '<button type="button" class="btn small mt-pub-save">Salva</button>'
        + '<button type="button" class="btn small secondary mt-pub-cancel">Annulla</button></div></form></div>';
    }

    function addFavoriteRow(item) {
      const div = document.createElement('div');
      div.className = 'link-item';
      div.setAttribute('data-mt-favorite', item.id);
      div.setAttribute('data-mt-event-id', item.thesportsdb_event_id);
      div.setAttribute('data-mt-note', '');
      div.setAttribute('data-mt-has-image', '0');
      div.style.flexDirection = 'column';
      div.style.alignItems = 'stretch';
      div.style.gap = '8px';
      div.innerHTML = favoriteRowHtml(item);
      listBox.prepend(div);
      updateListTitle();
    }

    function markEventResultAsAdded(eventId) {
      const row = eventResultsBox.querySelector('[data-mt-event="' + CSS.escape(eventId) + '"]');
      if (!row) return;
      const btn = row.querySelector('.mt-add-btn');
      if (btn) btn.outerHTML = '<span class="mt-already" style="color:var(--text-muted);font-size:13px;">Già in lista</span>';
    }

    eventResultsBox.addEventListener('click', function (e) {
      const btn = e.target.closest('.mt-add-btn');
      if (!btn) return;
      btn.disabled = true;
      const eventId = btn.dataset.eventId;
      const params = new URLSearchParams();
      params.set('action', 'add');
      params.set('event_id', eventId);
      params.set('match_title', btn.dataset.matchTitle);
      params.set('match_image', btn.dataset.matchImage || '');
      post(params).then(function (data) {
        if (data.ok && data.item) {
          markEventResultAsAdded(eventId);
          favoriteEventIds.push(eventId);
          addFavoriteRow(data.item);
        } else {
          btn.disabled = false;
        }
      }).catch(function () { btn.disabled = false; });
    });

    // Rimuovi/pubblicazione (delegato: sia le voci già presenti al caricamento, sia quelle aggiunte dopo)
    listBox.addEventListener('click', function (e) {
      const removeBtn = e.target.closest('.mt-remove-btn');
      const pubToggleBtn = e.target.closest('.mt-pub-toggle');
      const pubCancelBtn = e.target.closest('.mt-pub-cancel');
      const pubSaveBtn = e.target.closest('.mt-pub-save');
      const aiToggleBtn = e.target.closest('.mt-ai-toggle');
      const aiCancelBtn = e.target.closest('.mt-ai-cancel');
      const aiGenerateBtn = e.target.closest('.mt-ai-generate');

      if (removeBtn) {
        if (!confirm('Rimuovere questa partita dalla tua lista?')) return;
        const row = removeBtn.closest('[data-mt-favorite]');
        const id = row.getAttribute('data-mt-favorite');
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
        const block = pubToggleBtn.closest('.mt-pub-block');
        block.querySelector('.mt-pub-editor').style.display = 'block';
        block.querySelector('.mt-pub-textarea').focus();
        return;
      }

      if (pubCancelBtn) {
        const block = pubCancelBtn.closest('.mt-pub-block');
        const row = pubCancelBtn.closest('[data-mt-favorite]');
        block.querySelector('.mt-pub-textarea').value = row.getAttribute('data-mt-note') || '';
        block.querySelector('.mt-pub-editor').style.display = 'none';
        return;
      }

      if (aiToggleBtn) {
        const panel = aiToggleBtn.closest('.mt-pub-editor').querySelector('.mt-ai-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'block') panel.querySelector('.mt-ai-keywords').focus();
        return;
      }

      if (aiCancelBtn) {
        const panel = aiCancelBtn.closest('.mt-ai-panel');
        panel.style.display = 'none';
        panel.querySelector('.mt-ai-status').textContent = '';
        return;
      }

      if (aiGenerateBtn) {
        const panel = aiGenerateBtn.closest('.mt-ai-panel');
        const editor = aiGenerateBtn.closest('.mt-pub-editor');
        const keywords = panel.querySelector('.mt-ai-keywords').value.trim();
        const statusEl = panel.querySelector('.mt-ai-status');
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
              editor.querySelector('.mt-pub-textarea').value = data.text;
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
        const editor = pubSaveBtn.closest('.mt-pub-editor');
        const block = pubSaveBtn.closest('.mt-pub-block');
        const row = pubSaveBtn.closest('[data-mt-favorite]');
        const id = row.getAttribute('data-mt-favorite');
        const note = editor.querySelector('.mt-pub-textarea').value;
        const visibility = editor.querySelector('.mt-pub-visibility:checked').value;
        const inFeed = editor.querySelector('.mt-pub-in-feed').checked;
        const publishAt = editor.querySelector('.mt-pub-publish-at').value;
        const customLink = editor.querySelector('.mt-pub-custom-link').value;
        const imageInput = editor.querySelector('.mt-pub-image-input');
        const statusEl = editor.querySelector('.mt-pub-status');
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
          formData.set('tz_offset_minutes', new Date().getTimezoneOffset());
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
            row.setAttribute('data-mt-note', note);
            row.setAttribute('data-mt-has-image', data.item.image_path ? '1' : '0');
            const textEl = block.querySelector('.mt-pub-text');
            const toggleEl = block.querySelector('.mt-pub-toggle');
            if (note.trim() !== '') {
              textEl.innerHTML = escapeHtml(note).replace(/\n/g, '<br>');
              textEl.style.display = 'block';
            } else {
              textEl.style.display = 'none';
            }
            toggleEl.textContent = '✏️ Gestisci pubblicazione';
            const badges = renderBadges(data.item);
            const badgesBox = row.querySelector('.mt-pub-badges');
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
  })();
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
