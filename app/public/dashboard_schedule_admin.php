<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireAdmin();
$profile = getActingProfile($user);
$activeTab = 'schedule_admin';
$pageTitle = 'Calendario Programmazioni';

$allItems = getScheduledContentForAllUsers();

$accounts = [];
foreach ($allItems as $it) {
    $uid = $it['user_id'];
    if (!isset($accounts[$uid])) {
        $accounts[$uid] = $it['user_display_name'];
    }
}
asort($accounts);

$filterUserId = isset($_GET['account']) && $_GET['account'] !== '' ? (int) $_GET['account'] : null;
$filteredItems = $filterUserId !== null
    ? array_values(array_filter($allItems, fn ($it) => $it['user_id'] === $filterUserId))
    : $allItems;

$today = new DateTime('today');
$calendarStart = clone $today;

$byDate = [];
foreach ($filteredItems as $it) {
    $d = date('Y-m-d', strtotime($it['scheduled_for']));
    $byDate[$d][] = $it;
}

$viewMode = $_GET['view'] ?? 'calendar';

$typeBg = [
    'pensiero' => '#6C5CE7', 'blog' => '#00B894', 'brano' => '#E17055',
    'band_favorita' => '#0984E3', 'attore_favorito' => '#D63031', 'film_favorito' => '#E84393',
    'libro_favorito' => '#FDCB6E', 'viaggio_favorito' => '#00CEC9', 'playlist_favorita' => '#A29BFE',
    'album_favorito' => '#55EFC4', 'ricetta_favorita' => '#FAB1A0', 'squadra_favorita' => '#74B9FF',
    'calciatore_favorito' => '#636E72', 'partita_favorita' => '#2D3436',
    'pubblicazione_favorita' => '#FD79A8', 'offerta' => '#F0AD4E', 'album_foto' => '#81ECEC',
    'servizio' => '#DFE6E9',
];

include __DIR__ . '/_dash_header.php';
?>

