<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'social_queue';
$pageTitle = 'Coda Social';

require_once __DIR__ . '/../src/metricool_queue.php';
$metricoolActive = metricoolIsEnabled((int) $profile['id']);

$statusFilter = $_GET['status'] ?? 'active';
$validStatuses = ['active', 'pending', 'suspended', 'scheduled', 'done', 'skipped', 'failed', 'all'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'active';

// Filtro per tipo di contenuto (solo tipi realmente presenti in coda per questo profilo).
$typeOptions = [];
try {
    $ts = getDB()->prepare('SELECT DISTINCT content_type FROM social_queue WHERE user_id = ? ORDER BY content_type');
    $ts->execute([$profile['id']]);
    $typeOptions = $ts->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
}
$typeFilter = (string) ($_GET['tipo'] ?? '');
if (!in_array($typeFilter, $typeOptions, true)) $typeFilter = '';
$panelOpen = !empty($_GET['open']);
$filtersQs = '&status=' . urlencode($statusFilter) . ($typeFilter !== '' ? '&tipo=' . urlencode($typeFilter) : '');

$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = 'WHERE q.user_id = ?';
$params = [$profile['id']];
if ($statusFilter === 'active') {
    $where .= ' AND q.status IN ("pending","processing")';
} elseif ($statusFilter === 'suspended') {
    // Sospeso = flag suspended su un elemento ancora da lavorare.
    $where .= ' AND q.suspended = 1 AND q.status IN ("pending","processing")';
} elseif ($statusFilter === 'pending') {
    $where .= ' AND q.status = "pending" AND q.suspended = 0';
} elseif ($statusFilter !== 'all') {
    $where .= ' AND q.status = ?';
    $params[] = $statusFilter;
}

if ($typeFilter !== '') {
    $where .= ' AND q.content_type = ?';
    $params[] = $typeFilter;
}

$total = getDB()->prepare("SELECT COUNT(*) FROM social_queue q $where");
$total->execute($params);
$totalCount = (int) $total->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));

