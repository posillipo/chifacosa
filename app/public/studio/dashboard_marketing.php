<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/marketing.php';
$user = requireAdmin(); // strumento riservato agli amministratori del sito
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'marketing';
$pageTitle = 'Marketing';
$pageHelpInline = true; // ha già il proprio ❓ accanto al titolo
$uid = (int) $profile['id'];
$error = null;

const MKT_URL = '/dashboard_marketing.php';
function mktUrl(int $pid = 0, int $run = 0, string $extra = ''): string {
    $q = [];
    if ($pid) { $q[] = 'id=' . $pid; }
    if ($run) { $q[] = 'run=' . $run; }
    return MKT_URL . ($q ? '?' . implode('&', $q) : '') . $extra;
}
function mktWho(?string $a): string { return $a === 'ai' ? 'IA' : 'Utente'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $pid = (int) ($_POST['project_id'] ?? 0);
    $sid = (int) ($_POST['step_id'] ?? 0);
    $goRun = 0;
    // Dopo un'azione si torna alla scheda in cui è stata fatta.
    $tabAfter = in_array($action, ['add_step', 'add_final', 'update_step', 'delete_step'], true) ? 'istruzioni'
        : (in_array($action, ['update_project', 'archive_project', 'unarchive_project'], true) ? 'impostazioni' : '');
    try {
        if ($action === 'create_project') {
            $r = marketingCreateProject($uid, (string) ($_POST['title'] ?? ''), (string) ($_POST['description'] ?? ''), 'user');
            if (isset($r['error'])) { $error = $r['error']; } else { header('Location: ' . mktUrl($r['id'])); exit; }
        } elseif ($action === 'update_project') {
            $error = marketingUpdateProject($uid, $pid, ['title' => $_POST['title'] ?? '', 'description' => $_POST['description'] ?? '']);
        } elseif ($action === 'set_active') {
            $error = marketingUpdateProject($uid, $pid, ['active' => !empty($_POST['active'])]);
        } elseif ($action === 'archive_project' || $action === 'unarchive_project') {
            $error = marketingUpdateProject($uid, $pid, ['archived' => $action === 'archive_project']);
        } elseif ($action === 'delete_project') {
            marketingDeleteProject($uid, $pid);
            header('Location: ' . MKT_URL);
            exit;
        } elseif ($action === 'add_step' || $action === 'add_final') {
            $r = marketingAddStep($uid, $pid, (string) ($_POST['title'] ?? ''), (string) ($_POST['instructions'] ?? ''), $action === 'add_final', 'user');
            $error = $r['error'] ?? null;
        } elseif ($action === 'update_step') {
            $error = marketingUpdateStep($uid, $sid, ['title' => $_POST['title'] ?? '', 'instructions' => $_POST['instructions'] ?? '']);
        } elseif ($action === 'delete_step') {
            $error = marketingDeleteStep($uid, $sid);
        } elseif ($action === 'start_run') {
            $r = marketingStartRun($uid, $pid, 'user');
            if (isset($r['error'])) { $error = $r['error']; } else { $goRun = (int) $r['run_id']; }
        } elseif ($action === 'complete_step') {
            $error = marketingCompleteStep($uid, $sid, (string) ($_POST['note'] ?? ''), 'user');
            $open = marketingOpenRun($uid, $pid);
            $goRun = $open ? (int) $open['id'] : (int) ($_POST['run_id'] ?? 0);
        } elseif ($action === 'add_note') {
            $error = marketingAddNote($uid, $pid, (string) ($_POST['text'] ?? ''), $sid ?: null, 'user');
            $goRun = (int) ($_POST['run_id'] ?? 0);
        } elseif ($action === 'interrupt_run') {
            $error = marketingInterruptRun($uid, $pid, (string) ($_POST['reason'] ?? ''), 'user');
            $goRun = (int) ($_POST['run_id'] ?? 0);
        }
    } catch (PDOException $ex) {
        $error = in_array($ex->getCode(), ['42S02', '42S22'], true) ? 'Il modulo Marketing non è aggiornato su questo database: applicare la migrazione 2026_11_22_marketing_runs.sql.' : 'Errore durante il salvataggio.';
        error_log('dashboard_marketing: ' . $ex->getMessage());
    }
    if ($error === null) {
        $u = mktUrl($pid, $goRun);
        if ($tabAfter && !$goRun) { $u .= (strpos($u, '?') === false ? '?' : '&') . 'tab=' . $tabAfter; }
        header('Location: ' . $u);
        exit;
    }
    if ($tabAfter) { $_GET['tab'] = $tabAfter; }
    $_GET['id'] = $pid; // in caso di errore resta sulla stessa pagina
    if (!empty($_POST['run_id'])) { $_GET['run'] = (int) $_POST['run_id']; }
}

