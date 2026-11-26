<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: stessi dati della pagina originale, parte visiva a grafici */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/fn_cruscotto.php';
$user = requireAdmin(); // strumento riservato agli amministratori del sito
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'cruscotto';
$pageTitle = 'Cruscotto';
$nav = array_column(getAllProfileNavigationMenu((int) $profile['id'], $profile['slug']), 'is_visible', 'name');
$data = cruscottoData($user, $profile, $nav);

$j = fn ($v) => e(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
$q = $data['queue']; $api = $data['api']; $mk = $data['marketing'];
$inboxTotal = array_sum(array_column($data['inbox']['now'], 'value'));
$inboxColors = ['messages' => 'accent', 'contacts' => 'info', 'inquiries' => 'warn', 'reservations' => 'pink'];
$gInbox = ['center' => (string) $inboxTotal, 'sub' => 'da leggere', 'empty' => 'Tutto letto', 'segments' => array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['value'], 'color' => $inboxColors[$r['key']] ?? 'mute'], $data['inbox']['now'])];
$inQueue = (int) (($q['status']['pending'] ?? 0) + ($q['status']['processing'] ?? 0));
$gQueue = ['center' => (string) $inQueue, 'sub' => 'in coda', 'empty' => $q['on'] ? 'Coda vuota' : 'Non attiva', 'segments' => [
    ['label' => 'In coda', 'value' => $inQueue, 'color' => 'info'],
    ['label' => 'Programmati', 'value' => (int) ($q['status']['scheduled'] ?? 0), 'color' => 'accent'],
    ['label' => 'Pubblicati', 'value' => (int) ($q['status']['done'] ?? 0), 'color' => 'ok'],
    ['label' => 'Errori', 'value' => (int) ($q['status']['failed'] ?? 0), 'color' => 'bad'],
    ['label' => 'Saltati', 'value' => (int) ($q['status']['skipped'] ?? 0), 'color' => 'mute']]];
$gApi = ['center' => (string) $api['calls'], 'sub' => 'chiamate 24 h', 'empty' => $api['tokens'] ? 'Nessuna chiamata' : 'Nessun token', 'segments' => [
    ['label' => 'Riuscite', 'value' => max(0, $api['calls'] - $api['errors']), 'color' => 'ok'],
    ['label' => 'Errori', 'value' => (int) $api['errors'], 'color' => 'bad']]];
$gMk = ['center' => (string) $mk['runs']['running'], 'sub' => 'in corso', 'empty' => 'Nessuna esecuzione', 'segments' => [
    ['label' => 'In corso', 'value' => (int) $mk['runs']['running'], 'color' => 'info'],
    ['label' => 'Completate', 'value' => (int) $mk['runs']['completed'], 'color' => 'ok'],
    ['label' => 'Interrotte', 'value' => (int) $mk['runs']['interrupted'], 'color' => 'warn']]];
