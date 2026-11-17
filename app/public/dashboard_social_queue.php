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
.sq-table { width:100%; border-collapse:collapse; font-size:13.5px; }
.sq-table th { padding:10px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); white-space:nowrap; }
.sq-table td { padding:12px; vertical-align:top; }
.sq-table tbody tr { border-top:1px solid var(--border-color,#e0e0e0); }
.sq-table thead tr { background:var(--bg-alt,#f5f5f5); }
.sq-thumb { width:56px; height:56px; border-radius:8px; object-fit:cover; display:block; background:var(--bg-alt,#f0f0f0); }
.sq-thumb-empty { width:56px; height:56px; border-radius:8px; background:var(--bg-alt,#f0f0f0); display:flex; align-items:center; justify-content:center; color:var(--text-muted); }
.sq-title { font-weight:700; color:inherit; text-decoration:none; display:block; line-height:1.3; }
a.sq-title:hover { text-decoration:underline; }
.sq-excerpt { color:var(--text-muted); font-size:12.5px; margin-top:3px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; max-width:420px; }
.sq-pill { display:inline-block; font-size:11px; font-weight:700; padding:2px 9px; border-radius:999px; color:#fff; white-space:nowrap; }
.sq-chip { display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:6px; border:1px solid var(--border-color,#d0d0d0); color:var(--text-muted); background:var(--bg-alt,#fafafa); margin:0 4px 4px 0; white-space:nowrap; }
.sq-chip.ch { color:var(--accent,#6c5ce7); border-color:var(--accent,#6c5ce7); background:transparent; }
.sq-type { font-size:12px; font-weight:700; white-space:nowrap; }
.sq-meta { color:var(--text-muted); font-size:12px; margin-top:4px; }
.sq-when { font-weight:700; white-space:nowrap; }
.sq-actions { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.btn-xs { font-size:12px; padding:3px 9px; border-radius:4px; cursor:pointer; border:1px solid #ccc; background:#fff; white-space:nowrap; }
.btn-xs.danger { color:#e74c3c; border-color:#e74c3c; }
.btn-xs.primary { color:#fff; background:var(--accent,#6c5ce7); border-color:var(--accent,#6c5ce7); }
/* Su schermi stretti ogni riga diventa una scheda con tutti i dati, uno sotto l'altro. */
@media(max-width:900px) {
  .sq-table, .sq-table tbody { display:block; }
  .sq-table thead { display:none; }
  .sq-table tr { display:block; padding:12px; }
  .sq-table td { display:flex; gap:10px; align-items:baseline; padding:4px 0; border:0; }
  .sq-table td[data-label]::before { content:attr(data-label); flex:0 0 84px; color:var(--text-muted); font-size:12px; }
  .sq-excerpt { max-width:none; }
  .sq-col-azioni { padding-top:8px; }
  .sq-scheduled-input { max-width:100%; }
}
</style>

<?php if (!$metricoolActive): ?>
  <div class="alert error">Il servizio Social Automatico non è ancora configurato per questo profilo. <a href="/dashboard_metricool.php">Configura</a></div>
<?php endif; ?>

<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
  <div class="section-title" style="margin:0;flex:1;">📤 Coda Social</div>
  <a href="/dashboard_timeline.php" class="btn small">← Torna al Feed</a>
</div>

<!-- Filtri -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
  <?php foreach (['active' => '⚡ Attivi', 'pending' => '⏳ In attesa', 'suspended' => '⏸ Sospesi', 'scheduled' => '🗓 Programmati', 'done' => '✅ Completati', 'skipped' => '⏭ Saltati', 'failed' => '❌ Errori', 'all' => 'Tutti'] as $s => $l): ?>
    <a href="?status=<?= $s ?>" class="btn small <?= $statusFilter === $s ? '' : 'secondary' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$items): ?>
  <div class="card" style="color:var(--text-muted);text-align:center;">Nessun elemento in questa visualizzazione.</div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden;">
    <table class="sq-table">
      <thead>
        <tr>
          <th style="width:68px;"></th>
          <th>Contenuto</th>
          <th>Canali e formato</th>
          <th>Stato</th>
          <th>Uscita</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="sq-tbody">
        <?php
        $statusColors = ['pending' => '#e67e22', 'processing' => '#2980b9', 'done' => '#27ae60', 'failed' => '#e74c3c', 'suspended' => '#95a5a6', 'scheduled' => '#8e44ad', 'skipped' => '#7f8c8d'];
        $statusNames  = ['pending' => 'In attesa', 'processing' => 'In elaborazione', 'done' => 'Completato', 'failed' => 'Errore', 'suspended' => 'Sospeso', 'scheduled' => 'Programmato', 'skipped' => 'Saltato'];
        $typeNames = ['blog' => ['📝', 'Blog'], 'timeline' => ['💬', 'Timeline'], 'album' => ['🖼', 'Album'], 'service' => ['🛠', 'Servizio'], 'event' => ['📅', 'Evento'], 'video' => ['🎬', 'Video'], 'product' => ['🛍', 'Prodotto'], 'offer' => ['🏷', 'Offerta']];
        $scopeNames = ['announcement' => 'Annuncio', 'reminder' => 'Promemoria', 'result' => 'Risultato', 'new' => 'Novità', 'promo' => 'Promo', 'release' => 'Uscita', 'reprise' => 'Ripresa'];
        $formatNames = ['feed' => 'Feed', 'story' => 'Story', 'reel' => 'Reel'];
        ?>
        <?php foreach ($items as $qi): ?>
          <?php
          // Stato mostrato: "suspended" se l'elemento è ancora da lavorare ma sospeso.
          $isSuspended = !empty($qi['suspended']) && in_array($qi['status'], ['pending', 'processing'], true);
          $shownStatus = $isSuspended ? 'suspended' : $qi['status'];
          $color = $statusColors[$shownStatus] ?? '#666';
          $label = mb_substr($qi['title'] ?: ($qi['body'] ?? ''), 0, 90) ?: '—';
          $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($qi['body'] ?? ''))));
          $bodyText = str_replace('**', '', $bodyText);
          $isEditable = in_array($qi['status'], ['pending', 'processing'], true);
          [$typeIcon, $typeLabel] = $typeNames[$qi['content_type']] ?? ['📄', ucfirst((string) $qi['content_type'])];
          $chs = [];
          if (!empty($qi['channels'])) {
              $dec = json_decode((string) $qi['channels'], true);
              if (is_array($dec)) $chs = $dec;
          }
          $img = $qi['image_url'] ?? '';
          ?>
          <tr id="sq-row-<?= $qi['id'] ?>">
            <td data-label="">
              <?php if ($img): ?><img class="sq-thumb" src="<?= e($img) ?>" alt="" loading="lazy">
              <?php else: ?><div class="sq-thumb-empty"><?= $typeIcon ?></div><?php endif; ?>
            </td>
            <td class="sq-col-contenuto" data-label="Contenuto">
              <div class="sq-type" style="color:var(--text-muted);"><?= $typeIcon ?> <?= e($typeLabel) ?> <span style="font-weight:400;">· #<?= (int) $qi['content_id'] ?></span></div>
              <?php if ($qi['public_url']): ?><a class="sq-title" href="<?= e($qi['public_url']) ?>" target="_blank"><?= e($label) ?></a>
              <?php else: ?><span class="sq-title"><?= e($label) ?></span><?php endif; ?>
              <?php if ($bodyText !== '' && $bodyText !== $label): ?><div class="sq-excerpt"><?= e(mb_substr($bodyText, 0, 220)) ?></div><?php endif; ?>
              <?php if (isset($authorTables[$qi['content_type']])): $au = $authorsByItem[$qi['content_type']][(int) $qi['content_id']] ?? null; ?>
                <div style="margin-top:5px;"><?= $au ? authorBadgeHtml($au) : (empty($qi['created_by']) ? '<span class="sq-meta" style="margin:0;"><i class="fa-solid fa-pen-nib"></i> Autore non registrato</span>' : '') ?></div>
              <?php endif; ?>
              <?php if (!empty($qi['created_by'])): ?>
                <div class="sq-meta" style="margin-top:5px;">Accodato da <?= authorBadgeHtml($qi['created_by']) ?></div>
              <?php endif; ?>
              <div class="sq-meta">Aggiunto il <?= date('d/m/Y H:i', strtotime($qi['created_at'])) ?></div>
            </td>
            <td data-label="Canali">
              <?php foreach ($chs as $ch): ?><span class="sq-chip ch"><?= e($ch) ?></span><?php endforeach; ?>
              <?php if (!empty($qi['format'])): ?><span class="sq-chip"><?= e($formatNames[$qi['format']] ?? $qi['format']) ?></span><?php endif; ?>
              <?php if (!empty($qi['scope'])): ?><span class="sq-chip"><?= e($scopeNames[$qi['scope']] ?? $qi['scope']) ?></span><?php endif; ?>
              <?php if (!$chs && empty($qi['format']) && empty($qi['scope'])): ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
            </td>
            <td data-label="Stato">
              <span class="sq-pill sq-status" style="background:<?= $color ?>;"><?= e($statusNames[$shownStatus] ?? $shownStatus) ?></span>
              <?php if (!empty($qi['scheduler_ref'])): ?><div class="sq-meta" title="Riferimento su Metricool">Metricool: <?= e($qi['scheduler_ref']) ?></div><?php endif; ?>
              <?php if (!empty($qi['processed_at'])): ?><div class="sq-meta">Elaborato il <?= date('d/m H:i', strtotime($qi['processed_at'])) ?></div><?php endif; ?>
              <?php if ($qi['error_msg']): ?><div class="sq-meta" style="color:#e74c3c;"><?= e(mb_substr($qi['error_msg'], 0, 120)) ?></div><?php endif; ?>
            </td>
            <td data-label="Uscita">
              <?php if ($isEditable): ?>
                <input type="datetime-local" class="sq-scheduled-input" data-id="<?= $qi['id'] ?>"
                       value="<?= $qi['scheduled_for'] ? date('Y-m-d\TH:i', strtotime($qi['scheduled_for'])) : '' ?>"
                       style="font-size:12px;padding:3px 6px;border:1px solid #ccc;border-radius:4px;max-width:170px;">
              <?php elseif ($qi['scheduled_for']): ?>
                <span class="sq-when"><?= date('d/m/Y', strtotime($qi['scheduled_for'])) ?></span><div class="sq-meta" style="margin-top:0;">ore <?= date('H:i', strtotime($qi['scheduled_for'])) ?></div>
              <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
            </td>
            <td class="sq-col-azioni">
              <div class="sq-actions">
                <?php if ($isEditable): ?>
                  <button type="button" class="btn-xs sq-suspend-btn" data-id="<?= $qi['id'] ?>" data-suspended="<?= $isSuspended ? '1' : '0' ?>">
                    <?= $isSuspended ? '▶ Riattiva' : '⏸ Sospendi' ?>
                  </button>
                <?php endif; ?>
                <button type="button" class="btn-xs danger sq-delete-btn" data-id="<?= $qi['id'] ?>">✕</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:8px;margin-top:14px;">
      <?php if ($page > 1): ?><a href="?status=<?= $statusFilter ?>&p=<?= $page-1 ?>" class="btn small">← Prec.</a><?php endif; ?>
      <span style="line-height:2;color:var(--text-muted);font-size:13px;">Pagina <?= $page ?> di <?= $totalPages ?> (<?= $totalCount ?> elementi)</span>
      <?php if ($page < $totalPages): ?><a href="?status=<?= $statusFilter ?>&p=<?= $page+1 ?>" class="btn small">Succ. →</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
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