$stmt = getDB()->prepare(
    "SELECT * FROM social_queue q $where ORDER BY q.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Autore di ogni elemento: la firma sta sul contenuto di origine (colonna created_by), la si legge
// qui in blocco per tipo. Offerte e tipi sconosciuti non hanno firma.
$authorTables = ['blog' => 'blog_posts', 'timeline' => 'timeline_posts', 'album' => 'photo_albums', 'service' => 'services', 'event' => 'events', 'video' => 'videos'];
$authorsByItem = [];
$idsByType = [];
foreach ($items as $qi) {
    if (isset($authorTables[$qi['content_type']]) && (int) $qi['content_id'] > 0) {
        $idsByType[$qi['content_type']][] = (int) $qi['content_id'];
    }
}
foreach ($idsByType as $type => $ids) {
    try {
        $ids = array_values(array_unique($ids));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $as = getDB()->prepare('SELECT id, created_by FROM `' . $authorTables[$type] . "` WHERE user_id = ? AND id IN ($in)");
        $as->execute(array_merge([$profile['id']], $ids));
        foreach ($as->fetchAll() as $r) {
            $authorsByItem[$type][(int) $r['id']] = $r['created_by'];
        }
    } catch (Throwable $e) {
        // Colonna non ancora migrata: la pagina funziona lo stesso, senza autore.
    }
}

include __DIR__ . '/_dash_header.php';
?>
<style>
.sq-list { display:flex; flex-direction:column; gap:12px; }
.sq-item { background:var(--card-bg,#fff); border:1px solid var(--border-color,#e3e3ea); border-radius:14px; padding:14px; display:grid; grid-template-columns:64px minmax(0,1fr); gap:14px; }
.sq-thumb { width:64px; height:64px; border-radius:10px; object-fit:cover; display:block; background:var(--bg-alt,#f0f0f0); }
.sq-prev { all:unset; cursor:zoom-in; position:relative; display:block; border-radius:10px; }
.sq-prev:focus-visible { outline:2px solid var(--accent,#6c5ce7); outline-offset:2px; }
.sq-prev .sq-play { position:absolute; right:4px; bottom:4px; background:rgba(0,0,0,.65); color:#fff; border-radius:999px; font-size:11px; padding:1px 6px; }
.sq-media-chips { display:flex; gap:4px; margin-top:6px; flex-wrap:wrap; max-width:64px; }
.sq-dlg { border:none; border-radius:16px; padding:0; width:min(760px,96vw); max-height:92vh; box-shadow:0 12px 50px rgba(0,0,0,.35); }
.sq-dlg::backdrop { background:rgba(0,0,0,.6); }
.sq-dlg-in { padding:18px; max-height:92vh; overflow:auto; }
.sq-dlg-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:10px; }
.sq-dlg-head strong { font-size:16px; line-height:1.35; overflow-wrap:anywhere; }
.sq-dlg-close { background:none; border:none; font-size:24px; cursor:pointer; color:var(--text-muted); line-height:1; }
.sq-dlg-media { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:12px; }
.sq-dlg-media figure { margin:0; flex:1 1 280px; min-width:0; }
.sq-dlg-media figcaption { font-size:12px; color:var(--text-muted); margin-bottom:4px; font-weight:700; }
.sq-dlg-media img, .sq-dlg-media video { width:100%; max-height:60vh; object-fit:contain; background:#111; border-radius:10px; display:block; }
.sq-dlg-media figure.story { flex:0 1 240px; }
.sq-dlg-text { white-space:pre-wrap; font-size:14px; line-height:1.5; margin:0 0 10px; overflow-wrap:anywhere; }
.sq-dlg-meta { display:flex; flex-wrap:wrap; gap:6px 14px; font-size:12.5px; color:var(--text-muted); }
.sq-thumb-empty { width:64px; height:64px; border-radius:10px; background:var(--bg-alt,#f0f0f0); display:flex; align-items:center; justify-content:center; font-size:22px; }
.sq-main { min-width:0; }
.sq-type { font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.03em; }
.sq-title { font-weight:700; font-size:15px; color:inherit; text-decoration:none; display:block; line-height:1.35; margin:2px 0; overflow-wrap:anywhere; }
a.sq-title:hover { text-decoration:underline; }
.sq-excerpt { color:var(--text-muted); font-size:13px; margin:2px 0 6px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.sq-row { display:flex; flex-wrap:wrap; gap:6px 8px; align-items:center; margin-top:6px; }
.sq-label { color:var(--text-muted); font-size:12px; }
.sq-meta { color:var(--text-muted); font-size:12px; }
.sq-pill { display:inline-block; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; color:#fff; white-space:nowrap; }
.sq-chip { display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:6px; border:1px solid var(--border-color,#d0d0d0); color:var(--text-muted); background:var(--bg-alt,#fafafa); white-space:nowrap; }
.sq-chip.ch { color:var(--accent,#6c5ce7); border-color:var(--accent,#6c5ce7); background:transparent; }
.sq-side { grid-column:1 / -1; border-top:1px dashed var(--border-color,#e0e0e0); padding-top:10px; display:flex; flex-wrap:wrap; gap:10px 16px; align-items:center; justify-content:space-between; }
.sq-side-info { display:flex; flex-wrap:wrap; gap:6px 16px; align-items:center; min-width:0; }
.sq-when { font-weight:700; font-size:13.5px; }
.sq-actions { display:flex; gap:6px; align-items:center; margin-left:auto; }
.btn-xs { font-size:12px; padding:4px 10px; border-radius:6px; cursor:pointer; border:1px solid #ccc; background:#fff; white-space:nowrap; }
.btn-xs.danger { color:#e74c3c; border-color:#e74c3c; }
.btn-xs.primary { color:#fff; background:var(--accent,#6c5ce7); border-color:var(--accent,#6c5ce7); }
.sq-scheduled-input { font-size:12.5px; padding:3px 6px; border:1px solid #ccc; border-radius:6px; max-width:100%; }
/* Le etichette autore non vanno a capo a metà: restano intere e, se serve, si accorciano. */
.sq-item .sq-main span[title^="Autore"] { white-space:nowrap; max-width:100%; overflow:hidden; text-overflow:ellipsis; display:inline-block; vertical-align:middle; }
</style>

<?php if (!$metricoolActive): ?>
  <div class="alert error">Il servizio Social Automatico non è ancora configurato per questo profilo. <a href="/dashboard_metricool.php">Configura</a></div>
<?php endif; ?>

<?php
$statusLabels = ['active' => '⚡ Attivi', 'pending' => '⏳ In attesa', 'suspended' => '⏸ Sospesi', 'scheduled' => '🗓 Programmati', 'done' => '✅ Completati', 'skipped' => '⏭ Saltati', 'failed' => '❌ Errori', 'all' => 'Tutti'];
$typeLabelsFilter = ['blog' => 'Blog', 'timeline' => 'Timeline', 'album' => 'Album', 'service' => 'Servizi', 'event' => 'Eventi', 'video' => 'Video', 'product' => 'Prodotti', 'offer' => 'Offerte'];
$activeCount = ($statusFilter !== 'active' ? 1 : 0) + ($typeFilter !== '' ? 1 : 0);
?>
<style>
.sqf-head { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
.sqf-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; font-size:13px; border:1px solid #d5d5da; border-radius:6px; background:var(--card-bg,#fff); color:var(--text-muted,#6c757d); cursor:pointer; white-space:nowrap; }
.sqf-btn.on, .sqf-btn[aria-expanded="true"] { border-color:var(--accent); color:var(--accent); }
.sqf-count { background:var(--accent); color:#fff; border-radius:999px; font-size:11px; font-weight:700; padding:0 6px; line-height:17px; }
.sqf-panel { border:1px solid #e2e2e7; border-radius:12px; background:var(--card-bg,#fff); padding:12px 14px; margin:8px 0 12px; }
.sqf-panel[hidden] { display:none; }
.sqf-panel form { display:flex; flex-wrap:wrap; gap:10px 12px; align-items:flex-end; }
.sqf-panel label { display:flex; flex-direction:column; gap:2px; font-size:11.5px; color:var(--text-muted); font-weight:600; min-width:140px; flex:1 1 140px; }
.sqf-panel select { margin:0; padding:6px 8px; font-size:13px; }
.sqf-sum { font-size:12.5px; color:var(--text-muted); margin:0 0 12px; }
@media(max-width:560px){ .sqf-hide-sm { display:none; } }
</style>
<div class="sqf-head">
  <div class="section-title" style="margin:0;flex:1;min-width:0;">📤 Coda Social</div>
  <button type="button" id="sqf-toggle" class="sqf-btn<?= $activeCount ? ' on' : '' ?>" aria-expanded="<?= $panelOpen ? 'true' : 'false' ?>" aria-controls="sqf-panel">
    <i class="fa-solid fa-sliders"></i> Filtri<?= $activeCount ? ' <span class="sqf-count">' . $activeCount . '</span>' : '' ?>
  </button>
  <a href="/dashboard_timeline.php" class="sqf-btn" title="Torna al Feed" style="text-decoration:none;">←<span class="sqf-hide-sm"> Feed</span></a>
</div>
<p class="sqf-sum"><?= e($statusLabels[$statusFilter]) ?><?= $typeFilter !== '' ? ' · ' . e($typeLabelsFilter[$typeFilter] ?? $typeFilter) : '' ?> — <?= (int) $totalCount ?> element<?= $totalCount === 1 ? 'o' : 'i' ?></p>

<div id="sqf-panel" class="sqf-panel"<?= $panelOpen ? '' : ' hidden' ?>>
  <form method="get">
    <input type="hidden" name="open" value="1">
    <label>Stato
      <select name="status" onchange="this.form.submit()">
        <?php foreach ($statusLabels as $sv => $sl): ?><option value="<?= e($sv) ?>"<?= $statusFilter === $sv ? ' selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Tipo
      <select name="tipo" onchange="this.form.submit()">
        <option value="">Tutti</option>
        <?php foreach ($typeOptions as $tv): ?><option value="<?= e($tv) ?>"<?= $typeFilter === $tv ? ' selected' : '' ?>><?= e($typeLabelsFilter[$tv] ?? ucfirst($tv)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php if ($activeCount): ?><a href="?open=1" class="btn small secondary" style="align-self:flex-end;">Azzera filtri</a><?php endif; ?>
  </form>
</div>
<script>
(function () {
  var btn = document.getElementById('sqf-toggle'), panel = document.getElementById('sqf-panel');
  btn.addEventListener('click', function () {
    var open = panel.hasAttribute('hidden');
    if (open) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();
</script>

<?php if (!$items): ?>
  <div class="card" style="color:var(--text-muted);text-align:center;">Nessun elemento in questa visualizzazione.</div>
<?php else: ?>
  <?php
  $statusColors = ['pending' => '#e67e22', 'processing' => '#2980b9', 'done' => '#27ae60', 'failed' => '#e74c3c', 'suspended' => '#95a5a6', 'scheduled' => '#8e44ad', 'skipped' => '#7f8c8d'];
  $statusNames  = ['pending' => 'In attesa', 'processing' => 'In elaborazione', 'done' => 'Completato', 'failed' => 'Errore', 'suspended' => 'Sospeso', 'scheduled' => 'Programmato', 'skipped' => 'Saltato'];
  $typeNames = ['blog' => ['📝', 'Blog'], 'timeline' => ['💬', 'Timeline'], 'album' => ['🖼', 'Album'], 'service' => ['🛠', 'Servizio'], 'event' => ['📅', 'Evento'], 'video' => ['🎬', 'Video'], 'product' => ['🛍', 'Prodotto'], 'offer' => ['🏷', 'Offerta']];
  $scopeNames = ['announcement' => 'Annuncio', 'reminder' => 'Promemoria', 'result' => 'Risultato', 'new' => 'Novità', 'promo' => 'Promo', 'release' => 'Uscita', 'reprise' => 'Ripresa'];
  $formatNames = ['feed' => 'Feed', 'story' => 'Story', 'reel' => 'Reel'];
  ?>
  <div class="sq-list" id="sq-tbody">
    <?php foreach ($items as $qi): ?>
      <?php
      // Stato mostrato: "suspended" se l'elemento è ancora da lavorare ma sospeso.
      $isSuspended = !empty($qi['suspended']) && in_array($qi['status'], ['pending', 'processing'], true);
      $shownStatus = $isSuspended ? 'suspended' : $qi['status'];
      $color = $statusColors[$shownStatus] ?? '#666';
      $label = mb_substr($qi['title'] ?: ($qi['body'] ?? ''), 0, 120) ?: '—';
      $bodyText = str_replace('**', '', trim(preg_replace('/\s+/', ' ', strip_tags((string) ($qi['body'] ?? '')))));
      $isEditable = in_array($qi['status'], ['pending', 'processing'], true);
      [$typeIcon, $typeLabel] = $typeNames[$qi['content_type']] ?? ['📄', ucfirst((string) $qi['content_type'])];
      $chs = [];
      if (!empty($qi['channels'])) {
          $dec = json_decode((string) $qi['channels'], true);
          if (is_array($dec)) $chs = $dec;
      }
      $img = $qi['image_url'] ?? '';
      $au = isset($authorTables[$qi['content_type']]) ? ($authorsByItem[$qi['content_type']][(int) $qi['content_id']] ?? null) : null;
      ?>
      <div class="sq-item" id="sq-row-<?= $qi['id'] ?>">
        <div>
          <?php
          $okUrl = fn($u) => is_string($u) && $u !== '' && (preg_match('#^https?://#i', $u) || ($u[0] === '/' && !str_starts_with($u, '//'))) ? $u : '';
          $storyImg = $okUrl($qi['image_story_url'] ?? '');
          $videoUrl = $okUrl($qi['media_url'] ?? '');
          $mainImg = $okUrl($img);
          $prev = [
              'title' => $label,
              'text' => str_replace('**', '', trim(strip_tags((string) ($qi['body'] ?? '')))),
              'image' => $mainImg, 'story' => $storyImg, 'video' => $videoUrl,
              'meta' => array_values(array_filter([
                  $typeLabel . ' #' . (int) $qi['content_id'],
                  $chs ? 'Canali: ' . implode(', ', $chs) : '',
                  !empty($qi['format']) ? 'Formato: ' . ($formatNames[$qi['format']] ?? $qi['format']) : '',
                  $qi['scheduled_for'] ? 'Uscita: ' . date('d/m/Y H:i', strtotime($qi['scheduled_for'])) : 'Uscita: appena possibile',
                  'Stato: ' . ($statusNames[$shownStatus] ?? $shownStatus),
              ])),
          ];
          $hasPrev = $mainImg || $storyImg || $videoUrl || $prev['text'] !== '';
          ?>
          <?php if ($hasPrev): ?><button type="button" class="sq-prev" data-prev="<?= e(json_encode($prev, JSON_UNESCAPED_UNICODE)) ?>" title="Anteprima di ciò che è in programma"><?php endif; ?>
          <?php if ($mainImg): ?><img class="sq-thumb" src="<?= e($mainImg) ?>" alt="" loading="lazy">
          <?php elseif ($videoUrl): ?><div class="sq-thumb-empty">🎬</div>
          <?php else: ?><div class="sq-thumb-empty"><?= $typeIcon ?></div><?php endif; ?>
          <?php if ($videoUrl): ?><span class="sq-play">▶ Video</span><?php endif; ?>
          <?php if ($hasPrev): ?></button><?php endif; ?>
          <?php if ($storyImg || ($videoUrl && $mainImg)): ?>
            <div class="sq-media-chips">
              <?php if ($storyImg): ?><span class="sq-chip">Story</span><?php endif; ?>
              <?php if ($videoUrl && $mainImg): ?><span class="sq-chip">Video</span><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="sq-main">
          <div class="sq-type"><?= $typeIcon ?> <?= e($typeLabel) ?> <span style="font-weight:400;text-transform:none;">· #<?= (int) $qi['content_id'] ?></span></div>
          <?php if ($qi['public_url']): ?><a class="sq-title" href="<?= e($qi['public_url']) ?>" target="_blank"><?= e($label) ?></a>
          <?php else: ?><span class="sq-title"><?= e($label) ?></span><?php endif; ?>
          <?php if ($bodyText !== '' && $bodyText !== $label): ?><div class="sq-excerpt"><?= e(mb_substr($bodyText, 0, 220)) ?></div><?php endif; ?>
          <?php if ($chs || !empty($qi['format']) || !empty($qi['scope'])): ?>
            <div class="sq-row">
              <?php foreach ($chs as $ch): ?><span class="sq-chip ch"><?= e($ch) ?></span><?php endforeach; ?>
              <?php if (!empty($qi['format'])): ?><span class="sq-chip"><?= e($formatNames[$qi['format']] ?? $qi['format']) ?></span><?php endif; ?>
              <?php if (!empty($qi['scope'])): ?><span class="sq-chip"><?= e($scopeNames[$qi['scope']] ?? $qi['scope']) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (isset($authorTables[$qi['content_type']]) && ($au || empty($qi['created_by']))): ?>
            <div class="sq-row"><span class="sq-label">Scritto da</span>
              <?= $au ? authorBadgeHtml($au) : '<span class="sq-meta"><i class="fa-solid fa-pen-nib"></i> non registrato</span>' ?></div>
          <?php endif; ?>
          <?php if (!empty($qi['created_by'])): ?>
            <div class="sq-row"><span class="sq-label">Accodato da</span> <?= authorBadgeHtml($qi['created_by']) ?></div>
          <?php endif; ?>
        </div>
        <div class="sq-side">
          <div class="sq-side-info">
            <span class="sq-pill sq-status" style="background:<?= $color ?>;"><?= e($statusNames[$shownStatus] ?? $shownStatus) ?></span>
            <?php if ($isEditable): ?>
              <span><span class="sq-label">Uscita</span>
                <input type="datetime-local" class="sq-scheduled-input" data-id="<?= $qi['id'] ?>"
                       value="<?= $qi['scheduled_for'] ? date('Y-m-d\TH:i', strtotime($qi['scheduled_for'])) : '' ?>"></span>
            <?php elseif ($qi['scheduled_for']): ?>
              <span><span class="sq-label">Uscita</span> <span class="sq-when"><?= date('d/m/Y H:i', strtotime($qi['scheduled_for'])) ?></span></span>
            <?php endif; ?>
            <span class="sq-meta">Aggiunto il <?= date('d/m/Y H:i', strtotime($qi['created_at'])) ?></span>
            <?php if (!empty($qi['processed_at'])): ?><span class="sq-meta">Elaborato il <?= date('d/m H:i', strtotime($qi['processed_at'])) ?></span><?php endif; ?>
            <?php if (!empty($qi['scheduler_ref'])): ?><span class="sq-meta" title="Riferimento su Metricool">Metricool: <?= e($qi['scheduler_ref']) ?></span><?php endif; ?>
            <?php if ($qi['error_msg']): ?><span class="sq-meta" style="color:#e74c3c;"><?= e(mb_substr($qi['error_msg'], 0, 140)) ?></span><?php endif; ?>
          </div>
          <div class="sq-actions">
            <?php if ($isEditable): ?>
              <button type="button" class="btn-xs sq-suspend-btn" data-id="<?= $qi['id'] ?>" data-suspended="<?= $isSuspended ? '1' : '0' ?>">
                <?= $isSuspended ? '▶ Riattiva' : '⏸ Sospendi' ?>
              </button>
            <?php endif; ?>
            <button type="button" class="btn-xs danger sq-delete-btn" data-id="<?= $qi['id'] ?>">✕ Rimuovi</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:8px;margin-top:14px;">
      <?php if ($page > 1): ?><a href="?p=<?= $page-1 ?><?= e($filtersQs) ?>" class="btn small">← Prec.</a><?php endif; ?>
      <span style="line-height:2;color:var(--text-muted);font-size:13px;">Pagina <?= $page ?> di <?= $totalPages ?> (<?= $totalCount ?> elementi)</span>
      <?php if ($page < $totalPages): ?><a href="?p=<?= $page+1 ?><?= e($filtersQs) ?>" class="btn small">Succ. →</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<dialog class="sq-dlg" id="sq-dlg">
  <div class="sq-dlg-in">
    <div class="sq-dlg-head"><strong id="sq-dlg-title"></strong><button type="button" class="sq-dlg-close" id="sq-dlg-x" aria-label="Chiudi">&times;</button></div>
    <div class="sq-dlg-media" id="sq-dlg-media"></div>
    <p class="sq-dlg-text" id="sq-dlg-text"></p>
    <div class="sq-dlg-meta" id="sq-dlg-meta"></div>
  </div>
</dialog>
<script>
(function () {
  // Anteprima di ciò che è in programma: immagine, immagine Story, video (con lettore) e testo.
  var dlg = document.getElementById('sq-dlg');
  var mediaBox = document.getElementById('sq-dlg-media');
  function fig(cap, el, cls) {
    var f = document.createElement('figure'); if (cls) f.className = cls;
    var c = document.createElement('figcaption'); c.textContent = cap; f.appendChild(c); f.appendChild(el); return f;
  }
  function stopVideo() { mediaBox.querySelectorAll('video').forEach(function (v) { v.pause(); }); }
  document.querySelectorAll('.sq-prev').forEach(function (b) {
    b.addEventListener('click', function () {
      var d; try { d = JSON.parse(b.getAttribute('data-prev')); } catch (e) { return; }
      document.getElementById('sq-dlg-title').textContent = d.title || '';
      document.getElementById('sq-dlg-text').textContent = d.text || '';
      var meta = document.getElementById('sq-dlg-meta'); meta.textContent = '';
      (d.meta || []).forEach(function (m) { var s = document.createElement('span'); s.textContent = m; meta.appendChild(s); });
      mediaBox.textContent = '';
      if (d.image) { var i = document.createElement('img'); i.src = d.image; i.alt = ''; mediaBox.appendChild(fig('Immagine', i)); }
      if (d.story) { var j = document.createElement('img'); j.src = d.story; j.alt = ''; mediaBox.appendChild(fig('Immagine Story', j, 'story')); }
      if (d.video) { var v = document.createElement('video'); v.src = d.video; v.controls = true; v.preload = 'metadata'; v.playsInline = true; mediaBox.appendChild(fig('Video', v)); }
      dlg.showModal();
    });
  });
  dlg.addEventListener('close', stopVideo);
  document.getElementById('sq-dlg-x').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
})();
(function () {
  const apiUrl = '/api_social_queue_action.php';

  function apiPost(payload) {
    return fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then(r => r.json());
  }

  // Suspend / unsuspend
  document.querySelectorAll('.sq-suspend-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = parseInt(btn.dataset.id);
      btn.disabled = true;
      apiPost({ action: 'suspend', id }).then(function (data) {
        btn.disabled = false;
        if (!data.ok) { alert(data.error || 'Errore'); return; }
        const isSuspended = data.suspended;
        btn.dataset.suspended = isSuspended ? '1' : '0';
        btn.textContent = isSuspended ? '▶ Riattiva' : '⏸ Sospendi';
        const row = document.getElementById('sq-row-' + id);
        if (row) {
          const statusEl = row.querySelector('.sq-status');
          const colors = { pending: '#e67e22', suspended: '#95a5a6' };
          const names = { pending: 'In attesa', suspended: 'Sospeso' };
          statusEl.textContent = names[data.status] || data.status;
          statusEl.style.background = colors[data.status] || '#666';
        }
      }).catch(function () { btn.disabled = false; alert('Errore di connessione'); });
    });
  });

  // Delete
  document.querySelectorAll('.sq-delete-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Rimuovere questo elemento dalla coda?')) return;
      const id = parseInt(btn.dataset.id);
      btn.disabled = true;
      apiPost({ action: 'delete', id }).then(function (data) {
        if (!data.ok) { btn.disabled = false; alert(data.error || 'Errore'); return; }
        const row = document.getElementById('sq-row-' + id);
        if (row) row.remove();
      }).catch(function () { btn.disabled = false; alert('Errore di connessione'); });
    });
  });

  // Reschedule on blur/change
  document.querySelectorAll('.sq-scheduled-input').forEach(function (input) {
    input.addEventListener('change', function () {
      const id = parseInt(input.dataset.id);
      apiPost({ action: 'reschedule', id, scheduled_for: input.value || null }).then(function (data) {
        if (!data.ok) alert(data.error || 'Errore salvataggio data');
      });
    });
  });
})();
</script>

<?php include __DIR__ . '/_dash_footer.php'; ?>