$seriesColors = ['Messaggi' => 'accent', 'Contatti' => 'info', 'Richieste' => 'warn', 'Prenotazioni' => 'pink', 'In coda' => 'info', 'Programmati' => 'accent', 'Pubblicati' => 'ok', 'Errori' => 'bad'];
$mkSeries = fn (array $daily) => array_map(fn ($name, $vals) => ['name' => $name, 'color' => $seriesColors[$name] ?? 'mute', 'values' => $vals], array_keys($daily), array_values($daily));
$gQueueDaily = ['labels' => $data['days'], 'series' => $mkSeries($q['daily'])];
$gInboxDaily = ['labels' => $data['days'], 'series' => $mkSeries($data['inbox']['daily'])];
$gApiDaily = ['labels' => $data['days'], 'series' => [['name' => 'Chiamate', 'color' => 'accent', 'values' => $api['daily_calls']], ['name' => 'Errori', 'color' => 'bad', 'values' => $api['daily_errors']]]];
$gMkProjects = ['items' => array_map(fn ($p) => ['label' => '#' . (int) $p['id'] . ' ' . $p['title'], 'value' => (int) $p['n'], 'color' => 'accent', 'url' => '/dashboard_marketing.php?id=' . (int) $p['id']], $mk['projects'])];
$groups = [];
foreach ($data['links'] as $l) { $groups[$l['group']][] = $l; }

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx crx">
    <div class="crx-gauges">
      <section class="crx-card">
        <header><i class="fa-solid fa-inbox"></i><h3>Messaggistica</h3><a href="/dashboard_inbox.php">Apri <i class="fa-solid fa-arrow-right"></i></a></header>
        <div class="crx-chart" data-chart="donut" data-config='<?= $j($gInbox) ?>'></div>
      </section>
      <section class="crx-card">
        <header><i class="fa-solid fa-share-nodes"></i><h3>Coda Social <small>30 giorni</small></h3><a href="/dashboard_social_queue.php?status=all">Apri <i class="fa-solid fa-arrow-right"></i></a></header>
        <div class="crx-chart" data-chart="donut" data-config='<?= $j($gQueue) ?>'></div>
      </section>
      <section class="crx-card">
        <header><i class="fa-solid fa-robot"></i><h3>API e MCP <small>24 ore</small></h3><a href="/dashboard_api_tokens.php">Apri <i class="fa-solid fa-arrow-right"></i></a></header>
        <div class="crx-chart" data-chart="donut" data-config='<?= $j($gApi) ?>'></div>
      </section>
      <section class="crx-card">
        <header><i class="fa-solid fa-bullhorn"></i><h3>Marketing <small>30 giorni</small></h3><a href="/dashboard_marketing.php">Apri <i class="fa-solid fa-arrow-right"></i></a></header>
        <div class="crx-chart" data-chart="donut" data-config='<?= $j($gMk) ?>'></div>
      </section>
    </div>

    <div class="crx-wide">
      <section class="crx-card">
        <header><i class="fa-solid fa-chart-column"></i><h3>Coda Social <small>ultimi 14 giorni</small></h3></header>
        <div class="crx-chart" data-chart="stack" data-config='<?= $j($gQueueDaily) ?>'></div>
      </section>
      <section class="crx-card">
        <header><i class="fa-solid fa-chart-column"></i><h3>Messaggi in arrivo <small>ultimi 14 giorni</small></h3></header>
        <div class="crx-chart" data-chart="stack" data-config='<?= $j($gInboxDaily) ?>'></div>
      </section>
    </div>

    <div class="crx-wide">
      <section class="crx-card">
        <header><i class="fa-solid fa-chart-line"></i><h3>Chiamate API <small>ultimi 14 giorni</small></h3></header>
        <div class="crx-chart" data-chart="line" data-config='<?= $j($gApiDaily) ?>'></div>
        <?php if ($api['tokens']): ?>
        <ul class="crx-tokens">
          <?php foreach ($api['tokens'] as $tk): ?><li><span><?= e($tk['mcp_name'] ?: $tk['label']) ?></span><small><?= e(dashRelTime($tk['last_used_at'])) ?></small></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>
      <section class="crx-card">
        <header><i class="fa-solid fa-chart-simple"></i><h3>Marketing <small>esecuzioni per progetto</small></h3></header>
        <?php if ($mk['projects']): ?><div class="crx-chart" data-chart="hbars" data-config='<?= $j($gMkProjects) ?>'></div>
        <?php else: ?><p class="crx-empty">Nessun progetto ancora.</p><?php endif; ?>
      </section>
    </div>

    <div class="tlx-listhead"><h2>Tutte le sezioni</h2></div>
    <div class="crx-links">
      <?php foreach ($groups as $gname => $items): ?>
        <div class="crx-group"><h4><?= e($gname) ?></h4>
          <div class="crx-tiles">
          <?php foreach ($items as $l): ?>
            <a class="crx-tile" href="<?= e($l['url']) ?>">
              <span class="ic"><i class="<?= e($l['icon']) ?>"></i></span>
              <b><?= e($l['label']) ?></b>
              <?php if ($l['badge'] > 0): ?><span class="crx-badge <?= e($l['tone']) ?>"><?= (int) $l['badge'] ?></span><?php endif; ?>
              <?php if ($l['on'] !== null): ?><span class="crx-dot <?= $l['on'] ? 'on' : 'off' ?>" title="<?= $l['on'] ? 'Attivo' : 'Non attivo' ?>"></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <script src="/studio/assets/studio-cruscotto.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-cruscotto.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