$detailId = (int) ($_GET['id'] ?? 0);
$project = null;
$runs = [];
$selRun = null;
$openRun = null;
$projects = [];
$counts = ['active' => 0, 'inactive' => 0, 'archived' => 0];
$stato = 'active';
try {
    if ($detailId) {
        $project = marketingGetProject($uid, $detailId);
        if ($project) {
            $runs = marketingListRuns($uid, $detailId, 100);
            $openRun = marketingOpenRun($uid, $detailId);
            $wantRun = (int) ($_GET['run'] ?? 0);
            if ($wantRun) {
                $selRun = marketingGetRun($uid, $wantRun);
                if ($selRun && (int) $selRun['project_id'] !== $detailId) { $selRun = null; }
            } elseif ($openRun && !isset($_GET['tab'])) {
                // Atterrando sul progetto con un'esecuzione in corso si apre direttamente quella.
                $selRun = marketingGetRun($uid, (int) $openRun['id']);
            }
        }
    }
    if (!$project) {
        $stato = $_GET['stato'] ?? 'active';
        if (!in_array($stato, ['active', 'inactive', 'archived', 'all'], true)) { $stato = 'active'; }
        $all = marketingListProjects($uid, true);
        foreach ($all as $p0) {
            $k = $p0['status'] === 'archived' ? 'archived' : ($p0['is_active'] ? 'active' : 'inactive');
            $counts[$k]++;
        }
        $projects = array_values(array_filter($all, function ($p) use ($stato) {
            $k = $p['status'] === 'archived' ? 'archived' : ($p['is_active'] ? 'active' : 'inactive');
            return $stato === 'all' || $k === $stato;
        }));
    }
} catch (Throwable $ex) {
    error_log('dashboard_marketing: ' . $ex->getMessage());
    $project = null;
    $projects = [];
    $error = $error ?? 'Il modulo Marketing non è aggiornato su questo database: applicare la migrazione 2026_11_22_marketing_runs.sql.';
}
if ($detailId && !$project && !$error) {
    $error = 'Progetto non trovato.';
}

$tab = $_GET['tab'] ?? '';
if (!in_array($tab, ['cronologia', 'istruzioni', 'impostazioni'], true)) {
    // Si apre dove c'è qualcosa da vedere: la cronologia se il progetto è già stato usato, altrimenti le istruzioni.
    $tab = $runs ? 'cronologia' : 'istruzioni';
}
if ($selRun) { $tab = 'cronologia'; }

