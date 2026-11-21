<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'timeline';
$pageTitle = 'Feed';
$pageHelpInline = true; // il "?" sta accanto al titolo Feed (vedi sotto)

const DASH_TIMELINE_PAGE_SIZE = 20;
$viewMode = $_GET['view'] ?? ($_COOKIE['dash_feed_view'] ?? 'list');
if (!in_array($viewMode, ['list', 'card'], true)) {
    $viewMode = 'list';
}
if (isset($_GET['view'])) {
    setcookie('dash_feed_view', $viewMode, time() + 86400 * 365, '/', '', false, true);
}

$followedIds = getFollowedUserIds((int) $profile['id']);
$feedUserIds = array_merge($followedIds, [(int) $profile['id']]);
$feedFilters = dashboardFeedFilters($_GET);
$filtersOn = dashboardFeedFiltersActive($feedFilters);
$filterQs = $filtersOn ? '&' . http_build_query(array_filter($feedFilters, fn($v) => $v !== '')) : '';
$feed = $filtersOn
    ? dashboardFeedFiltered((int) $profile['id'], $profile['slug'], $feedFilters, DASH_TIMELINE_PAGE_SIZE, 0)
    : dashboardFeedEnrich(getTimelineFeedForUsers($feedUserIds, DASH_TIMELINE_PAGE_SIZE, 0, (int) $profile['id']), (int) $profile['id'], $profile['slug']);
require_once __DIR__ . '/../../src/metricool_queue.php';
$metricoolActive = metricoolIsEnabled((int) $profile['id']);

$followedBands = [];
if ($followedIds) {
    $placeholders = implode(',', array_fill(0, count($followedIds), '?'));
    $stmt = getDB()->prepare("SELECT u.slug, p.display_name, p.avatar_path FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id IN ($placeholders) ORDER BY p.display_name ASC");
    $stmt->execute($followedIds);
    $followedBands = $stmt->fetchAll();
}