<style>
.cal-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:2px;margin-bottom:24px}
.cal-table th{text-align:center;font-size:11px;font-weight:700;color:var(--text-muted,#6c757d);text-transform:uppercase;padding:0 0 4px}
.cal-table td{vertical-align:top;min-height:70px;height:70px;border:1px solid var(--border-color,#dee2e6);border-radius:4px;padding:3px;font-size:11px;background:var(--card-bg,#fff);overflow:hidden}
.cal-table td.today{border-color:#0d6efd;border-width:2px}
.cal-table td.empty{background:transparent;border-color:transparent}
.cal-date{font-weight:700;margin-bottom:2px;color:var(--text-muted,#6c757d);font-size:11px}
td.today .cal-date{color:#0d6efd}
.cal-item{display:flex;gap:3px;align-items:center;padding:1px 2px;border-radius:3px;margin-bottom:1px;cursor:pointer;text-decoration:none;color:inherit;font-size:10px;line-height:1.3;overflow:hidden}
.cal-item:hover{background:rgba(0,0,0,.06)}
.cal-item img{width:18px;height:18px;border-radius:2px;object-fit:cover;flex-shrink:0}
.cal-item-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
.cal-item-time{font-size:9px;color:var(--text-muted,#6c757d);white-space:nowrap;flex-shrink:0}
.cal-type-badge{display:inline-block;font-size:8px;font-weight:700;padding:1px 3px;border-radius:999px;white-space:nowrap;color:#fff;line-height:1.3;flex-shrink:0}
.cal-more{font-size:10px;color:var(--text-muted);padding:1px 2px;cursor:pointer}
.view-toggle{display:inline-flex;gap:0;border:1px solid var(--border-color,#dee2e6);border-radius:6px;overflow:hidden}
.view-toggle a{padding:6px 14px;font-size:13px;text-decoration:none;color:var(--text-muted,#6c757d);border-right:1px solid var(--border-color,#dee2e6)}
.view-toggle a:last-child{border-right:none}
.view-toggle a.active{background:#0d6efd;color:#fff}

.mob-day{margin-bottom:12px;border:1px solid var(--border-color,#dee2e6);border-radius:6px;background:var(--card-bg,#fff);overflow:hidden}
.mob-day.today{border-color:#0d6efd;border-width:2px}
.mob-day-header{padding:6px 10px;font-weight:700;font-size:13px;background:rgba(0,0,0,.03);border-bottom:1px solid var(--border-color,#dee2e6)}
.mob-day.today .mob-day-header{color:#0d6efd}
.mob-item{display:flex;gap:8px;align-items:center;padding:6px 10px;border-bottom:1px solid var(--border-color,#dee2e6);text-decoration:none;color:inherit;font-size:13px}
.mob-item:last-child{border-bottom:none}
.mob-item:active{background:rgba(0,0,0,.04)}
.mob-item img{width:36px;height:36px;border-radius:4px;object-fit:cover;flex-shrink:0}
.mob-item-body{flex:1;min-width:0;overflow:hidden}
.mob-item-title{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mob-item-meta{font-size:11px;color:var(--text-muted,#6c757d);display:flex;gap:6px;align-items:center;flex-wrap:wrap}

@media(max-width:1023px){
  .cal-desktop{display:none!important}
  .cal-mobile{display:block!important}
}
@media(min-width:1024px){
  .cal-desktop{display:block}
  .cal-mobile{display:none!important}
}
</style>

<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px;">
  <div class="section-title" style="margin:0;flex:1;min-width:200px;">
    Calendario Programmazioni (<?= count($filteredItems) ?>)
  </div>
  <div class="view-toggle">
    <a href="?view=calendar<?= $filterUserId !== null ? '&account=' . $filterUserId : '' ?>" class="<?= $viewMode === 'calendar' ? 'active' : '' ?>">📅 Calendario</a>
    <a href="?view=list<?= $filterUserId !== null ? '&account=' . $filterUserId : '' ?>" class="<?= $viewMode === 'list' ? 'active' : '' ?>">📋 Lista</a>
  </div>
</div>

<div style="margin-bottom:16px;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="view" value="<?= e($viewMode) ?>">
    <label style="font-size:13px;font-weight:600;">Filtra account:</label>
    <select name="account" onchange="this.form.submit()" style="max-width:280px;font-size:13px;padding:6px 10px;border-radius:6px;border:1px solid var(--border-color,#dee2e6);">
      <option value="">Tutti gli account (<?= count($accounts) ?>)</option>
      <?php foreach ($accounts as $uid => $name): ?>
        <option value="<?= $uid ?>" <?= $filterUserId === $uid ? 'selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$filteredItems): ?>
  <div class="alert error">Nessun contenuto programmato al momento.</div>
<?php elseif ($viewMode === 'calendar'): ?>

<?php $dayNames = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom']; ?>

<!-- Desktop: tabella 7 colonne a larghezza fissa -->
<div class="cal-desktop">
<table class="cal-table">
<thead><tr>
  <?php foreach ($dayNames as $dn): ?>
    <th><?= $dn ?></th>
  <?php endforeach; ?>
</tr></thead>
<tbody>
<?php
$cursor = clone $calendarStart;
$startDow = (int) $calendarStart->format('N');
$cellIndex = 0;

echo '<tr>';
for ($pad = 1; $pad < $startDow; $pad++) {
    echo '<td class="empty"></td>';
    $cellIndex++;
}
for ($day = 0; $day < 30; $day++):
    if ($cellIndex > 0 && $cellIndex % 7 === 0) {
        echo '</tr><tr>';
    }
    $dateKey = $cursor->format('Y-m-d');
    $isToday = $dateKey === $today->format('Y-m-d');
    $dayItems = $byDate[$dateKey] ?? [];
    $maxShow = 4;
?>
  <td class="<?= $isToday ? 'today' : '' ?>">
    <div class="cal-date"><?= $cursor->format('d') ?> <?= strtolower($cursor->format('M')) ?></div>
    <?php foreach (array_slice($dayItems, 0, $maxShow) as $it):
        $bg = $typeBg[$it['type']] ?? '#999';
        $time = date('H:i', strtotime($it['scheduled_for']));
    ?>
      <a class="cal-item" href="<?= e($it['edit_url']) ?>" title="<?= e($it['title']) ?> — <?= e($it['user_display_name']) ?> (<?= e($it['label']) ?>) ore <?= $time ?>">
        <?php if ($it['cover']): ?>
          <img src="/<?= e($it['cover']) ?>" alt="">
        <?php endif; ?>
        <span class="cal-type-badge" style="background:<?= $bg ?>"><?= e(mb_substr($it['label'], 0, 4)) ?></span>
        <span class="cal-item-text"><?= e(mb_strimwidth($it['title'], 0, 18, '…')) ?></span>
        <span class="cal-item-time"><?= $time ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (count($dayItems) > $maxShow): ?>
      <div class="cal-more">+<?= count($dayItems) - $maxShow ?> altri</div>
    <?php endif; ?>
  </td>
<?php
    $cellIndex++;
    $cursor->modify('+1 day');
endfor;
// Celle vuote per completare l'ultima riga
$remainder = $cellIndex % 7;
if ($remainder > 0) {
    for ($pad = $remainder; $pad < 7; $pad++) {
        echo '<td class="empty"></td>';
    }
}
?>
</tr>
</tbody>
</table>
</div>

<!-- Mobile: lista per giorno (solo giorni con contenuti + oggi) -->
<div class="cal-mobile">
<?php
$cursor = clone $calendarStart;
for ($day = 0; $day < 30; $day++):
    $dateKey = $cursor->format('Y-m-d');
    $isToday = $dateKey === $today->format('Y-m-d');
    $dayItems = $byDate[$dateKey] ?? [];
    if (!$dayItems && !$isToday) { $cursor->modify('+1 day'); continue; }
?>
  <div class="mob-day<?= $isToday ? ' today' : '' ?>">
    <div class="mob-day-header">
      <?= $cursor->format('d') ?> <?= strtolower($cursor->format('M')) ?>
      <?php if ($isToday): ?> <span style="font-size:11px;font-weight:400;">— oggi</span><?php endif; ?>
      <?php if ($dayItems): ?> <span style="font-size:11px;font-weight:400;color:var(--text-muted);">(<?= count($dayItems) ?>)</span><?php endif; ?>
    </div>
    <?php if (!$dayItems): ?>
      <div style="padding:8px 10px;font-size:12px;color:var(--text-muted);">Nessun contenuto</div>
    <?php endif; ?>
    <?php foreach ($dayItems as $it):
        $bg = $typeBg[$it['type']] ?? '#999';
        $time = date('H:i', strtotime($it['scheduled_for']));
    ?>
      <a class="mob-item" href="<?= e($it['edit_url']) ?>">
        <?php if ($it['cover']): ?>
          <img src="/<?= e($it['cover']) ?>" alt="">
        <?php endif; ?>
        <div class="mob-item-body">
          <div class="mob-item-title"><?= e(mb_strimwidth($it['title'], 0, 50, '…')) ?></div>
          <div class="mob-item-meta">
            <span class="cal-type-badge" style="background:<?= $bg ?>;font-size:10px;padding:1px 6px;"><?= e($it['label']) ?></span>
            <span>🕐 <?= $time ?></span>
            <?php if (!$filterUserId): ?>
              <span>👤 <?= e($it['user_display_name']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php
    $cursor->modify('+1 day');
endfor;
?>
</div>

<details style="margin-bottom:16px">
  <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--text-muted);">🎨 Legenda tipi</summary>
  <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">
    <?php foreach (PINNABLE_CONTENT_TYPES as $type => $cfg):
        if (pinnableScheduleColumn($cfg['visibility']) === null) continue;
        $bg = $typeBg[$type] ?? '#999';
    ?>
      <span class="cal-type-badge" style="background:<?= $bg ?>;font-size:11px;padding:2px 8px;"><?= e($cfg['label']) ?></span>
    <?php endforeach; ?>
  </div>
</details>

<?php else: /* list view */ ?>

<?php
$groupedByDate = [];
foreach ($filteredItems as $it) {
    $d = date('Y-m-d', strtotime($it['scheduled_for']));
    $groupedByDate[$d][] = $it;
}
?>
<?php foreach ($groupedByDate as $date => $dateItems): ?>
  <div class="section-title" style="font-size:14px;margin-top:16px;margin-bottom:8px;">
    📅 <?= date('d/m/Y', strtotime($date)) ?>
    <?php
    $daysFromNow = (int) ((strtotime($date) - strtotime('today')) / 86400);
    if ($daysFromNow === 0) echo '<span style="color:#0d6efd;font-weight:700;font-size:12px;">(oggi)</span>';
    elseif ($daysFromNow === 1) echo '<span style="color:var(--text-muted);font-size:12px;">(domani)</span>';
    elseif ($daysFromNow > 1) echo '<span style="color:var(--text-muted);font-size:12px;">(tra ' . $daysFromNow . ' giorni)</span>';
    ?>
  </div>
  <?php foreach ($dateItems as $it): ?>
    <a href="<?= e($it['edit_url']) ?>" class="card" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;border:1px solid #f0ad4e;text-decoration:none;color:inherit;">
      <?php if ($it['cover']): ?>
        <img src="/<?= e($it['cover']) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:180px;">
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:4px;">
          <span style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;white-space:nowrap;">
            ⏰ <?= e(date('d/m/Y H:i', strtotime($it['scheduled_for']))) ?>
          </span>
          <span style="color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.3px;white-space:nowrap;"><?= e($it['label']) ?></span>
        </div>
        <p style="margin:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e(textExcerpt($it['title'], 120)) ?></p>
        <p style="margin:2px 0 0;font-size:12px;color:var(--text-muted);">👤 <?= e($it['user_display_name']) ?></p>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;margin-top:4px;" onclick="event.stopPropagation();">
        <?php if ($it['preview_url']): ?>
          <button type="button" class="btn small secondary schedule-preview-copy" data-url="<?= e($it['preview_url']) ?>">🔗 Copia</button>
          <a href="<?= e($it['preview_url']) ?>" target="_blank" rel="noopener" class="btn small secondary" onclick="event.stopPropagation();">👁️ Anteprima</a>
        <?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
<?php endforeach; ?>

<?php endif; ?>

<script>
document.querySelectorAll('.schedule-preview-copy').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var url = btn.getAttribute('data-url');
    navigator.clipboard.writeText(url).then(function () {
      var original = btn.textContent;
      btn.textContent = '✅ Copiato!';
      setTimeout(function () { btn.textContent = original; }, 1800);
    }).catch(function () {
      window.prompt('Copia questo link:', url);
    });
  });
});
</script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