include __DIR__ . '/../_dash_header.php';
?>
  <style>
  .container { max-width: 1240px; }
  .dash-two-col { display: block; }
  .dash-two-col > div { min-width: 0; }
  @media (min-width: 760px) {
    .dash-two-col { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.6fr); gap: 20px; align-items: start; }
  }
  .mkt-badge { font-size: 11.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; color: #fff; white-space: nowrap; }
  .mkt-id { display: inline-block; background: #ecebf8; color: #4b3fc4; border-radius: 6px; padding: 1px 7px; font-size: 12px; font-weight: 700; white-space: nowrap; }
  .mkt-instr { white-space: pre-wrap; margin: 4px 0 6px; font-size: 14px; overflow-wrap: anywhere; }
  .mkt-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; align-items: center; }
  .mkt-actions form { display: inline; }
  .mkt-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 12px; }
  .mkt-tabs a { border: 1px solid var(--border, #d6d6e2); border-radius: 999px; padding: 4px 12px; font-size: 13px; text-decoration: none; color: inherit; }
  .mkt-tabs a.on { background: var(--accent); color: var(--accent-text, #fff); border-color: var(--accent); font-weight: 700; }
  .mkt-tools { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
  .mkt-tools input { margin: 0; flex: 1; min-width: 160px; }
  .mkt-row-link { display: block; text-decoration: none; color: inherit; border: 1px solid var(--border, #e5e5ee); border-radius: 12px; margin-bottom: 10px; padding: 12px 14px; background: var(--card-bg, #fff); }
  .mkt-row-link:hover { border-color: var(--accent); }
  .mkt-line { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .mkt-line .mkt-name { flex: 1; min-width: 0; font-weight: 700; overflow-wrap: anywhere; }
  .mkt-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 5px; }
  /* pagina del progetto: testata + schede separate (Cronologia / Istruzioni / Impostazioni) */
  .mkt-head { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap; justify-content: space-between; margin-bottom: 4px; }
  .mkt-head h2 { margin: 0; font-size: 22px; overflow-wrap: anywhere; }
  .mkt-switch { display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--text-muted); }
  .mkt-switch button { position: relative; width: 42px; height: 24px; border-radius: 999px; border: 0; cursor: pointer; background: #c9c9d6; padding: 0; }
  .mkt-switch button::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: left .15s; }
  .mkt-switch.on button { background: var(--accent); }
  .mkt-switch.on button::after { left: 21px; }
  .mkt-ptabs { display: flex; gap: 4px; border-bottom: 2px solid #e5e5ee; margin: 14px 0 18px; overflow-x: auto; }
  .mkt-ptabs a { padding: 9px 16px; text-decoration: none; color: var(--text-muted); font-weight: 600; font-size: 14.5px; border-bottom: 3px solid transparent; margin-bottom: -2px; white-space: nowrap; }
  .mkt-ptabs a.on { color: var(--accent); border-bottom-color: var(--accent); }
  .mkt-ptabs small { font-weight: 400; margin-left: 4px; }
  .mkt-panel { max-width: 860px; }
  .mkt-h { font-size: 14px; font-weight: 700; margin: 4px 0 12px; }
  .mkt-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); }
  .mkt-run-meta { font-size: 13px; color: var(--text-muted); white-space: nowrap; }
  .mkt-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; display: inline-block; }
  .mkt-tag { font-size: 12px; color: var(--text-muted); }
  /* elenco esecuzioni */
  .mkt-runs { border: 1px solid #e5e5ee; border-radius: 12px; overflow: hidden; background: var(--card-bg, #fff); }
  .mkt-runrow { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 13px 16px; text-decoration: none; color: inherit; border-bottom: 1px solid #eee; }
  .mkt-runrow:last-child { border-bottom: 0; }
  .mkt-runrow:hover { background: rgba(108,92,231,.05); }
  .mkt-runrow .when { font-weight: 600; }
  .mkt-runrow .meta { margin-left: auto; display: flex; gap: 12px; align-items: center; font-size: 13px; color: var(--text-muted); white-space: nowrap; }
  .mkt-minibar { width: 70px; height: 6px; border-radius: 999px; background: #e5e5ee; overflow: hidden; }
  .mkt-minibar > i { display: block; height: 100%; background: #2e9e5b; }
  /* pagina di una esecuzione */
  .mkt-runhead { border: 1px solid #e5e5ee; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; background: var(--card-bg, #fff); }
  .mkt-stepper { display: flex; gap: 0; align-items: center; margin-top: 12px; flex-wrap: wrap; row-gap: 8px; }
  .mkt-stepper .st { display: flex; align-items: center; }
  .mkt-stepper .st b { width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; background: #e5e5ee; color: #666; }
  .mkt-stepper .st.done b { background: #2e9e5b; color: #fff; }
  .mkt-stepper .st.next b { background: var(--accent); color: #fff; }
  .mkt-stepper .st i { width: 22px; height: 2px; background: #e5e5ee; display: inline-block; }
  .mkt-stepper .st.done i { background: #2e9e5b; }
  .mkt-timeline { border-left: 3px solid #e5e5ee; margin-left: 6px; padding-left: 0; }
  .mkt-ev { position: relative; padding: 0 0 18px 20px; }
  .mkt-ev::before { content: ''; position: absolute; left: -8px; top: 3px; width: 13px; height: 13px; border-radius: 50%; background: #c9c9d6; border: 2px solid #fff; }
  .mkt-ev.k-step_done::before, .mkt-ev.k-finished::before { background: #2e9e5b; }
  .mkt-ev.k-note::before { background: #6c5ce7; }
  .mkt-ev.k-interrupted::before { background: #e08a1e; }
  .mkt-ev .top { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
  .mkt-ev .what { font-weight: 700; font-size: 14px; }
  .mkt-ev time { font-size: 12.5px; color: var(--text-muted); }
  .mkt-ev .stepname { font-size: 13px; color: var(--text-muted); }
  .mkt-ev .body { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 14px; margin-top: 4px; background: #f7f7fb; border-radius: 8px; padding: 8px 10px; }
  .mkt-ev .body.long { max-height: 7.2em; overflow: hidden; position: relative; }
  .mkt-ev details.more summary { cursor: pointer; font-size: 12.5px; color: var(--accent); margin-top: 3px; }
  .mkt-now { background: rgba(108,92,231,.07); border: 1px solid rgba(108,92,231,.25); border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; }
  /* istruzioni (passi) */
  .mkt-stepcard { border: 1px solid #e5e5ee; border-radius: 12px; margin-bottom: 10px; background: var(--card-bg, #fff); }
  .mkt-stepcard > summary { list-style: none; cursor: pointer; padding: 12px 16px; display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
  .mkt-stepcard > summary::-webkit-details-marker { display: none; }
  .mkt-stepcard[open] > summary { border-bottom: 1px solid #eee; }
  .mkt-stepcard .num { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); min-width: 56px; }
  .mkt-stepcard .ttl { font-weight: 700; flex: 1; min-width: 0; overflow-wrap: anywhere; }
  .mkt-stepcard.final { border-color: var(--accent); }
  .mkt-stepbody { padding: 10px 16px 14px; }
  .mkt-danger { border: 1px solid #f0c4c4; border-radius: 12px; padding: 14px 16px; margin-top: 16px; }
  </style>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="section-title" style="display:flex;align-items:center;gap:8px;">
    Marketing
    <button type="button" id="mkt-help-btn" title="Come funziona" aria-label="Come funziona" style="border:0;background:none;cursor:pointer;font-size:20px;line-height:1;padding:0;">❓</button>
  </div>
  <dialog id="mkt-help" style="max-width:600px;width:calc(100% - 32px);border:0;border-radius:14px;padding:20px 22px;box-shadow:0 8px 40px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;">
      <strong style="font-size:17px;">Come funziona Marketing</strong>
      <button type="button" id="mkt-help-close" aria-label="Chiudi" style="border:0;background:none;cursor:pointer;font-size:24px;line-height:1;">&times;</button>
    </div>
    <p style="margin:0 0 10px;">Un <strong>progetto</strong> è un modello riutilizzabile: una serie di passi — Passo 1, Passo 2, Passo 3… — chiusa dal <strong>Finale</strong>. Ogni passo ha un titolo e le istruzioni, per te o per un'intelligenza artificiale. Aggiungi i passi che servono e premi <em>Inserisci Finale</em> per l'ultimo.</p>
    <p style="margin:0 0 10px;">Ogni volta che il progetto viene usato (<strong>Esegui ora</strong>, oppure un'IA via MCP) nasce un'<strong>esecuzione</strong>: i passi si completano in ordine e la <strong>Cronologia</strong> registra giorno e ora, chi (Utente o IA) e cosa è stato fatto. Completando il Finale l'esecuzione è conclusa e il progetto si può riusare.</p>
    <p style="margin:0 0 10px;">L'interruttore <strong>Attivo</strong> decide se il progetto si può usare: spento, nessuno può avviare esecuzioni.</p>
    <p style="margin:0 0 10px;">Ogni progetto ha un <strong>ID</strong> (per esempio <code>ID 12</code>): è lo stesso numero che le IA usano, quindi puoi passarlo a un'altra IA per farle continuare il lavoro.</p>
    <div style="font-weight:700;margin:0 0 6px;">Strumenti MCP disponibili alle IA</div>
    <ul style="margin:0;padding-left:18px;font-size:14px;line-height:1.55;">
      <li><code>list_marketing_projects</code> — elenca i progetti attivi e l'esecuzione in corso</li>
      <li><code>get_marketing_project</code> — legge un progetto (per ID) con passi ed esecuzioni</li>
      <li><code>create_marketing_project</code> — crea un progetto, con passi e Finale</li>
      <li><code>update_marketing_project</code> — titolo, descrizione, archivia, interruttore Attivo</li>
      <li><code>add_marketing_step</code> — aggiunge un passo (o il Finale)</li>
      <li><code>update_marketing_step</code> — cambia titolo e istruzioni di un passo</li>
      <li><code>start_marketing_run</code> — avvia (o riprende) un'esecuzione</li>
      <li><code>add_marketing_note</code> — scrive una nota nella cronologia</li>
      <li><code>complete_marketing_step</code> — completa il passo da fare, con una nota</li>
      <li><code>interrupt_marketing_run</code> — interrompe l'esecuzione, con il motivo</li>
      <li><code>list_marketing_runs</code> — elenca le esecuzioni di un progetto</li>
      <li><code>get_marketing_run</code> — legge una esecuzione con la cronologia completa</li>
    </ul>
    <p style="margin:10px 0 0;font-size:13px;color:#666;">Le IA non possono eliminare progetti o passi: l'eliminazione si fa solo da qui.</p>
  </dialog>
  <script>
  (function () {
    var d = document.getElementById('mkt-help'), b = document.getElementById('mkt-help-btn'), c = document.getElementById('mkt-help-close');
    if (!d || !b) return;
    b.addEventListener('click', function () { if (d.showModal) d.showModal(); else d.setAttribute('open', ''); });
    c.addEventListener('click', function () { d.close ? d.close() : d.removeAttribute('open'); });
    d.addEventListener('click', function (e) { if (e.target === d && d.close) d.close(); });
  })();
  </script>

<?php if ($project): /* ============================ PAGINA DI UN PROGETTO ============================ */
    $steps = $project['steps'];
    $isArch = $project['status'] === 'archived';
    $hasFinal = false;
    foreach ($steps as $s) { $hasFinal = $hasFinal || (int) $s['is_final']; }
    $dotColor = ['running' => '#2d7ff9', 'completed' => '#2e9e5b', 'interrupted' => '#e08a1e'];
    $stLabel = ['running' => 'In corso', 'completed' => 'Completata', 'interrupted' => 'Interrotta'];
    $tabUrl = fn (string $t) => mktUrl((int) $project['id']) . (strpos(mktUrl((int) $project['id']), '?') === false ? '?' : '&') . 'tab=' . $t;
?>
  <p style="margin:0 0 8px;"><a href="<?= MKT_URL ?>">← Tutti i progetti</a></p>

  <div class="mkt-head">
    <div style="min-width:0;flex:1;">
      <h2><span class="mkt-id">ID <?= (int) $project['id'] ?></span> <?= e($project['title']) ?></h2>
      <div class="mkt-sub" style="margin-top:6px;">
        <?php if ($isArch): ?><span class="mkt-badge" style="background:#6c757d;">Archiviato</span> <?php endif; ?>
        <?php if ($project['created_by'] === 'ai'): ?><span class="mkt-badge" style="background:#0d9488;" title="Creato da un'intelligenza artificiale tramite MCP">IA</span> <?php endif; ?>
        <?php if ($runs): ?>Ultima esecuzione: <?= e(formatLocalDateTime($runs[0]['started_at'], $profile, 'd/m/Y H:i')) ?> · <?= e($stLabel[$runs[0]['status']]) ?><?php else: ?>Mai eseguito<?php endif; ?>
      </div>
    </div>
    <?php if (!$isArch): ?>
    <div class="mkt-actions" style="margin:0;">
      <form method="post" class="mkt-switch<?= $project['is_active'] ? ' on' : '' ?>" title="Se spento, il progetto non si può usare">
        <?= csrfField() ?><input type="hidden" name="action" value="set_active"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <?php if (!$project['is_active']): ?><input type="hidden" name="active" value="1"><?php endif; ?>
        <span><?= $project['is_active'] ? 'Attivo' : 'Disattivato' ?></span>
        <button type="submit" aria-label="<?= $project['is_active'] ? 'Disattiva' : 'Attiva' ?> il progetto"></button>
      </form>
      <?php if ($project['is_active'] && $steps && !$openRun): ?>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="start_run"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <button class="btn" type="submit">▶ Esegui ora</button>
      </form>
      <?php elseif ($openRun): ?>
        <a class="btn" href="<?= e(mktUrl((int) $project['id'], (int) $openRun['id'])) ?>">▶ Esecuzione in corso</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <nav class="mkt-ptabs" aria-label="Sezioni del progetto">
    <a href="<?= e($tabUrl('cronologia')) ?>" class="<?= $tab === 'cronologia' ? 'on' : '' ?>">Cronologia <small>(<?= count($runs) ?>)</small></a>
    <a href="<?= e($tabUrl('istruzioni')) ?>" class="<?= $tab === 'istruzioni' ? 'on' : '' ?>">Istruzioni <small>(<?= count($steps) ?> passi)</small></a>
    <a href="<?= e($tabUrl('impostazioni')) ?>" class="<?= $tab === 'impostazioni' ? 'on' : '' ?>">Impostazioni</a>
  </nav>

  <div class="mkt-panel">
  <?php if ($tab === 'cronologia' && $selRun): /* ---------- una esecuzione ---------- */
      $sr = $selRun;
      $srTotal = $sr['status'] === 'running' ? count($steps) : max((int) $sr['steps_total'], count($sr['done']));
      $dur = '';
      if ($sr['finished_at']) {
          $sec = max(0, strtotime($sr['finished_at']) - strtotime($sr['started_at']));
          $dur = $sec < 60 ? $sec . ' s' : ($sec < 3600 ? floor($sec / 60) . ' min' : floor($sec / 3600) . ' h ' . floor(($sec % 3600) / 60) . ' min');
      }
  ?>
    <p style="margin:0 0 10px;"><a href="<?= e($tabUrl('cronologia')) ?>">← Tutte le esecuzioni</a></p>
    <div class="mkt-runhead">
      <div class="mkt-line">
        <span class="mkt-name" style="font-size:17px;"><?= e(formatLocalDateTime($sr['started_at'], $profile, 'd/m/Y \a\l\l\e H:i')) ?></span>
        <span class="mkt-tag">avviata da <strong><?= e(mktWho($sr['started_by'])) ?></strong></span>
        <span class="mkt-run-meta" style="margin-left:auto;"><span class="mkt-dot" style="background:<?= e($dotColor[$sr['status']]) ?>;" aria-hidden="true"></span> <strong><?= e($stLabel[$sr['status']]) ?></strong> · <?= count($sr['done']) ?>/<?= (int) $srTotal ?> passi<?= $dur ? ' · ' . e($dur) : '' ?></span>
      </div>
      <?php if ($steps): ?>
      <div class="mkt-stepper" aria-label="Avanzamento">
        <?php $nextId = $sr['next_step'] ? (int) $sr['next_step']['id'] : 0; $nSt = count($steps); foreach ($steps as $k => $s2):
            $isD = isset($sr['done'][(int) $s2['id']]); ?>
          <span class="st<?= $isD ? ' done' : '' ?><?= (int) $s2['id'] === $nextId ? ' next' : '' ?>" title="<?= e(marketingStepLabel($steps, (int) $s2['id']) . ' — ' . $s2['title']) ?>"><b><?= (int) $s2['is_final'] ? '🏁' : ($k + 1) ?></b><?php if ($k < $nSt - 1): ?><i></i><?php endif; ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($sr['status'] === 'running' && !$isArch): ?>
    <div class="mkt-now">
      <?php if ($sr['next_step']): $ns = $sr['next_step']; ?>
        <div class="mkt-label">▶ Da fare adesso · <?= e(marketingStepLabel($steps, (int) $ns['id'])) ?></div>
        <div style="font-weight:700;font-size:15.5px;margin:2px 0 4px;"><?= e($ns['title']) ?></div>
        <?php if ($ns['instructions']): ?>
          <details class="mkt-instr-more"><summary style="cursor:pointer;font-size:13px;color:var(--accent);">Mostra le istruzioni del passo</summary><div class="mkt-instr"><?= e($ns['instructions']) ?></div></details>
        <?php endif; ?>
        <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:8px;">
          <?= csrfField() ?><input type="hidden" name="action" value="complete_step"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $ns['id'] ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
          <input type="text" name="note" maxlength="2000" placeholder="Nota (facoltativa): cosa è stato fatto" style="margin:0;flex:1;min-width:200px;">
          <button class="btn small" type="submit">✔ Segna completato</button>
        </form>
      <?php else: ?>
        <div style="font-size:14px;">Tutti i passi sono completati: manca solo il <strong>Finale</strong>. Inseriscilo dalla scheda <a href="<?= e($tabUrl('istruzioni')) ?>">Istruzioni</a> per poter concludere.</div>
      <?php endif; ?>
      <div class="mkt-actions" style="margin-top:10px;">
        <details><summary class="btn small secondary" style="display:inline-block;cursor:pointer;">📝 Aggiungi una nota</summary>
          <form method="post" style="margin-top:8px;">
            <?= csrfField() ?><input type="hidden" name="action" value="add_note"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
            <textarea name="text" rows="2" required placeholder="Cosa hai fatto o deciso"></textarea>
            <button class="btn small" type="submit">Aggiungi alla cronologia</button>
          </form>
        </details>
        <form method="post" onsubmit="return confirm('Interrompere questa esecuzione? Non si potrà più proseguire: potrai avviarne una nuova.');">
          <?= csrfField() ?><input type="hidden" name="action" value="interrupt_run"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
          <input type="text" name="reason" maxlength="500" placeholder="Motivo (facoltativo)" style="margin:0 6px 0 0;width:auto;min-width:150px;display:inline-block;">
          <button class="btn small danger" type="submit">⏹ Interrompi</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="mkt-h">Cosa è successo</div>
    <div class="mkt-timeline">
      <?php foreach ($sr['events'] as $ev):
        $what = ['started' => 'Esecuzione avviata', 'note' => 'Nota', 'step_done' => 'Passo completato', 'finished' => 'Esecuzione completata', 'interrupted' => 'Esecuzione interrotta'][$ev['kind']] ?? $ev['kind'];
        $body = (string) ($ev['body'] ?? '');
        $isLong = mb_strlen($body) > 320;
      ?>
      <div class="mkt-ev k-<?= e($ev['kind']) ?>">
        <div class="top"><span class="what"><?= e($what) ?></span><span class="mkt-tag">· <?= e(mktWho($ev['actor'])) ?></span><time><?= e(formatLocalDateTime($ev['created_at'], $profile, 'd/m/Y H:i')) ?></time></div>
        <?php if ($ev['step_label']): ?><div class="stepname"><?= e($ev['step_label']) ?></div><?php endif; ?>
        <?php if ($body !== '' && $ev['kind'] !== 'finished'): ?>
          <?php if ($isLong): ?>
            <div class="body"><?= e(mb_strimwidth($body, 0, 320, '…')) ?></div>
            <details class="more"><summary>Mostra il testo completo</summary><div class="body"><?= e($body) ?></div></details>
          <?php else: ?>
            <div class="body"><?= e($body) ?></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

  <?php elseif ($tab === 'cronologia'): /* ---------- elenco esecuzioni ---------- */ ?>
    <?php if (!$runs): ?>
      <div class="card" style="color:var(--text-muted);">Nessuna esecuzione ancora.<?= $project['is_active'] && $steps && !$isArch ? ' Premi <strong>Esegui ora</strong> per avviarne una, oppure lascia che lo faccia un\'IA.' : ($steps ? '' : ' Aggiungi prima i passi nella scheda <a href="' . e($tabUrl('istruzioni')) . '">Istruzioni</a>.') ?></div>
    <?php else: ?>
      <div class="mkt-runs">
      <?php foreach ($runs as $r):
          $tot = $r['status'] === 'running' ? count($steps) : max((int) $r['steps_total'], (int) $r['done_count']);
          $pctR = $tot ? (int) round(((int) $r['done_count']) / $tot * 100) : 0;
      ?>
        <a class="mkt-runrow" href="<?= e(mktUrl((int) $project['id'], (int) $r['id'])) ?>">
          <span class="mkt-dot" style="background:<?= e($dotColor[$r['status']]) ?>;" aria-hidden="true"></span>
          <span class="when"><?= e(formatLocalDateTime($r['started_at'], $profile, 'd/m/Y \a\l\l\e H:i')) ?></span>
          <span class="mkt-tag"><?= e(mktWho($r['started_by'])) ?></span>
          <span class="meta"><span><?= e($stLabel[$r['status']]) ?></span><span class="mkt-minibar" aria-hidden="true"><i style="width:<?= $pctR ?>%;"></i></span><span><?= (int) $r['done_count'] ?>/<?= (int) $tot ?></span><span aria-hidden="true">›</span></span>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'istruzioni'): /* ---------- passi (modello) ---------- */ ?>
    <?php if ($project['description']): ?><div class="card"><div class="mkt-label">Obiettivo</div><p class="mkt-instr" style="margin:4px 0 0;"><?= e($project['description']) ?></p></div><?php endif; ?>
    <?php if (!$steps): ?><p style="color:var(--text-muted);font-size:14px;">Nessun passo ancora: aggiungi il Passo 1 qui sotto.</p><?php endif; ?>
    <?php foreach ($steps as $s):
      $label = marketingStepLabel($steps, (int) $s['id']);
    ?>
    <details class="mkt-stepcard<?= (int) $s['is_final'] ? ' final' : '' ?>"<?= count($steps) <= 2 ? ' open' : '' ?>>
      <summary><span class="num"><?= e($label) ?></span><span class="ttl"><?= e($s['title']) ?></span></summary>
      <div class="mkt-stepbody">
        <?php if ($s['instructions']): ?><div class="mkt-instr" style="margin-top:0;"><?= e($s['instructions']) ?></div><?php else: ?><p style="color:var(--text-muted);font-size:13.5px;margin:0;">Nessuna istruzione.</p><?php endif; ?>
        <?php if (!$isArch): ?>
        <div class="mkt-actions">
          <details style="display:inline-block;">
            <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica</summary>
            <form method="post" style="margin-top:8px;">
              <?= csrfField() ?><input type="hidden" name="action" value="update_step"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
              <input type="text" name="title" maxlength="150" required value="<?= e($s['title']) ?>">
              <textarea name="instructions" rows="6"><?= e($s['instructions'] ?? '') ?></textarea>
              <button class="btn small" type="submit">Salva passo</button>
            </form>
          </details>
          <form method="post" onsubmit="return confirm('Eliminare questo passo? Le cronologie passate restano leggibili.');">
            <?= csrfField() ?><input type="hidden" name="action" value="delete_step"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </details>
    <?php endforeach; ?>

    <?php if (!$isArch): ?>
      <?php if (!$hasFinal): ?>
      <div class="card">
        <strong>➕ Aggiungi un passo</strong>
        <form method="post" style="margin-top:6px;">
          <?= csrfField() ?><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
          <label>Titolo del <?= $steps ? 'passo ' . (count($steps) + 1) : 'Passo 1' ?></label>
          <input type="text" name="title" maxlength="150" required>
          <label>Istruzioni (per te o per l'IA)</label>
          <textarea name="instructions" rows="4" placeholder="Cosa va fatto in questo passo, con quali contenuti, quando…"></textarea>
          <button class="btn" type="submit" name="action" value="add_step">Aggiungi passo</button>
          <button class="btn secondary" type="submit" name="action" value="add_final" title="Aggiunge l'ultimo passo e chiude il progetto">🏁 Inserisci Finale</button>
        </form>
      </div>
      <?php else: ?>
        <p style="color:var(--text-muted);font-size:12.5px;">Il Finale è inserito: per aggiungere altri passi elimina prima il Finale.</p>
      <?php endif; ?>
    <?php endif; ?>

  <?php else: /* ---------- impostazioni ---------- */ ?>
    <?php if (!$isArch): ?>
    <form method="post" class="card">
      <?= csrfField() ?><input type="hidden" name="action" value="update_project"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
      <strong>Dati del progetto</strong>
      <label>Titolo</label>
      <input type="text" name="title" maxlength="150" required value="<?= e($project['title']) ?>">
      <label>Obiettivo / descrizione</label>
      <textarea name="description" rows="5"><?= e($project['description'] ?? '') ?></textarea>
      <button class="btn" type="submit">Salva</button>
    </form>
    <?php endif; ?>
    <div class="card">
      <strong>Archivio</strong>
      <p style="color:var(--text-muted);font-size:13.5px;margin:6px 0 10px;"><?= $isArch ? 'Il progetto è archiviato: non si può usare finché non lo ripristini. La cronologia resta consultabile.' : 'Archiviare toglie il progetto dall\'elenco principale e interrompe l\'eventuale esecuzione in corso. La cronologia resta consultabile e puoi ripristinarlo.' ?></p>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="<?= $isArch ? 'unarchive_project' : 'archive_project' ?>"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <button class="btn secondary" type="submit"><?= $isArch ? 'Ripristina il progetto' : 'Archivia il progetto' ?></button>
      </form>
    </div>
    <div class="mkt-danger">
      <strong>Zona pericolosa</strong>
      <p style="color:var(--text-muted);font-size:13.5px;margin:6px 0 10px;">Elimina il progetto con tutti i passi e tutta la cronologia. Non si può annullare.</p>
      <form method="post" onsubmit="return confirm('Eliminare il progetto con tutti i passi e tutta la cronologia? Non si può annullare.');">
        <?= csrfField() ?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <button class="btn danger" type="submit">Elimina progetto</button>
      </form>
    </div>
  <?php endif; ?>
  </div>

<?php else: /* ============================ ELENCO DEI PROGETTI ============================ */ ?>
  <div class="dash-two-col">
  <div>
    <form method="post" class="card">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_project">
      <strong>Nuovo progetto</strong>
      <label>Titolo del progetto</label>
      <input type="text" name="title" maxlength="150" required placeholder="es. Lancio nuova collezione">
      <label>Obiettivo / descrizione (opzionale)</label>
      <textarea name="description" rows="3" placeholder="Cosa vuoi ottenere con questo progetto"></textarea>
      <button type="submit" class="btn">Crea progetto</button>
      <span style="color:var(--text-muted);font-size:12.5px;display:block;margin-top:8px;">I passi si aggiungono dopo aver creato il progetto.</span>
    </form>
  </div>

  <div>
    <div class="mkt-tabs">
      <?php foreach (['active' => 'Attivi', 'inactive' => 'Disattivati', 'archived' => 'Archiviati'] as $k => $lbl): ?>
        <a href="<?= MKT_URL ?>?stato=<?= $k ?>" class="<?= $stato === $k ? 'on' : '' ?>"><?= e($lbl) ?> (<?= (int) $counts[$k] ?>)</a>
      <?php endforeach; ?>
      <a href="<?= MKT_URL ?>?stato=all" class="<?= $stato === 'all' ? 'on' : '' ?>">Tutti (<?= array_sum($counts) ?>)</a>
    </div>
    <?php if (count($projects) > 1): ?>
    <div class="mkt-tools">
      <input type="search" id="mkt-search" placeholder="Cerca per titolo o ID…" aria-label="Cerca un progetto">
      <select id="mkt-sort" aria-label="Ordina i progetti" style="margin:0;width:auto;">
        <option value="date_desc">Data: più recenti prima</option>
        <option value="date_asc">Data: più vecchi prima</option>
        <option value="name_asc">Nome: A → Z</option>
        <option value="name_desc">Nome: Z → A</option>
      </select>
    </div>
    <?php endif; ?>
    <?php if (!$projects): ?>
      <div class="card" style="color:var(--text-muted);"><?= array_sum($counts) ? 'Nessun progetto in questo elenco.' : 'Nessun progetto ancora: creane uno qui a sinistra.' ?></div>
    <?php endif; ?>
    <div id="mkt-list">
    <?php foreach ($projects as $p):
      $isArchP = $p['status'] === 'archived';
      $openP = marketingOpenRun($uid, (int) $p['id']);
      $lastP = marketingListRuns($uid, (int) $p['id'], 1);
      $stLabel = ['running' => 'In corso', 'completed' => 'completata', 'interrupted' => 'interrotta'];
    ?>
    <a class="mkt-row-link mkt-proj" href="<?= e(mktUrl((int) $p['id'])) ?>" data-title="<?= e(mb_strtolower($p['title']) . ' id ' . (int) $p['id']) ?>" data-name="<?= e(mb_strtolower($p['title'])) ?>" data-date="<?= (int) strtotime($p['created_at']) ?>" data-id="<?= (int) $p['id'] ?>">
      <div class="mkt-line">
        <span class="mkt-name"><span class="mkt-id">ID <?= (int) $p['id'] ?></span> <?= e($p['title']) ?></span>
        <?php if ($isArchP): ?><span class="mkt-badge" style="background:#6c757d;">Archiviato</span>
        <?php elseif (!$p['is_active']): ?><span class="mkt-badge" style="background:#9a9aab;">Disattivato</span>
        <?php else: ?><span class="mkt-badge" style="background:#6c5ce7;">Attivo</span><?php endif; ?>
        <?php if ($p['created_by'] === 'ai'): ?><span class="mkt-badge" style="background:#0d9488;" title="Creato da un'intelligenza artificiale tramite MCP">IA</span><?php endif; ?>
        <span style="font-size:12.5px;color:var(--text-muted);white-space:nowrap;"><?= count($p['steps']) ?> passi</span>
      </div>
      <div class="mkt-sub">
        <?php if ($openP): ?>▶ Esecuzione in corso dal <?= e(formatLocalDateTime($openP['started_at'], $profile, 'd/m H:i')) ?>
        <?php elseif ($lastP): ?>Ultima esecuzione: <?= e(formatLocalDateTime($lastP[0]['started_at'], $profile, 'd/m/Y H:i')) ?> · <?= e($stLabel[$lastP[0]['status']]) ?>
        <?php else: ?>Mai eseguito<?php endif; ?>
        · creato il <?= e(formatLocalDateTime($p['created_at'], $profile, 'd/m/Y')) ?>
      </div>
    </a>
    <?php endforeach; ?>
    </div>
  </div>
  </div>
  <script>
  (function () {
    var list = document.getElementById('mkt-list');
    var items = list ? Array.prototype.slice.call(list.querySelectorAll('.mkt-proj')) : [];
    var q = document.getElementById('mkt-search');
    if (q) q.addEventListener('input', function () {
      var t = q.value.trim().toLowerCase();
      items.forEach(function (d) { d.style.display = !t || (d.getAttribute('data-title') || '').indexOf(t) !== -1 ? '' : 'none'; });
    });
    // Ordinamento: per data di creazione o per nome, crescente/decrescente; la scelta si ricorda nel browser.
    var sel = document.getElementById('mkt-sort');
    if (sel && items.length) {
      var apply = function () {
        var v = sel.value, dir = /_desc$/.test(v) ? -1 : 1, byName = /^name/.test(v);
        items.slice().sort(function (a, b) {
          if (byName) return dir * (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '', 'it', { sensitivity: 'base' });
          return dir * (((+a.getAttribute('data-date')) - (+b.getAttribute('data-date'))) || ((+a.getAttribute('data-id')) - (+b.getAttribute('data-id'))));
        }).forEach(function (d) { list.appendChild(d); });
      };
      try { var saved = localStorage.getItem('mktSort'); if (saved && sel.querySelector('option[value="' + saved + '"]')) sel.value = saved; } catch (e) {}
      sel.addEventListener('change', function () { try { localStorage.setItem('mktSort', sel.value); } catch (e) {} apply(); });
      apply();
    }
  })();
  </script>
<?php endif; ?>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
