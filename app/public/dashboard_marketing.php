<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/marketing.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'marketing';
$pageTitle = 'Marketing';
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
        header('Location: ' . mktUrl($pid, $goRun));
        exit;
    }
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
            } elseif ($openRun) {
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

include __DIR__ . '/_dash_header.php';
?>
  <style>
  .container { max-width: 1240px; }
  .dash-two-col { display: block; }
  .dash-two-col > div { min-width: 0; }
  @media (min-width: 760px) {
    .dash-two-col { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.6fr); gap: 20px; align-items: start; }
    .mkt-detail-cols { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 28px; align-items: start; }
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
  /* pagina del progetto */
  .mkt-head { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap; justify-content: space-between; margin-bottom: 6px; }
  .mkt-head h2 { margin: 0; font-size: 22px; overflow-wrap: anywhere; }
  .mkt-switch { display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--text-muted); }
  .mkt-switch button { position: relative; width: 42px; height: 24px; border-radius: 999px; border: 0; cursor: pointer; background: #c9c9d6; padding: 0; }
  .mkt-switch button::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: left .15s; }
  .mkt-switch.on button { background: var(--accent); }
  .mkt-switch.on button::after { left: 21px; }
  .mkt-h { font-size: 14px; font-weight: 700; margin: 6px 0 8px; }
  .mkt-run { border-bottom: 1px solid #eee; }
  .mkt-run > a { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 11px 2px; text-decoration: none; color: inherit; }
  .mkt-run > a:hover { background: rgba(108,92,231,.05); }
  .mkt-run.sel > a { font-weight: 700; }
  .mkt-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
  .mkt-run-meta { font-size: 12.5px; color: var(--text-muted); margin-left: auto; white-space: nowrap; }
  .mkt-tag { font-size: 11.5px; color: var(--text-muted); }
  .mkt-run-detail { padding: 4px 4px 14px 14px; border-left: 3px solid var(--accent); margin: 0 0 12px 4px; }
  .mkt-ev { display: flex; gap: 10px; padding: 6px 0; font-size: 14px; }
  .mkt-ev time { flex: 0 0 96px; font-size: 12.5px; color: var(--text-muted); padding-top: 1px; }
  .mkt-ev .mkt-evtxt { flex: 1; min-width: 0; overflow-wrap: anywhere; }
  .mkt-ev .mkt-evstep { font-size: 12.5px; color: var(--text-muted); display: block; }
  .mkt-ev .mkt-evbody { white-space: pre-wrap; display: block; }
  .mkt-now { background: rgba(108,92,231,.07); border-radius: 10px; padding: 10px 12px; margin-top: 10px; }
  .mkt-step { border-left: 3px solid #c9c9d6; padding: 6px 0 6px 12px; margin: 10px 0; }
  .mkt-step.done { border-left-color: #2e9e5b; }
  .mkt-step.next { border-left-color: var(--accent); }
  .mkt-step.final { border-left-style: double; border-left-width: 5px; }
  .mkt-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); }
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
    $openDone = $openRun ? marketingRunDone($uid, (int) $openRun['id']) : [];
    $openNext = $openRun ? marketingRunNextStep($project, $openDone) : null;
    $dotColor = ['running' => '#2d7ff9', 'completed' => '#2e9e5b', 'interrupted' => '#e08a1e'];
    $stLabel = ['running' => 'In corso', 'completed' => 'Completata', 'interrupted' => 'Interrotta'];
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
  <hr style="border:0;border-top:1px solid #e5e5ee;margin:12px 0 16px;">

  <div class="mkt-detail-cols">
  <div>
    <div class="mkt-h">Cronologia</div>
    <?php if (!$runs): ?>
      <p style="color:var(--text-muted);font-size:14px;">Nessuna esecuzione ancora.<?= $project['is_active'] && $steps && !$isArch ? ' Premi <strong>Esegui ora</strong> per avviarne una, oppure lascia che lo faccia un\'IA.' : '' ?></p>
    <?php endif; ?>
    <?php foreach ($runs as $r):
      $isSel = $selRun && (int) $selRun['id'] === (int) $r['id'];
      $tot = $r['status'] === 'running' ? count($steps) : (int) $r['steps_total'];
    ?>
    <div class="mkt-run<?= $isSel ? ' sel' : '' ?>" id="r<?= (int) $r['id'] ?>">
      <a href="<?= e($isSel ? mktUrl((int) $project['id'], 0, '#r' . (int) $r['id']) : mktUrl((int) $project['id'], (int) $r['id'], '#r' . (int) $r['id'])) ?>">
        <span><?= e(formatLocalDateTime($r['started_at'], $profile, 'd/m/Y \a\l\l\e H:i')) ?></span>
        <span class="mkt-tag">· <?= e(mktWho($r['started_by'])) ?></span>
        <span class="mkt-run-meta"><span class="mkt-dot" style="background:<?= e($dotColor[$r['status']]) ?>;display:inline-block;" aria-hidden="true"></span> <?= e($stLabel[$r['status']]) ?> · <?= (int) $r['done_count'] ?>/<?= (int) $tot ?></span>
      </a>
      <?php if ($isSel): $sr = $selRun; ?>
      <div class="mkt-run-detail">
        <?php foreach ($sr['events'] as $ev):
          $icon = ['started' => '▶', 'note' => '📝', 'step_done' => '✅', 'finished' => '🏁', 'interrupted' => '⏹'][$ev['kind']] ?? '•';
          $verb = ['started' => 'Esecuzione avviata', 'note' => '', 'step_done' => 'Passo completato', 'finished' => '', 'interrupted' => 'Esecuzione interrotta'][$ev['kind']] ?? '';
        ?>
        <div class="mkt-ev">
          <time><?= e(formatLocalDateTime($ev['created_at'], $profile, 'd/m H:i')) ?></time>
          <div class="mkt-evtxt">
            <?= $icon ?> <strong><?= e(mktWho($ev['actor'])) ?></strong><?= $verb !== '' ? ' · ' . e($verb) : '' ?>
            <?php if ($ev['step_label']): ?><span class="mkt-evstep"><?= e($ev['step_label']) ?></span><?php endif; ?>
            <?php if ($ev['body']): ?><span class="mkt-evbody"><?= e($ev['body']) ?></span><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if ($sr['status'] === 'running' && !$isArch): ?>
        <div class="mkt-now">
          <?php if ($sr['next_step']): $ns = $sr['next_step']; ?>
            <div class="mkt-label">▶ Da fare adesso · <?= e(marketingStepLabel($steps, (int) $ns['id'])) ?></div>
            <div style="font-weight:600;"><?= e($ns['title']) ?></div>
            <?php if ($ns['instructions']): ?><div class="mkt-instr"><?= e($ns['instructions']) ?></div><?php endif; ?>
            <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
              <?= csrfField() ?><input type="hidden" name="action" value="complete_step"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $ns['id'] ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
              <input type="text" name="note" maxlength="2000" placeholder="Nota (facoltativa): cosa è stato fatto" style="margin:0;flex:1;min-width:180px;">
              <button class="btn small" type="submit">✔ Segna completato</button>
            </form>
          <?php else: ?>
            <div style="font-size:13.5px;">Tutti i passi sono completati: manca solo il <strong>Finale</strong>. Inseriscilo dal riquadro Istruzioni per poter concludere.</div>
          <?php endif; ?>
          <details style="margin-top:8px;"><summary style="cursor:pointer;font-size:13.5px;">📝 Aggiungi una nota</summary>
            <form method="post" style="margin-top:6px;">
              <?= csrfField() ?><input type="hidden" name="action" value="add_note"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
              <textarea name="text" rows="2" required placeholder="Cosa hai fatto o deciso"></textarea>
              <button class="btn small secondary" type="submit">Aggiungi alla cronologia</button>
            </form>
          </details>
          <form method="post" style="margin-top:8px;" onsubmit="return confirm('Interrompere questa esecuzione? Non si potrà più proseguire: potrai avviarne una nuova.');">
            <?= csrfField() ?><input type="hidden" name="action" value="interrupt_run"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
            <input type="text" name="reason" maxlength="500" placeholder="Motivo (facoltativo)" style="margin:0 6px 0 0;width:auto;min-width:160px;">
            <button class="btn small danger" type="submit">⏹ Interrompi</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div>
    <div class="mkt-h">Istruzioni</div>
    <?php if ($project['description']): ?><p class="mkt-instr" style="color:var(--text-muted);"><?= e($project['description']) ?></p><?php endif; ?>
    <?php if (!$steps): ?><p style="color:var(--text-muted);font-size:14px;">Nessun passo ancora: aggiungi il Passo 1 qui sotto.</p><?php endif; ?>
    <?php foreach ($steps as $s):
      $label = marketingStepLabel($steps, (int) $s['id']);
      $stDone = isset($openDone[(int) $s['id']]);
      $stNext = $openNext && (int) $openNext['id'] === (int) $s['id'];
    ?>
    <div class="mkt-step<?= (int) $s['is_final'] ? ' final' : '' ?><?= $stDone ? ' done' : '' ?><?= $stNext ? ' next' : '' ?>">
      <div class="mkt-label"><?= e($label) ?><?= $stDone ? ' · ✅ fatto' : ($stNext ? ' · ▶ da fare adesso' : '') ?></div>
      <div style="font-weight:600;"><?= e($s['title']) ?></div>
      <?php if ($s['instructions']): ?><div class="mkt-instr"><?= e($s['instructions']) ?></div><?php endif; ?>
      <?php if (!$isArch): ?>
      <div class="mkt-actions">
        <details style="display:inline-block;">
          <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica</summary>
          <form method="post" style="margin-top:8px;">
            <?= csrfField() ?><input type="hidden" name="action" value="update_step"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
            <input type="text" name="title" maxlength="150" required value="<?= e($s['title']) ?>">
            <textarea name="instructions" rows="3"><?= e($s['instructions'] ?? '') ?></textarea>
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
    <?php endforeach; ?>

    <?php if (!$isArch): ?>
      <?php if (!$hasFinal): ?>
      <details style="margin-top:10px;"<?= !$steps ? ' open' : '' ?>>
        <summary style="cursor:pointer;font-weight:600;">➕ Aggiungi un passo</summary>
        <form method="post" style="margin-top:8px;">
          <?= csrfField() ?><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
          <label>Titolo del <?= $steps ? 'passo ' . (count($steps) + 1) : 'Passo 1' ?></label>
          <input type="text" name="title" maxlength="150" required>
          <label>Istruzioni (per te o per l'IA)</label>
          <textarea name="instructions" rows="3" placeholder="Cosa va fatto in questo passo, con quali contenuti, quando…"></textarea>
          <button class="btn" type="submit" name="action" value="add_step">Aggiungi passo</button>
          <button class="btn secondary" type="submit" name="action" value="add_final" title="Aggiunge l'ultimo passo e chiude il progetto">🏁 Inserisci Finale</button>
        </form>
      </details>
      <?php else: ?>
        <p style="color:var(--text-muted);font-size:12.5px;margin:10px 0 0;">Il Finale è inserito: per aggiungere altri passi elimina prima il Finale.</p>
      <?php endif; ?>
    <?php endif; ?>

    <div class="mkt-actions" style="margin-top:16px;border-top:1px solid #e5e5ee;padding-top:12px;">
      <?php if (!$isArch): ?>
      <details style="display:inline-block;">
        <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica progetto</summary>
        <form method="post" style="margin-top:8px;">
          <?= csrfField() ?><input type="hidden" name="action" value="update_project"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
          <input type="text" name="title" maxlength="150" required value="<?= e($project['title']) ?>">
          <textarea name="description" rows="3"><?= e($project['description'] ?? '') ?></textarea>
          <button class="btn small" type="submit">Salva progetto</button>
        </form>
      </details>
      <?php endif; ?>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="<?= $isArch ? 'unarchive_project' : 'archive_project' ?>"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <button class="btn small secondary" type="submit"><?= $isArch ? 'Ripristina' : 'Archivia' ?></button>
      </form>
      <form method="post" onsubmit="return confirm('Eliminare il progetto con tutti i passi e tutta la cronologia? Non si può annullare.');">
        <?= csrfField() ?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <button class="btn small danger" type="submit">Elimina progetto</button>
      </form>
    </div>
  </div>
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
<?php include __DIR__ . '/_dash_footer.php'; ?>