include __DIR__ . '/../_dash_header.php';
?>
  <style>
  .dtc-card{display:block;text-decoration:none;color:inherit;background:var(--card-bg);border:1px solid #e2e2e7;border-radius:12px;margin-bottom:14px;overflow:hidden;transition:box-shadow .15s}
  .dtc-card:hover{box-shadow:0 2px 12px rgba(0,0,0,0.08)}
  .dtc-mine{border-left:3px solid var(--accent)}
  .dtc-header{display:flex;gap:10px;align-items:center;padding:12px 14px 8px}
  .dtc-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0}
  .dtc-author{flex:1;min-width:0}
  .dtc-name{font-weight:600;font-size:13.5px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .dtc-you{color:var(--accent);font-weight:700}
  .dtc-date{font-size:12px;color:var(--text-muted)}
  .dtc-badge{font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0}
  .dtc-cover{width:100%;max-height:420px;object-fit:cover;display:block}
  .dtc-body{padding:10px 14px 14px}
  .dtc-body strong{font-size:15px;line-height:1.35;display:block;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical}
  .dtc-evento-info{font-size:12.5px;color:var(--text-muted);margin-top:4px}
  .dtc-redirect-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;background:var(--card-bg);border:1px solid #e2e2e7;font-size:15px;text-decoration:none;flex-shrink:0;z-index:1;transition:background .15s}
  .dtc-redirect-btn:hover{background:#f0f0f4}
  /* Tabellina delle proprietà a destra di ogni riga; su schermi stretti va sotto il titolo. */
  .dtl-row{flex-wrap:wrap;row-gap:6px}
  .dtl-row > a.link-item{min-width:280px !important}
  @media(max-width:820px){.dtl-row > .dtp{width:100% !important;margin:2px 0 6px !important}}
  </style>

  <?php $activeCount = count(array_filter($feedFilters, fn($v) => $v !== '')); $panelOpen = !empty($_GET['open']); ?>
  <div class="dtf-head">
    <div class="section-title" style="margin:0;flex:1;min-width:0;display:flex;align-items:center;gap:10px;">Feed <?= renderPageHelpButton('dashboard_timeline', 'Feed') ?></div>
    <button type="button" id="dtf-toggle" class="dtf-btn<?= $activeCount ? ' on' : '' ?>" aria-expanded="<?= $panelOpen ? 'true' : 'false' ?>" aria-controls="dtf-panel">
      <i class="fa-solid fa-sliders"></i> Filtri<?= $activeCount ? ' <span class="dtf-count">' . $activeCount . '</span>' : '' ?>
    </button>
    <div class="view-toggle">
      <a href="?view=list<?= e($filterQs) ?>" class="<?= $viewMode === 'list' ? 'active' : '' ?>" title="Lista">☰<span class="dtf-hide-sm"> Lista</span></a>
      <a href="?view=card<?= e($filterQs) ?>" class="<?= $viewMode === 'card' ? 'active' : '' ?>" title="Card">🖼️<span class="dtf-hide-sm"> Card</span></a>
    </div>
  </div>
  <style>
  .view-toggle{display:inline-flex;gap:0;border:1px solid #d5d5da;border-radius:6px;overflow:hidden}
  .view-toggle a{padding:6px 12px;font-size:13px;text-decoration:none;color:var(--text-muted,#6c757d);border-right:1px solid #d5d5da}
  .view-toggle a:last-child{border-right:none}
  .view-toggle a.active{background:var(--accent);color:#fff}
  .dtf-head{display:flex;align-items:center;gap:8px;margin-bottom:10px}
  .dtf-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;font-size:13px;border:1px solid #d5d5da;border-radius:6px;background:var(--card-bg,#fff);color:var(--text-muted,#6c757d);cursor:pointer;white-space:nowrap}
  .dtf-btn.on,.dtf-btn[aria-expanded="true"]{border-color:var(--accent);color:var(--accent)}
  .dtf-count{background:var(--accent);color:#fff;border-radius:999px;font-size:11px;font-weight:700;padding:0 6px;line-height:17px}
  .dtf-panel{border:1px solid #e2e2e7;border-radius:12px;background:var(--card-bg,#fff);padding:12px 14px;margin-bottom:12px}
  .dtf-panel[hidden]{display:none}
  .dtf-sub{margin-top:12px;border-top:1px dashed #e2e2e7;padding-top:10px}
  .dtf-sub > summary{cursor:pointer;font-size:13px;font-weight:600;list-style:none}
  .dtf-sub > summary::-webkit-details-marker{display:none}
  @media(max-width:560px){.dtf-hide-sm{display:none}.view-toggle a{padding:6px 10px}}
  </style>

  <div id="dtf-panel" class="dtf-panel"<?= $panelOpen ? '' : ' hidden' ?>>
    <?= dashboardFeedFilterBarHtml($feedFilters, dashboardFeedAuthorOptions((int) $profile['id']), $metricoolActive, $viewMode) ?>

    <details class="dtf-sub">
      <summary>👥 Band seguite (<?= count($followedBands) ?>)</summary>
      <div style="padding:10px 0 0;">
        <input type="text" id="followed-search" placeholder="Cerca tra le band che segui..." style="margin-bottom:12px;">
        <div id="followed-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;"></div>
        <div id="followed-empty" style="display:none;color:var(--text-muted);font-size:13px;padding:8px 0;">Nessuna band trovata.</div>
        <div id="followed-pagination" style="display:flex;justify-content:center;align-items:center;gap:14px;margin-top:12px;">
          <button type="button" id="followed-prev" class="btn small" disabled>← Prec.</button>
          <span id="followed-page-indicator" style="font-size:13px;color:var(--text-muted);"></span>
          <button type="button" id="followed-next" class="btn small" disabled>Succ. →</button>
        </div>
      </div>
    </details>
  </div>
  <script>
  (function () {
    var btn = document.getElementById('dtf-toggle'), panel = document.getElementById('dtf-panel');
    btn.addEventListener('click', function () {
      var open = panel.hasAttribute('hidden');
      if (open) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  })();
  </script>
  <script>
  (function () {
    var allBands = <?= json_encode(array_map(fn($b) => ['slug' => $b['slug'], 'name' => $b['display_name'], 'avatar' => $b['avatar_path']], $followedBands)) ?>;
    var pageSize = 9;
    var page = 0;
    var filtered = allBands;

    var grid = document.getElementById('followed-grid');
    var emptyMsg = document.getElementById('followed-empty');
    var prevBtn = document.getElementById('followed-prev');
    var nextBtn = document.getElementById('followed-next');
    var pageIndicator = document.getElementById('followed-page-indicator');
    var paginationBox = document.getElementById('followed-pagination');
    var searchInput = document.getElementById('followed-search');

    function render() {
      grid.innerHTML = '';
      var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
      if (page >= totalPages) page = totalPages - 1;
      if (page < 0) page = 0;
      var slice = filtered.slice(page * pageSize, page * pageSize + pageSize);
      emptyMsg.style.display = filtered.length === 0 ? 'block' : 'none';
      slice.forEach(function (b) {
        var a = document.createElement('a');
        a.href = '/' + b.slug;
        a.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:6px;text-decoration:none;color:inherit;background:var(--card-bg);border-radius:10px;padding:10px;text-align:center;';
        var imgHtml = b.avatar ? '<img src="/' + b.avatar + '" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">' : '<div style="width:48px;height:48px;border-radius:50%;background:rgba(0,0,0,0.1);"></div>';
        a.innerHTML = imgHtml + '<span style="font-size:12.5px;font-weight:600;">' + b.name.replace(/</g,'&lt;') + '</span>';
        grid.appendChild(a);
      });
      pageIndicator.textContent = filtered.length ? ('Pagina ' + (page + 1) + ' di ' + totalPages) : '';
      prevBtn.disabled = page <= 0;
      nextBtn.disabled = page >= totalPages - 1;
      paginationBox.style.display = filtered.length > pageSize ? 'flex' : 'none';
    }

    searchInput.addEventListener('input', function () {
      var q = searchInput.value.trim().toLowerCase();
      filtered = q ? allBands.filter(function (b) { return b.name.toLowerCase().indexOf(q) !== -1; }) : allBands;
      page = 0;
      render();
    });
    prevBtn.addEventListener('click', function () { page--; render(); });
    nextBtn.addEventListener('click', function () { page++; render(); });

    render();
  })();
  </script>

  <?php // Anteprima dei contenuti degli altri profili (al posto della pagina di modifica, che non esiste per loro). ?>
  <dialog class="page-help-dialog" id="fp-dialog" aria-labelledby="fp-title">
    <div class="page-help-inner">
      <div id="fp-meta" style="display:flex;align-items:center;gap:10px;margin-bottom:10px;"></div>
      <img id="fp-cover" alt="" style="display:none;width:100%;max-height:240px;object-fit:cover;border-radius:10px;margin-bottom:10px;">
      <h2 id="fp-title"></h2>
      <p id="fp-text" style="display:none;"></p>
      <div class="page-help-actions">
        <a id="fp-open" class="btn" href="#" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Vai alla pagina pubblica</a>
        <button type="button" class="btn secondary" id="fp-close">Chiudi</button>
      </div>
    </div>
  </dialog>
  <script>
  (function () {
    var dlg = document.getElementById('fp-dialog');
    if (!dlg || !dlg.showModal) return;
    var meta = document.getElementById('fp-meta'), cover = document.getElementById('fp-cover'), title = document.getElementById('fp-title'),
        text = document.getElementById('fp-text'), open = document.getElementById('fp-open'), seq = 0;
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest('a[data-fp]');
      if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1) return;
      ev.preventDefault();
      var d; try { d = JSON.parse(a.getAttribute('data-fp')); } catch (e) { window.location = a.href; return; }
      meta.innerHTML = (d.avatar ? '<img src="' + esc(d.avatar) + '" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">' : '')
        + '<div><b>' + esc(d.autore) + '</b><br><small style="color:var(--text-muted);">' + esc(d.label) + (d.data ? ' · ' + esc(d.data) : '') + '</small></div>';
      cover.style.display = d.cover ? 'block' : 'none'; if (d.cover) cover.src = d.cover;
      title.textContent = d.titolo || '';
      text.style.display = 'none'; text.textContent = '';
      open.href = d.url;
      dlg.showModal();
      if ((d.tipo === 'blog' || d.tipo === 'pensiero') && d.id) {
        var my = ++seq;
        fetch('/dashboard_feed_preview.php?t=' + encodeURIComponent(d.tipo) + '&id=' + d.id, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (x) { if (my === seq && x.text) { text.textContent = x.text; text.style.display = 'block'; } })
          .catch(function () {});
      }
    });
    document.getElementById('fp-close').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
  })();
  </script>

  <?php if (!$feed): ?>
    <?php if ($filtersOn): ?>
      <div class="card">Nessun elemento corrisponde ai filtri scelti.</div>
    <?php elseif (!$followedIds): ?>
      <div class="alert error">Non segui ancora nessun profilo — i contenuti che pubblichi tu compariranno comunque qui.</div>
    <?php else: ?>
      <div class="card">I profili che segui non hanno ancora pubblicato nulla.</div>
    <?php endif; ?>
  <?php else: ?>
    <div id="dash-timeline-feed">
      <?php foreach ($feed as $item): ?>
        <?= $viewMode === 'card' ? renderDashboardTimelineCard($item, $profile['slug'], $metricoolActive) : renderDashboardTimelineItem($item, $profile['slug'], $metricoolActive) ?>
      <?php endforeach; ?>
    </div>
    <div id="dash-timeline-sentinel" style="height:1px;"></div>
    <p id="dash-timeline-loading" style="text-align:center;color:var(--text-muted);display:none;">Caricamento...</p>
    <p id="dash-timeline-end" style="text-align:center;color:var(--text-muted);display:none;">Hai visto tutto.</p>
    <script>
    (function () {
      var offset = <?= count($feed) ?>;
      var pageSize = <?= DASH_TIMELINE_PAGE_SIZE ?>;
      var viewMode = <?= json_encode($viewMode) ?>;
      var loading = false;
      var finished = <?= count($feed) < DASH_TIMELINE_PAGE_SIZE ? 'true' : 'false' ?>;
      var feedEl = document.getElementById('dash-timeline-feed');
      var loadingEl = document.getElementById('dash-timeline-loading');
      var endEl = document.getElementById('dash-timeline-end');
      var sentinel = document.getElementById('dash-timeline-sentinel');

      function loadMore() {
        if (loading || finished) return;
        loading = true;
        loadingEl.style.display = 'block';
        fetch('/dashboard_timeline_more.php?offset=' + offset + '&view=' + viewMode + <?= json_encode($filterQs) ?>)
          .then(function (r) { return r.json(); })
          .then(function (data) {
            loadingEl.style.display = 'none';
            if (data.html) feedEl.insertAdjacentHTML('beforeend', data.html);
            offset += data.count;
            if (data.count < pageSize) { finished = true; endEl.style.display = 'block'; }
            loading = false;
          })
          .catch(function () { loading = false; loadingEl.style.display = 'none'; });
      }

      if (!finished && 'IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
          if (entries[0].isIntersecting) loadMore();
        }).observe(sentinel);
      } else if (finished) {
        endEl.style.display = 'block';
      }
    })();
    </script>
  <?php endif; ?>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
