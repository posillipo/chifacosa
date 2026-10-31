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
    $where .= ' AND q.status IN ("pending","processing","suspended")';
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

include __DIR__ . '/_dash_header.php';
?>
<style>
.sq-table td, .sq-table th { padding: 9px 12px; white-space:nowrap; }
.sq-table { width:100%; border-collapse:collapse; font-size:13.5px; table-layout:auto; }
.sq-table tr { border-top:1px solid var(--border-color,#e0e0e0); }
.sq-table thead tr { background:var(--bg-alt,#f5f5f5); border-top:none; }
.sq-table th { white-space:nowrap; }
.sq-col-tipo { width:90px; }
.sq-col-contenuto { max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.sq-status { font-weight:600; white-space:nowrap; }
.sq-actions { display:flex; gap:6px; align-items:center; }
.btn-xs { font-size:12px; padding:3px 9px; border-radius:4px; cursor:pointer; border:1px solid #ccc; background:#fff; white-space:nowrap; }
.btn-xs.danger { color:#e74c3c; border-color:#e74c3c; }
.btn-xs.primary { color:#fff; background:var(--accent,#6c5ce7); border-color:var(--accent,#6c5ce7); }
@media(max-width:600px) { .sq-col-aggiunto { display:none; } }
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
          <th class="sq-col-tipo" style="text-align:left;">Tipo</th>
          <th style="text-align:left;">Contenuto</th>
          <th style="text-align:left;">Stato</th>
          <th style="text-align:left;">Programmato</th>
          <th class="sq-col-aggiunto" style="text-align:left;">Aggiunto</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="sq-tbody">
        <?php foreach ($items as $qi): ?>
          <?php
          $statusColors = ['pending' => '#e67e22', 'processing' => '#2980b9', 'done' => '#27ae60', 'failed' => '#e74c3c', 'suspended' => '#95a5a6', 'scheduled' => '#8e44ad', 'skipped' => '#7f8c8d'];
          $color = $statusColors[$qi['status']] ?? '#666';
          $label = mb_substr($qi['title'] ?: ($qi['body'] ?? ''), 0, 60) ?: '—';
          $isSuspended = !empty($qi['suspended']) || $qi['status'] === 'suspended';
          $isEditable = in_array($qi['status'], ['pending', 'suspended', 'processing'], true);
          ?>
          <tr id="sq-row-<?= $qi['id'] ?>">
            <td class="sq-col-tipo"><span style="font-size:12px;color:var(--text-muted);"><?= e($qi['content_type']) ?></span></td>
            <td class="sq-col-contenuto">
              <?php if ($qi['public_url']): ?><a href="<?= e($qi['public_url']) ?>" target="_blank" style="color:inherit;"><?= e($label) ?></a>
              <?php else: ?><?= e($label) ?>
              <?php endif; ?>
              <?php
              // Dati del registro (presenti solo dopo la migrazione 2026_10_31 e solo per le uscite che li usano).
              $regBits = [];
              if (!empty($qi['scope'])) $regBits[] = $qi['scope'];
              if (!empty($qi['format'])) $regBits[] = $qi['format'];
              if (!empty($qi['channels'])) {
                  $chs = json_decode((string) $qi['channels'], true);
                  if (is_array($chs) && $chs) $regBits[] = implode(', ', $chs);
              }
              ?>
              <?php if ($regBits): ?><br><small style="color:var(--text-muted);"><?= e(implode(' · ', $regBits)) ?></small><?php endif; ?>
            </td>
            <td>
              <span class="sq-status" style="color:<?= $color ?>;"><?= e($qi['status']) ?></span>
              <?php if ($qi['error_msg']): ?><br><small style="color:#e74c3c;"><?= e(mb_substr($qi['error_msg'], 0, 60)) ?></small><?php endif; ?>
            </td>
            <td>
              <?php if ($isEditable): ?>
                <input type="datetime-local" class="sq-scheduled-input" data-id="<?= $qi['id'] ?>"
                       value="<?= $qi['scheduled_for'] ? date('Y-m-d\TH:i', strtotime($qi['scheduled_for'])) : '' ?>"
                       style="font-size:12px;padding:3px 6px;border:1px solid #ccc;border-radius:4px;max-width:160px;">
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:12.5px;"><?= $qi['scheduled_for'] ? date('d/m H:i', strtotime($qi['scheduled_for'])) : '—' ?></span>
              <?php endif; ?>
            </td>
            <td class="sq-col-aggiunto" style="color:var(--text-muted);font-size:12.5px;"><?= date('d/m H:i', strtotime($qi['created_at'])) ?></td>
            <td>
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
          statusEl.textContent = data.status;
          statusEl.style.color = colors[data.status] || '#666';
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
