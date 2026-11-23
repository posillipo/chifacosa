<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireAdmin(); // strumento riservato agli amministratori del sito
$profile = getActingProfile($user);
$activeTab = 'social_queue';
$pageTitle = 'Coda Social';
$pageHelpInline = true; // il "?" sta accanto al titolo (vedi sotto)

require_once __DIR__ . '/../../src/metricool_queue.php';
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

include __DIR__ . '/../_dash_header.php';
$statusLabels = ['active' => 'Attivi', 'pending' => 'In attesa', 'suspended' => 'Sospesi', 'scheduled' => 'Programmati', 'done' => 'Completati', 'skipped' => 'Saltati', 'failed' => 'Errori', 'all' => 'Tutti'];
$typeLabelsFilter = ['blog' => 'Blog', 'timeline' => 'Timeline', 'album' => 'Album', 'service' => 'Servizi', 'event' => 'Eventi', 'video' => 'Video', 'product' => 'Prodotti', 'offer' => 'Offerte'];
$statusNames  = ['pending' => 'In attesa', 'processing' => 'In elaborazione', 'done' => 'Completato', 'failed' => 'Errore', 'suspended' => 'Sospeso', 'scheduled' => 'Programmato', 'skipped' => 'Saltato'];
$typeNames = ['blog' => ['fa-pen-nib', 'Blog'], 'timeline' => ['fa-comment', 'Timeline'], 'album' => ['fa-images', 'Album'], 'service' => ['fa-screwdriver-wrench', 'Servizio'], 'event' => ['fa-calendar', 'Evento'], 'video' => ['fa-film', 'Video'], 'product' => ['fa-bag-shopping', 'Prodotto'], 'offer' => ['fa-tag', 'Offerta']];
$scopeNames = ['announcement' => 'Annuncio', 'reminder' => 'Promemoria', 'result' => 'Risultato', 'new' => 'Novità', 'promo' => 'Promo', 'release' => 'Uscita', 'reprise' => 'Ripresa'];
$formatNames = ['feed' => 'Feed', 'story' => 'Story', 'reel' => 'Reel'];
$okUrl = fn($u) => is_string($u) && $u !== '' && (preg_match('#^https?://#i', $u) || ($u[0] === '/' && !str_starts_with($u, '//'))) ? $u : '';
$sqLink = fn($st, $tp) => '?status=' . urlencode($st) . ($tp !== '' ? '&tipo=' . urlencode($tp) : '');
?>
  <div class="page-head"><h1 class="page-head-title">Coda Social</h1><?= renderPageHelpButton('dashboard_social_queue', 'Coda Social') ?></div>
  <div class="tlx">
    <?php if (!$metricoolActive): ?>
      <div class="alert error">Il servizio Social Automatico non è ancora configurato per questo profilo. <a href="/dashboard_metricool.php">Configura</a></div>
    <?php endif; ?>

    <div class="sqx-chips" role="group" aria-label="Stato">
      <?php foreach ($statusLabels as $sv => $sl): ?><a class="sqx-chip<?= $statusFilter === $sv ? ' on' : '' ?>" href="<?= e($sqLink($sv, $typeFilter)) ?>"><?= e($sl) ?></a><?php endforeach; ?>
    </div>
    <?php if ($typeOptions): ?>
      <div class="sqx-chips" role="group" aria-label="Tipo">
        <a class="sqx-chip<?= $typeFilter === '' ? ' on' : '' ?>" href="<?= e($sqLink($statusFilter, '')) ?>">Tutti i tipi</a>
        <?php foreach ($typeOptions as $tv): ?><a class="sqx-chip<?= $typeFilter === $tv ? ' on' : '' ?>" href="<?= e($sqLink($statusFilter, $tv)) ?>"><?= e($typeLabelsFilter[$tv] ?? ucfirst($tv)) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="tlx-hint"><?= e($statusLabels[$statusFilter]) ?><?= $typeFilter !== '' ? ' · ' . e($typeLabelsFilter[$typeFilter] ?? $typeFilter) : '' ?> — <?= (int) $totalCount ?> element<?= $totalCount === 1 ? 'o' : 'i' ?></p>

    <?php if (!$items): ?>
      <div class="tlx-empty"><i class="fa-solid fa-inbox"></i><b>Nessun elemento</b><span>Non c'è niente in questa visualizzazione.</span></div>
    <?php else: ?>
      <div id="sq-tbody">
        <?php foreach ($items as $qi):
          $isSuspended = !empty($qi['suspended']) && in_array($qi['status'], ['pending', 'processing'], true);
          $shownStatus = $isSuspended ? 'suspended' : $qi['status'];
          $label = mb_substr($qi['title'] ?: ($qi['body'] ?? ''), 0, 120) ?: '—';
          $bodyText = str_replace('**', '', trim(preg_replace('/\s+/', ' ', strip_tags((string) ($qi['body'] ?? '')))));
          $isEditable = in_array($qi['status'], ['pending', 'processing'], true);
          [$typeIcon, $typeLabel] = $typeNames[$qi['content_type']] ?? ['fa-file', ucfirst((string) $qi['content_type'])];
          $chs = [];
          if (!empty($qi['channels'])) { $dec = json_decode((string) $qi['channels'], true); if (is_array($dec)) $chs = $dec; }
          $au = isset($authorTables[$qi['content_type']]) ? ($authorsByItem[$qi['content_type']][(int) $qi['content_id']] ?? null) : null;
          $storyImg = $okUrl($qi['image_story_url'] ?? ''); $videoUrl = $okUrl($qi['media_url'] ?? ''); $mainImg = $okUrl($qi['image_url'] ?? '');
          $prev = ['title' => $label, 'text' => str_replace('**', '', trim(strip_tags((string) ($qi['body'] ?? '')))), 'image' => $mainImg, 'story' => $storyImg, 'video' => $videoUrl,
              'meta' => array_values(array_filter([$typeLabel . ' #' . (int) $qi['content_id'], $chs ? 'Canali: ' . implode(', ', $chs) : '', !empty($qi['format']) ? 'Formato: ' . ($formatNames[$qi['format']] ?? $qi['format']) : '', $qi['scheduled_for'] ? 'Uscita: ' . date('d/m/Y H:i', strtotime($qi['scheduled_for'])) : 'Uscita: appena possibile', 'Stato: ' . ($statusNames[$shownStatus] ?? $shownStatus)]))];
          $hasPrev = $mainImg || $storyImg || $videoUrl || $prev['text'] !== '';
        ?>
          <div class="sqx-item" id="sq-row-<?= (int) $qi['id'] ?>">
            <div class="sqx-top">
              <?php if ($hasPrev): ?><button type="button" class="sq-prev sqx-thumb" data-prev="<?= e(json_encode($prev, JSON_UNESCAPED_UNICODE)) ?>" title="Anteprima"><?php else: ?><span class="sqx-thumb"><?php endif; ?>
                <?php if ($mainImg): ?><img src="<?= e($mainImg) ?>" alt="" loading="lazy"><?php else: ?><i class="fa-solid <?= $videoUrl ? 'fa-film' : e($typeIcon) ?>"></i><?php endif; ?>
              <?= $hasPrev ? '</button>' : '</span>' ?>
              <div class="sqx-main">
                <span class="pnx-t"><i class="fa-solid <?= e($typeIcon) ?>"></i> <?= e($typeLabel) ?> · #<?= (int) $qi['content_id'] ?></span>
                <?php if ($qi['public_url']): ?><a class="sqx-title" href="<?= e($qi['public_url']) ?>" target="_blank" rel="noopener"><?= e($label) ?></a><?php else: ?><span class="sqx-title"><?= e($label) ?></span><?php endif; ?>
                <?php if ($bodyText !== '' && $bodyText !== $label): ?><div class="sqx-ex"><?= e(mb_substr($bodyText, 0, 220)) ?></div><?php endif; ?>
                <div class="sqx-tags">
                  <?php foreach ($chs as $ch): ?><span class="sqx-tag ch"><?= e($ch) ?></span><?php endforeach; ?>
                  <?php if (!empty($qi['format'])): ?><span class="sqx-tag"><?= e($formatNames[$qi['format']] ?? $qi['format']) ?></span><?php endif; ?>
                  <?php if (!empty($qi['scope'])): ?><span class="sqx-tag"><?= e($scopeNames[$qi['scope']] ?? $qi['scope']) ?></span><?php endif; ?>
                  <?php if ($storyImg): ?><span class="sqx-tag">Story</span><?php endif; ?>
                  <?php if ($videoUrl): ?><span class="sqx-tag">Video</span><?php endif; ?>
                </div>
                <?php if (isset($authorTables[$qi['content_type']]) && ($au || empty($qi['created_by']))): ?>
                  <div class="sqx-by">Scritto da <?= $au ? authorBadgeHtml($au) : '<small>non registrato</small>' ?></div>
                <?php endif; ?>
                <?php if (!empty($qi['created_by'])): ?><div class="sqx-by">Accodato da <?= authorBadgeHtml($qi['created_by']) ?></div><?php endif; ?>
              </div>
            </div>
            <div class="sqx-foot">
              <span class="sqx-b sq-status" data-s="<?= e($shownStatus) ?>"><?= e($statusNames[$shownStatus] ?? $shownStatus) ?></span>
              <?php if ($isEditable): ?>
                <label class="sqx-when">Uscita <input type="datetime-local" class="sq-scheduled-input" data-id="<?= (int) $qi['id'] ?>" value="<?= $qi['scheduled_for'] ? date('Y-m-d\TH:i', strtotime($qi['scheduled_for'])) : '' ?>"></label>
              <?php elseif ($qi['scheduled_for']): ?>
                <span class="sqx-when">Uscita <b><?= date('d/m/Y H:i', strtotime($qi['scheduled_for'])) ?></b></span>
              <?php endif; ?>
              <span class="sqx-meta">Aggiunto il <?= date('d/m/Y H:i', strtotime($qi['created_at'])) ?><?= !empty($qi['processed_at']) ? ' · elaborato il ' . date('d/m H:i', strtotime($qi['processed_at'])) : '' ?><?= !empty($qi['scheduler_ref']) ? ' · Metricool: ' . e($qi['scheduler_ref']) : '' ?></span>
              <?php if ($qi['error_msg']): ?><span class="sqx-err"><?= e(mb_substr($qi['error_msg'], 0, 140)) ?></span><?php endif; ?>
              <span class="sqx-actions">
                <?php if ($isEditable): ?><button type="button" class="stc-act sq-suspend-btn" data-id="<?= (int) $qi['id'] ?>" data-suspended="<?= $isSuspended ? '1' : '0' ?>"><i class="fa-solid <?= $isSuspended ? 'fa-play' : 'fa-pause' ?>"></i> <span><?= $isSuspended ? 'Riattiva' : 'Sospendi' ?></span></button><?php endif; ?>
                <button type="button" class="stc-act danger sq-delete-btn" data-id="<?= (int) $qi['id'] ?>"><i class="fa-solid fa-xmark"></i> <span>Rimuovi</span></button>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="sqx-pager">
          <?php if ($page > 1): ?><a class="stc-act" href="?p=<?= $page - 1 ?><?= e($filtersQs) ?>">← Prec.</a><?php endif; ?>
          <span>Pagina <?= $page ?> di <?= $totalPages ?> (<?= $totalCount ?> elementi)</span>
          <?php if ($page < $totalPages): ?><a class="stc-act" href="?p=<?= $page + 1 ?><?= e($filtersQs) ?>">Succ. →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <dialog class="sq-dlg" id="sq-dlg">
    <div class="sq-dlg-in">
      <div class="sq-dlg-head"><strong id="sq-dlg-title"></strong><button type="button" class="sq-dlg-close" id="sq-dlg-x" aria-label="Chiudi">&times;</button></div>
      <div class="sq-dlg-media" id="sq-dlg-media"></div>
      <p class="sq-dlg-text" id="sq-dlg-text"></p>
      <div class="sq-dlg-meta" id="sq-dlg-meta"></div>
    </div>
  </dialog>
  <script src="/studio/assets/studio-queue.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-queue.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
