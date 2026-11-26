<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/marketing.php';
$user = requireAdmin(); // strumento riservato agli amministratori del sito
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
    if ($action === 'add_step' && !empty($_POST['is_final'])) { $action = 'add_final'; } // il popup «Nuovo passo» ha l'interruttore «Finale»
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

$statoLabels = ['active' => 'Attivi', 'inactive' => 'Disattivati', 'archived' => 'Archiviati'];
$stLabelRun = ['running' => 'In corso', 'completed' => 'Completata', 'interrupted' => 'Interrotta'];
$ago = fn (?string $d) => $d ? formatLocalDateTime($d, $profile, 'd/m/Y H:i') : '';
?>
  <div class="tlx mkx">
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<?php if ($project): /* ============================ PAGINA DI UN PROGETTO ============================ */
    $steps = $project['steps'];
    $isArch = $project['status'] === 'archived';
    $hasFinal = false;
    foreach ($steps as $s) { $hasFinal = $hasFinal || (int) $s['is_final']; }
    $tabUrl = fn (string $t) => mktUrl((int) $project['id']) . (strpos(mktUrl((int) $project['id']), '?') === false ? '?' : '&') . 'tab=' . $t;
    $pid = (int) $project['id'];
?>
    <p class="mkx-back"><a href="<?= MKT_URL ?>"><i class="fa-solid fa-arrow-left"></i> Tutti i progetti</a></p>

    <div class="mkx-head">
      <div class="mkx-title">
        <h2><span class="mkx-id">ID <?= $pid ?></span> <?= e($project['title']) ?></h2>
        <div class="mkx-sub">
          <?php if ($isArch): ?><span class="mkx-badge off">Archiviato</span><?php endif; ?>
          <?php if ($project['created_by'] === 'ai'): ?><span class="mkx-badge ai" title="Creato da un'intelligenza artificiale tramite MCP">IA</span><?php endif; ?>
          <?php if ($runs): ?>Ultima esecuzione: <?= e($ago($runs[0]['started_at'])) ?> · <?= e($stLabelRun[$runs[0]['status']]) ?><?php else: ?>Mai eseguito<?php endif; ?>
        </div>
      </div>
      <?php if (!$isArch): ?>
      <div class="mkx-actions">
        <form method="post" data-autosubmit class="mkx-active">
          <?= csrfField() ?><input type="hidden" name="action" value="set_active"><input type="hidden" name="project_id" value="<?= $pid ?>">
          <?php studioSwitch('active', 'Attivo', (bool) $project['is_active']); ?>
        </form>
        <?php if ($project['is_active'] && $steps && !$openRun): ?>
        <form method="post">
          <?= csrfField() ?><input type="hidden" name="action" value="start_run"><input type="hidden" name="project_id" value="<?= $pid ?>">
          <button class="stc-act primary" type="submit"><i class="fa-solid fa-play"></i> <span>Esegui ora</span></button>
        </form>
        <?php elseif ($openRun): ?>
          <a class="stc-act primary" href="<?= e(mktUrl($pid, (int) $openRun['id'])) ?>"><i class="fa-solid fa-play"></i> <span>Esecuzione in corso</span></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <nav class="mkx-tabs" aria-label="Sezioni del progetto">
      <a href="<?= e($tabUrl('cronologia')) ?>" class="<?= $tab === 'cronologia' ? 'on' : '' ?>"><i class="fa-solid fa-clock-rotate-left"></i> Cronologia <small><?= count($runs) ?></small></a>
      <a href="<?= e($tabUrl('istruzioni')) ?>" class="<?= $tab === 'istruzioni' ? 'on' : '' ?>"><i class="fa-solid fa-list-ol"></i> Istruzioni <small><?= count($steps) ?> passi</small></a>
      <a href="<?= e($tabUrl('impostazioni')) ?>" class="<?= $tab === 'impostazioni' ? 'on' : '' ?>"><i class="fa-solid fa-gear"></i> Impostazioni</a>
    </nav>

    <div class="mkx-panel">
  <?php if ($tab === 'cronologia' && $selRun): /* ---------- una esecuzione ---------- */
      $sr = $selRun;
      $srTotal = $sr['status'] === 'running' ? count($steps) : max((int) $sr['steps_total'], count($sr['done']));
  ?>
      <p class="mkx-back"><a href="<?= e($tabUrl('cronologia')) ?>"><i class="fa-solid fa-arrow-left"></i> Tutte le esecuzioni</a></p>
      <div class="mkx-card mkx-runhead">
        <div class="mkx-line">
          <b class="mkx-when"><?= e(formatLocalDateTime($sr['started_at'], $profile, 'd/m/Y \a\l\l\e H:i')) ?></b>
          <span class="mkx-tag">avviata da <strong><?= e(mktWho($sr['started_by'])) ?></strong></span>
          <span class="mkx-state <?= e($sr['status']) ?>"><?= e($stLabelRun[$sr['status']]) ?> · <?= count($sr['done']) ?>/<?= (int) $srTotal ?></span>
        </div>
        <?php if ($steps): ?>
        <div class="mkx-stepper" aria-label="Avanzamento">
          <?php $nextId = $sr['next_step'] ? (int) $sr['next_step']['id'] : 0; foreach ($steps as $k => $s2): $isD = isset($sr['done'][(int) $s2['id']]); ?>
            <span class="st<?= $isD ? ' done' : '' ?><?= (int) $s2['id'] === $nextId ? ' next' : '' ?>" title="<?= e(marketingStepLabel($steps, (int) $s2['id']) . ' — ' . $s2['title']) ?>"><b><?= (int) $s2['is_final'] ? '<i class="fa-solid fa-flag-checkered"></i>' : ($k + 1) ?></b></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($sr['status'] === 'running' && !$isArch): ?>
      <div class="mkx-now">
        <?php if ($sr['next_step']): $ns = $sr['next_step']; ?>
          <span class="tlx-lbl">Da fare adesso · <?= e(marketingStepLabel($steps, (int) $ns['id'])) ?></span>
          <h3><?= e($ns['title']) ?></h3>
          <?php if ($ns['instructions']): ?>
            <details class="mkx-more"><summary>Mostra le istruzioni del passo</summary><div class="mkx-text"><?= e($ns['instructions']) ?></div></details>
          <?php endif; ?>
          <form method="post" class="mkx-inline">
            <?= csrfField() ?><input type="hidden" name="action" value="complete_step"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="step_id" value="<?= (int) $ns['id'] ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
            <input type="text" name="note" class="kit-inp" maxlength="2000" placeholder="Nota (facoltativa): cosa è stato fatto">
            <button class="stc-act primary" type="submit"><i class="fa-solid fa-check"></i> <span>Segna completato</span></button>
          </form>
        <?php else: ?>
          <p class="mkx-note">Tutti i passi sono completati: manca solo il <strong>Finale</strong>. Inseriscilo dalla scheda <a href="<?= e($tabUrl('istruzioni')) ?>">Istruzioni</a> per poter concludere.</p>
        <?php endif; ?>
        <div class="mkx-actions mkx-actions-row">
          <button type="button" class="stc-act" data-dlg="mk-note"><i class="fa-solid fa-note-sticky"></i> <span>Aggiungi una nota</span></button>
          <form method="post" class="mkx-inline" data-confirm="Interrompere questa esecuzione? Non si potrà più proseguire: potrai avviarne una nuova.">
            <?= csrfField() ?><input type="hidden" name="action" value="interrupt_run"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
            <input type="text" name="reason" class="kit-inp" maxlength="500" placeholder="Motivo (facoltativo)">
            <button class="stc-act danger" type="submit"><i class="fa-solid fa-stop"></i> <span>Interrompi</span></button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="tlx-listhead"><h2>Cosa è successo</h2></div>
      <div class="mkx-timeline">
        <?php foreach ($sr['events'] as $ev):
          $what = ['started' => 'Esecuzione avviata', 'note' => 'Nota', 'step_done' => 'Passo completato', 'finished' => 'Esecuzione completata', 'interrupted' => 'Esecuzione interrotta'][$ev['kind']] ?? $ev['kind'];
          $body = (string) ($ev['body'] ?? '');
          $isLong = mb_strlen($body) > 320;
        ?>
        <div class="mkx-ev k-<?= e($ev['kind']) ?>">
          <div class="top"><b><?= e($what) ?></b><span class="mkx-tag">· <?= e(mktWho($ev['actor'])) ?></span><time><?= e($ago($ev['created_at'])) ?></time></div>
          <?php if ($ev['step_label']): ?><div class="mkx-stepname"><?= e($ev['step_label']) ?></div><?php endif; ?>
          <?php if ($body !== '' && $ev['kind'] !== 'finished'): ?>
            <?php if ($isLong): ?>
              <div class="mkx-text"><?= e(mb_strimwidth($body, 0, 320, '…')) ?></div>
              <details class="mkx-more"><summary>Mostra il testo completo</summary><div class="mkx-text"><?= e($body) ?></div></details>
            <?php else: ?>
              <div class="mkx-text"><?= e($body) ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($sr['status'] === 'running' && !$isArch): ?>
      <dialog class="cmp kit-dlg" id="mk-note" aria-labelledby="mk-note-t" data-title-new="Aggiungi una nota" data-title-edit="Aggiungi una nota" data-action-new="add_note" data-action-edit="add_note">
        <form method="post" class="cmp-form"><?= csrfField() ?>
          <input type="hidden" name="action" value="add_note"><input type="hidden" name="id" value=""><input type="hidden" name="tz_offset_minutes" value="">
          <input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="run_id" value="<?= (int) $sr['id'] ?>">
          <section class="cmp-panel"><header class="cmp-head"><h2 id="mk-note-t">Aggiungi una nota</h2><button type="button" class="cmp-x" data-kit-close aria-label="Chiudi"><i class="fa-solid fa-xmark"></i></button></header>
          <div class="cmp-body"><label for="mk-note-text">Cosa hai fatto o deciso</label><textarea name="text" id="mk-note-text" rows="4" required></textarea></div>
          <footer class="cmp-foot"><button type="submit" class="tlx-submit"><i class="fa-solid fa-check"></i> <span>Aggiungi alla cronologia</span></button></footer></section>
        </form>
      </dialog>
      <?php endif; ?>

  <?php elseif ($tab === 'cronologia'): /* ---------- elenco esecuzioni ---------- */ ?>
    <?php if (!$runs): ?>
      <div class="tlx-empty"><i class="fa-solid fa-clock-rotate-left"></i><b>Nessuna esecuzione ancora</b><span><?= $project['is_active'] && $steps && !$isArch ? 'Premi «Esegui ora» per avviarne una, oppure lascia che lo faccia un\'IA.' : ($steps ? '' : 'Aggiungi prima i passi dalla scheda Istruzioni.') ?></span></div>
    <?php else: ?>
      <div class="mkx-runs">
      <?php foreach ($runs as $r):
          $tot = $r['status'] === 'running' ? count($steps) : max((int) $r['steps_total'], (int) $r['done_count']);
          $pctR = $tot ? (int) round(((int) $r['done_count']) / $tot * 100) : 0;
      ?>
        <a class="mkx-runrow" href="<?= e(mktUrl($pid, (int) $r['id'])) ?>">
          <span class="mkx-dot <?= e($r['status']) ?>" aria-hidden="true"></span>
          <b><?= e(formatLocalDateTime($r['started_at'], $profile, 'd/m/Y \a\l\l\e H:i')) ?></b>
          <span class="mkx-tag"><?= e(mktWho($r['started_by'])) ?></span>
          <span class="meta"><span><?= e($stLabelRun[$r['status']]) ?></span><span class="mkx-bar" aria-hidden="true"><i data-w="<?= $pctR ?>"></i></span><span><?= (int) $r['done_count'] ?>/<?= (int) $tot ?></span><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'istruzioni'): /* ---------- passi (modello) ---------- */ ?>
    <?php if ($project['description']): ?><div class="mkx-card"><span class="tlx-lbl">Obiettivo</span><p class="mkx-text"><?= e($project['description']) ?></p></div><?php endif; ?>
    <div class="tlx-listhead"><h2>Passi</h2>
      <?php if (!$isArch && !$hasFinal): ?><button type="button" class="tlx-submit" data-dlg="mk-step"><i class="fa-solid fa-plus"></i> <span>Nuovo passo</span></button><?php endif; ?></div>
    <?php if (!$steps): ?><div class="tlx-empty"><i class="fa-solid fa-list-ol"></i><b>Nessun passo ancora</b><span>Aggiungi il Passo 1 per iniziare.</span></div><?php endif; ?>
    <?php foreach ($steps as $s):
      $label = marketingStepLabel($steps, (int) $s['id']);
      $fill = json_encode(['step_id' => (int) $s['id'], 'title' => $s['title'], 'instructions' => $s['instructions'] ?? ''], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
    <details class="mkx-step<?= (int) $s['is_final'] ? ' final' : '' ?>"<?= count($steps) <= 2 ? ' open' : '' ?>>
      <summary><span class="num"><?= e($label) ?></span><span class="ttl"><?= e($s['title']) ?></span></summary>
      <div class="mkx-stepbody">
        <?php if ($s['instructions']): ?><div class="mkx-text"><?= e($s['instructions']) ?></div><?php else: ?><p class="mkx-note">Nessuna istruzione.</p><?php endif; ?>
        <?php if (!$isArch): ?>
        <div class="mkx-actions mkx-actions-row">
          <button type="button" class="stc-act" data-dlg="mk-stepedit" data-fill='<?= $fill ?>'><i class="fa-solid fa-pen"></i> <span>Modifica</span></button>
          <form method="post" data-confirm="Eliminare questo passo? Le cronologie passate restano leggibili.">
            <?= csrfField() ?><input type="hidden" name="action" value="delete_step"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
            <button class="stc-act danger" type="submit"><i class="fa-solid fa-xmark"></i> <span>Elimina</span></button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </details>
    <?php endforeach; ?>
    <?php if (!$isArch && $hasFinal): ?><p class="mkx-note">Il Finale è inserito: per aggiungere altri passi elimina prima il Finale.</p><?php endif; ?>

    <?php if (!$isArch): ?>
    <?php studioDlgOpen('mk-step', ['new_title' => 'Nuovo passo', 'edit_title' => 'Nuovo passo', 'action_new' => 'add_step', 'action_edit' => 'add_step', 'multipart' => false]); ?>
      <input type="hidden" name="project_id" value="<?= $pid ?>">
      <label for="mk-step-title">Titolo del <?= $steps ? 'passo ' . (count($steps) + 1) : 'Passo 1' ?></label>
      <input type="text" name="title" id="mk-step-title" class="kit-inp" maxlength="150" required>
      <label for="mk-step-ins">Istruzioni (per te o per l'IA)</label>
      <textarea name="instructions" id="mk-step-ins" rows="5" placeholder="Cosa va fatto in questo passo, con quali contenuti, quando…"></textarea>
      <?php studioSwitch('is_final', 'È il Finale (aggiunge l\'ultimo passo e chiude il progetto)', false); ?>
    <?php studioDlgClose('Aggiungi passo'); ?>
    <?php studioDlgOpen('mk-stepedit', ['new_title' => 'Modifica passo', 'edit_title' => 'Modifica passo', 'action_new' => 'update_step', 'action_edit' => 'update_step', 'multipart' => false]); ?>
      <input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="step_id" value="">
      <label for="mk-se-title">Titolo</label>
      <input type="text" name="title" id="mk-se-title" class="kit-inp" maxlength="150" required>
      <label for="mk-se-ins">Istruzioni</label>
      <textarea name="instructions" id="mk-se-ins" rows="8"></textarea>
    <?php studioDlgClose('Salva passo'); ?>
    <?php endif; ?>

  <?php else: /* ---------- impostazioni ---------- */ ?>
    <?php if (!$isArch): ?>
    <form method="post" class="mkx-card">
      <?= csrfField() ?><input type="hidden" name="action" value="update_project"><input type="hidden" name="project_id" value="<?= $pid ?>">
      <h3>Dati del progetto</h3>
      <label class="tlx-lbl" for="mk-p-title">Titolo</label>
      <input type="text" name="title" id="mk-p-title" class="kit-inp" maxlength="150" required value="<?= e($project['title']) ?>">
      <label class="tlx-lbl" for="mk-p-desc">Obiettivo / descrizione</label>
      <textarea name="description" id="mk-p-desc" rows="5"><?= e($project['description'] ?? '') ?></textarea>
      <button class="tlx-submit" type="submit"><i class="fa-solid fa-check"></i> <span>Salva</span></button>
    </form>
    <?php endif; ?>
    <div class="mkx-card">
      <h3>Archivio</h3>
      <p class="mkx-note"><?= $isArch ? 'Il progetto è archiviato: non si può usare finché non lo ripristini. La cronologia resta consultabile.' : 'Archiviare toglie il progetto dall\'elenco principale e impedisce di usarlo; la cronologia resta consultabile e puoi ripristinarlo quando vuoi.' ?></p>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="<?= $isArch ? 'unarchive_project' : 'archive_project' ?>"><input type="hidden" name="project_id" value="<?= $pid ?>">
        <button class="stc-act" type="submit"><i class="fa-solid fa-box-archive"></i> <span><?= $isArch ? 'Ripristina il progetto' : 'Archivia il progetto' ?></span></button>
      </form>
    </div>
    <div class="blx-danger"><div><b>Elimina progetto</b><p>Elimina il progetto con tutti i passi e tutta la cronologia. Non si può annullare.</p></div>
      <form method="post" data-confirm="Eliminare il progetto con tutti i passi e tutta la cronologia? Non si può annullare.">
        <?= csrfField() ?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= $pid ?>">
        <button class="stc-act danger" type="submit"><i class="fa-solid fa-trash"></i> <span>Elimina progetto</span></button>
      </form></div>
  <?php endif; ?>
    </div>

<?php else: /* ============================ ELENCO DEI PROGETTI ============================ */
    $running = 0;
    foreach ($projects as $p0) { if (marketingOpenRun($uid, (int) $p0['id'])) { $running++; } }
?>
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= (int) $counts['active'] ?></b><span>Progetti attivi</span></div>
      <div class="tlx-kpi"><b><?= $running ?></b><span>Esecuzioni in corso</span></div>
      <div class="tlx-kpi"><b><?= (int) $counts['inactive'] ?></b><span>Disattivati</span></div>
      <div class="tlx-kpi"><b><?= (int) $counts['archived'] ?></b><span>Archiviati</span></div>
    </div>

    <div class="tlx-listhead"><h2>I tuoi progetti</h2>
      <button type="button" class="tlx-submit" data-dlg="mk-new"><i class="fa-solid fa-plus"></i> <span>Nuovo progetto</span></button></div>

    <div class="sqx-chips">
      <?php foreach ($statoLabels as $k => $lbl): ?><a class="sqx-chip<?= $stato === $k ? ' on' : '' ?>" href="<?= MKT_URL ?>?stato=<?= $k ?>"><?= e($lbl) ?> (<?= (int) $counts[$k] ?>)</a><?php endforeach; ?>
      <a class="sqx-chip<?= $stato === 'all' ? ' on' : '' ?>" href="<?= MKT_URL ?>?stato=all">Tutti (<?= array_sum($counts) ?>)</a>
    </div>

    <?php if (count($projects) > 1): ?>
    <div class="mkx-tools">
      <input type="search" class="kit-inp" id="mkt-search" placeholder="Cerca per titolo o ID…" aria-label="Cerca un progetto">
      <select id="mkt-sort" class="kit-inp" aria-label="Ordina i progetti">
        <option value="date_desc">Data: più recenti prima</option>
        <option value="date_asc">Data: più vecchi prima</option>
        <option value="name_asc">Nome: A → Z</option>
        <option value="name_desc">Nome: Z → A</option>
      </select>
    </div>
    <?php endif; ?>

    <?php if (!$projects): ?>
      <div class="tlx-empty"><i class="fa-solid fa-bullhorn"></i><b><?= array_sum($counts) ? 'Nessun progetto in questo elenco' : 'Nessun progetto ancora' ?></b><span><?= array_sum($counts) ? '' : 'Crea il primo con «Nuovo progetto»: i passi si aggiungono dopo.' ?></span></div>
    <?php endif; ?>

    <div id="mkt-list" class="mkx-list">
    <?php foreach ($projects as $p):
      $isArchP = $p['status'] === 'archived';
      $openP = marketingOpenRun($uid, (int) $p['id']);
      $lastP = marketingListRuns($uid, (int) $p['id'], 1);
      $stLabelP = ['running' => 'In corso', 'completed' => 'completata', 'interrupted' => 'interrotta'];
    ?>
      <a class="mkx-proj" href="<?= e(mktUrl((int) $p['id'])) ?>" data-title="<?= e(mb_strtolower($p['title']) . ' id ' . (int) $p['id']) ?>" data-name="<?= e(mb_strtolower($p['title'])) ?>" data-date="<?= (int) strtotime($p['created_at']) ?>" data-id="<?= (int) $p['id'] ?>">
        <div class="mkx-line">
          <b class="mkx-name"><span class="mkx-id">ID <?= (int) $p['id'] ?></span> <?= e($p['title']) ?></b>
          <?php if ($isArchP): ?><span class="mkx-badge off">Archiviato</span>
          <?php elseif (!$p['is_active']): ?><span class="mkx-badge idle">Disattivato</span>
          <?php else: ?><span class="mkx-badge on">Attivo</span><?php endif; ?>
          <?php if ($p['created_by'] === 'ai'): ?><span class="mkx-badge ai" title="Creato da un'intelligenza artificiale tramite MCP">IA</span><?php endif; ?>
          <span class="mkx-steps"><?= count($p['steps']) ?> passi</span>
        </div>
        <div class="mkx-sub">
          <?php if ($openP): ?><i class="fa-solid fa-play"></i> Esecuzione in corso dal <?= e(formatLocalDateTime($openP['started_at'], $profile, 'd/m H:i')) ?>
          <?php elseif ($lastP): ?>Ultima esecuzione: <?= e($ago($lastP[0]['started_at'])) ?> · <?= e($stLabelP[$lastP[0]['status']]) ?>
          <?php else: ?>Mai eseguito<?php endif; ?>
          · creato il <?= e(formatLocalDateTime($p['created_at'], $profile, 'd/m/Y')) ?>
        </div>
      </a>
    <?php endforeach; ?>
    </div>

    <?php studioDlgOpen('mk-new', ['new_title' => 'Nuovo progetto', 'edit_title' => 'Nuovo progetto', 'action_new' => 'create_project', 'action_edit' => 'create_project', 'multipart' => false]); ?>
      <label for="mk-n-title">Titolo del progetto</label>
      <input type="text" name="title" id="mk-n-title" class="kit-inp" maxlength="150" required placeholder="es. Lancio nuova collezione">
      <label for="mk-n-desc">Obiettivo / descrizione (opzionale)</label>
      <textarea name="description" id="mk-n-desc" rows="4" placeholder="Cosa vuoi ottenere con questo progetto"></textarea>
      <p class="tlx-hint">I passi si aggiungono dopo aver creato il progetto.</p>
    <?php studioDlgClose('Crea progetto'); ?>
<?php endif; ?>
  </div>

  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
  <script src="/studio/assets/studio-marketing.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-marketing.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
