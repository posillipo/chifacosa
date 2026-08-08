<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$activeTab = 'timeline';
$pageTitle = 'La mia Timeline';

const DASH_TIMELINE_PAGE_SIZE = 20;
$followedIds = getFollowedUserIds((int) $user['id']);
$feedUserIds = array_merge($followedIds, [(int) $user['id']]);
$feed = getTimelineFeedForUsers($feedUserIds, DASH_TIMELINE_PAGE_SIZE, 0);

$followedBands = [];
if ($followedIds) {
    $placeholders = implode(',', array_fill(0, count($followedIds), '?'));
    $stmt = getDB()->prepare("SELECT u.slug, p.display_name, p.avatar_path FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id IN ($placeholders) ORDER BY p.display_name ASC");
    $stmt->execute($followedIds);
    $followedBands = $stmt->fetchAll();
}

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>👥 Band Seguite (<?= count($followedBands) ?>)</summary>
    <div style="padding:0 16px 14px;">
      <input type="text" id="followed-search" placeholder="Cerca tra le band che segui..." style="margin-bottom:12px;">
      <div id="followed-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;"></div>
      <div id="followed-empty" style="display:none;color:var(--text-muted);font-size:13px;padding:8px 0;">Nessuna band trovata.</div>
      <div id="followed-pagination" style="display:flex;justify-content:center;align-items:center;gap:14px;margin-top:12px;">
        <button type="button" id="followed-prev" class="btn small" disabled>← Prec.</button>
        <span id="followed-page-indicator" style="font-size:13px;color:var(--text-muted);"></span>
        <button type="button" id="followed-next" class="btn small" disabled>Succ. →</button>
      </div>
    </div>
  </details>
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

  <?php if (!$feed): ?>
    <?php if (!$followedIds): ?>
      <div class="alert error">Non segui ancora nessun profilo — i contenuti che pubblichi tu compariranno comunque qui.</div>
    <?php else: ?>
      <div class="card">I profili che segui non hanno ancora pubblicato nulla.</div>
    <?php endif; ?>
  <?php else: ?>
    <div id="dash-timeline-feed">
      <?php foreach ($feed as $item): ?>
        <?= renderDashboardTimelineItem($item, $user['slug']) ?>
      <?php endforeach; ?>
    </div>
    <div id="dash-timeline-sentinel" style="height:1px;"></div>
    <p id="dash-timeline-loading" style="text-align:center;color:var(--text-muted);display:none;">Caricamento...</p>
    <p id="dash-timeline-end" style="text-align:center;color:var(--text-muted);display:none;">Hai visto tutto.</p>
    <script>
    (function () {
      var offset = <?= count($feed) ?>;
      var pageSize = <?= DASH_TIMELINE_PAGE_SIZE ?>;
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
        fetch('/dashboard_timeline_more.php?offset=' + offset)
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
<?php include __DIR__ . '/_dash_footer.php'; ?>
