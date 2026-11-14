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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $pid = (int) ($_POST['project_id'] ?? 0);
    $sid = (int) ($_POST['step_id'] ?? 0);
    try {
        if ($action === 'create_project') {
            $r = marketingCreateProject($uid, (string) ($_POST['title'] ?? ''), (string) ($_POST['description'] ?? ''), 'user');
            if (isset($r['error'])) { $error = $r['error']; } else { header('Location: /dashboard_marketing.php?stato=active&open=' . $r['id'] . '#p' . $r['id']); exit; }
        } elseif ($action === 'update_project') {
            $error = marketingUpdateProject($uid, $pid, ['title' => $_POST['title'] ?? '', 'description' => $_POST['description'] ?? '']);
        } elseif ($action === 'archive_project' || $action === 'unarchive_project') {
            $error = marketingUpdateProject($uid, $pid, ['archived' => $action === 'archive_project']);
        } elseif ($action === 'delete_project') {
            marketingDeleteProject($uid, $pid);
        } elseif ($action === 'add_step' || $action === 'add_final') {
            $r = marketingAddStep($uid, $pid, (string) ($_POST['title'] ?? ''), (string) ($_POST['instructions'] ?? ''), $action === 'add_final', 'user');
            $error = $r['error'] ?? null;
        } elseif ($action === 'update_step') {
            $error = marketingUpdateStep($uid, $sid, ['title' => $_POST['title'] ?? '', 'instructions' => $_POST['instructions'] ?? '']);
        } elseif ($action === 'delete_step') {
            $error = marketingDeleteStep($uid, $sid);
        } elseif ($action === 'complete_step') {
            $error = marketingCompleteStep($uid, $sid, (string) ($_POST['result'] ?? ''), 'user');
        } elseif ($action === 'reopen_step') {
            $error = marketingReopenStep($uid, $sid);
        }
    } catch (PDOException $ex) {
        $error = $ex->getCode() === '42S02' ? 'Il modulo Marketing non è ancora attivo su questo database: applicare la migrazione 2026_11_21_marketing_plans.sql.' : 'Errore durante il salvataggio.';
        error_log('dashboard_marketing: ' . $ex->getMessage());
    }
    if ($error === null) {
        // Dopo l'azione si torna alla scheda dello stato in cui il progetto si trova adesso
        // (es. completato il Finale -> "Completati"), con il progetto aperto.
        $after = ($pid && $action !== 'delete_project') ? marketingGetProject($uid, $pid) : null;
        header('Location: /dashboard_marketing.php' . ($after ? '?stato=' . $after['status'] . '&open=' . $pid . '#p' . $pid : ''));
        exit;
    }
}

$stato = $_GET['stato'] ?? (!empty($_GET['archived']) ? 'archived' : 'active');
if (!in_array($stato, ['active', 'completed', 'archived', 'all'], true)) {
    $stato = 'active';
}
$counts = ['active' => 0, 'completed' => 0, 'archived' => 0];
try {
    $allProjects = marketingListProjects($uid, true);
    foreach ($allProjects as $p0) {
        $counts[$p0['status']]++;
    }
    $projects = array_values(array_filter($allProjects, fn ($p) => $stato === 'all' || $p['status'] === $stato));
} catch (Throwable $ex) {
    $projects = [];
    $error = $error ?? 'Il modulo Marketing non è ancora attivo su questo database: applicare la migrazione 2026_11_21_marketing_plans.sql.';
}
$openId = (int) ($_GET['open'] ?? 0);

include __DIR__ . '/_dash_header.php';
?>

  <style>
  .container { max-width: 1240px; }
  .dash-two-col { display: block; }
  .dash-two-col > div { min-width: 0; }
  @media (min-width: 760px) {
    .dash-two-col { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.6fr); gap: 20px; align-items: start; }
  }
  .mkt-step { border-left: 3px solid #c9c9d6; padding: 8px 0 8px 12px; margin: 10px 0; }
  .mkt-step.done { border-left-color: #2e9e5b; }
  .mkt-step.next { border-left-color: var(--accent); background: rgba(108,92,231,.06); border-radius: 0 8px 8px 0; }
  .mkt-step.final { border-left-style: double; border-left-width: 5px; }
  .mkt-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); }
  .mkt-badge { font-size: 11.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; color: #fff; white-space: nowrap; }
  .mkt-instr { white-space: pre-wrap; margin: 4px 0 6px; font-size: 14px; }
  .mkt-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
  .mkt-actions form { display: inline; }
  /* Elenco compatto: ogni progetto è una riga che si apre al clic. */
  .mkt-proj { border: 1px solid var(--border, #e5e5ee); border-radius: 12px; margin-bottom: 10px; background: var(--card-bg, #fff); }
  .mkt-proj > summary { list-style: none; cursor: pointer; padding: 12px 14px; display: block; }
  .mkt-proj > summary::-webkit-details-marker { display: none; }
  .mkt-proj[open] > summary { border-bottom: 1px solid var(--border, #e5e5ee); }
  .mkt-proj-body { padding: 4px 14px 14px; }
  .mkt-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .mkt-row .mkt-name { flex: 1; min-width: 0; font-weight: 700; overflow-wrap: anywhere; }
  .mkt-id { display: inline-block; background: #ecebf8; color: #4b3fc4; border-radius: 6px; padding: 1px 7px; font-size: 12px; font-weight: 700; margin-right: 6px; white-space: nowrap; }
  .mkt-chev::before { content: '▸'; display: inline-block; margin-right: 6px; transition: transform .15s; }
  .mkt-proj[open] .mkt-chev::before { transform: rotate(90deg); }
  .mkt-bar { height: 6px; border-radius: 999px; background: #e5e5ee; overflow: hidden; margin-top: 8px; }
  .mkt-bar > i { display: block; height: 100%; background: #2e9e5b; }
  .mkt-next { font-size: 12.5px; color: var(--text-muted); margin-top: 5px; }
  .mkt-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 12px; }
  .mkt-tabs a { border: 1px solid var(--border, #d6d6e2); border-radius: 999px; padding: 4px 12px; font-size: 13px; text-decoration: none; color: inherit; }
  .mkt-tabs a.on { background: var(--accent); color: var(--accent-text, #fff); border-color: var(--accent); font-weight: 700; }
  .mkt-tools { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
  .mkt-tools input { margin: 0; flex: 1; min-width: 160px; }
  .mkt-instr.clamp { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  </style>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="section-title" style="display:flex;align-items:center;gap:8px;">
    Marketing
    <button type="button" id="mkt-help-btn" title="Come funziona" aria-label="Come funziona" style="border:0;background:none;cursor:pointer;font-size:20px;line-height:1;padding:0;">❓</button>
  </div>
  <dialog id="mkt-help" style="max-width:560px;width:calc(100% - 32px);border:0;border-radius:14px;padding:20px 22px;box-shadow:0 8px 40px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;">
      <strong style="font-size:17px;">Come funziona Marketing</strong>
      <button type="button" id="mkt-help-close" aria-label="Chiudi" style="border:0;background:none;cursor:pointer;font-size:24px;line-height:1;">&times;</button>
    </div>
    <p style="margin:0 0 10px;">Ogni <strong>progetto</strong> è una serie di passi da eseguire in ordine — Passo 1, Passo 2, Passo 3… — chiusa dal <strong>Finale</strong>.</p>
    <p style="margin:0 0 10px;">Aggiungi tutti i passi che servono; quando hai finito premi <em>Inserisci Finale</em> per l'ultimo passo. Per ogni passo scrivi un titolo e le istruzioni, per te o per un'intelligenza artificiale.</p>
    <p style="margin:0 0 10px;">Le IA collegate tramite i server MCP leggono i progetti del profilo, possono crearne di nuovi e segnano i passi come completati: <strong>sempre nell'ordine</strong>, un passo alla volta, senza saltare avanti.</p>
    <p style="margin:0;">Ogni progetto ha un <strong>ID</strong> (per esempio <code>ID 12</code>): è lo stesso numero che le IA usano per leggerlo e aggiornarlo, quindi puoi passarlo a un'altra IA per farle continuare il lavoro.</p>
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
      <?php foreach (['active' => 'In corso', 'completed' => 'Completati', 'archived' => 'Archiviati'] as $k => $lbl): ?>
        <a href="/dashboard_marketing.php?stato=<?= $k ?>" class="<?= $stato === $k ? 'on' : '' ?>"><?= e($lbl) ?> (<?= (int) $counts[$k] ?>)</a>
      <?php endforeach; ?>
      <a href="/dashboard_marketing.php?stato=all" class="<?= $stato === 'all' ? 'on' : '' ?>">Tutti (<?= array_sum($counts) ?>)</a>
    </div>
    <?php if (count($projects) > 1): ?>
    <div class="mkt-tools">
      <input type="search" id="mkt-search" placeholder="Cerca per titolo o ID…" aria-label="Cerca un progetto">
      <button type="button" class="btn small secondary" id="mkt-expand">Apri tutti</button>
      <button type="button" class="btn small secondary" id="mkt-collapse">Chiudi tutti</button>
    </div>
    <?php endif; ?>
    <?php if (!$projects): ?>
      <div class="card" style="color:var(--text-muted);"><?= array_sum($counts) ? 'Nessun progetto in questo elenco.' : 'Nessun progetto ancora: creane uno qui a sinistra.' ?></div>
    <?php endif; ?>

    <?php foreach ($projects as $p):
      $steps = $p['steps'];
      $next = marketingNextStep($p);
      $hasFinal = false; $doneN = 0;
      foreach ($steps as $s) { $hasFinal = $hasFinal || (int) $s['is_final']; $doneN += $s['status'] === 'done' ? 1 : 0; }
      $badge = ['active' => ['In corso', '#6c5ce7'], 'completed' => ['Completato', '#2e9e5b'], 'archived' => ['Archiviato', '#6c757d']][$p['status']];
      $pct = $steps ? (int) round($doneN / count($steps) * 100) : 0;
      $isOpen = $openId === (int) $p['id'] || count($projects) === 1;
    ?>
    <details class="mkt-proj" id="p<?= (int) $p['id'] ?>" data-title="<?= e(mb_strtolower($p['title']) . ' id ' . (int) $p['id']) ?>"<?= $isOpen ? ' open' : '' ?>>
      <summary>
        <div class="mkt-row">
          <span class="mkt-name"><span class="mkt-chev"></span><span class="mkt-id" title="ID del progetto: lo stesso che usano le IA (MCP)">ID <?= (int) $p['id'] ?></span> <?= e($p['title']) ?></span>
          <span class="mkt-badge" style="background:<?= e($badge[1]) ?>;"><?= e($badge[0]) ?></span>
          <?php if ($p['created_by'] === 'ai'): ?><span class="mkt-badge" style="background:#0d9488;" title="Creato da un'intelligenza artificiale tramite MCP">IA</span><?php endif; ?>
          <span style="font-size:12.5px;color:var(--text-muted);white-space:nowrap;"><?= $doneN ?>/<?= count($steps) ?> passi</span>
        </div>
        <?php if ($steps): ?><div class="mkt-bar" aria-hidden="true"><i style="width:<?= $pct ?>%;"></i></div><?php endif; ?>
        <?php if ($next && $p['status'] === 'active'): ?>
          <div class="mkt-next">▶ Prossimo: <strong><?= e(marketingStepLabel($steps, (int) $next['id'])) ?></strong> — <?= e(mb_strimwidth($next['title'], 0, 90, '…')) ?></div>
        <?php elseif (!$steps): ?>
          <div class="mkt-next">Nessun passo ancora</div>
        <?php endif; ?>
      </summary>

      <div class="mkt-proj-body">
      <?php if ($p['description']): ?>
        <details style="margin:8px 0;"><summary style="cursor:pointer;color:var(--text-muted);font-size:13px;">Obiettivo del progetto</summary><p class="mkt-instr" style="color:var(--text-muted);"><?= e($p['description']) ?></p></details>
      <?php endif; ?>

      <?php foreach ($steps as $s):
        $isDone = $s['status'] === 'done';
        $isNext = $next && (int) $next['id'] === (int) $s['id'] && $p['status'] !== 'archived';
        $label = marketingStepLabel($steps, (int) $s['id']);
      ?>
      <div class="mkt-step<?= $isDone ? ' done' : '' ?><?= $isNext ? ' next' : '' ?><?= (int) $s['is_final'] ? ' final' : '' ?>">
        <div class="mkt-label">
          <?= e($label) ?>
          <?= $isDone ? '· ✅ completato' . ($s['completed_by'] === 'ai' ? ' dall\'IA' : '') . ($s['completed_at'] ? ' il ' . e(formatLocalDateTime($s['completed_at'], $profile)) : '') : ($isNext ? '· ▶ da fare adesso' : '· in attesa') ?>
        </div>
        <div style="font-weight:600;"><?= e($s['title']) ?></div>
        <?php if ($s['instructions']): ?>
          <?php if ($isNext): ?>
            <div class="mkt-instr"><?= e($s['instructions']) ?></div>
          <?php else: ?>
            <details class="mkt-instr-more"><summary style="cursor:pointer;color:var(--text-muted);font-size:12.5px;">Istruzioni</summary><div class="mkt-instr"><?= e($s['instructions']) ?></div></details>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($s['result']): ?><div class="mkt-instr" style="color:#2e9e5b;"><strong>Esito:</strong> <?= e($s['result']) ?></div><?php endif; ?>
        <div class="mkt-actions">
          <?php if ($p['status'] !== 'archived'): ?>
            <?php if (!$isDone && $isNext): ?>
              <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <?= csrfField() ?><input type="hidden" name="action" value="complete_step"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
                <input type="text" name="result" maxlength="500" placeholder="Esito (facoltativo)" style="margin:0;min-width:160px;">
                <button class="btn small" type="submit">✔ Segna completato</button>
              </form>
            <?php elseif ($isDone): ?>
              <form method="post" onsubmit="return confirm('Riaprire questo passo? Anche i passi successivi tornano da fare.');">
                <?= csrfField() ?><input type="hidden" name="action" value="reopen_step"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
                <button class="btn small secondary" type="submit">↺ Riapri</button>
              </form>
            <?php endif; ?>
            <details style="display:inline-block;">
              <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica</summary>
              <form method="post" style="margin-top:8px;">
                <?= csrfField() ?><input type="hidden" name="action" value="update_step"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
                <input type="text" name="title" maxlength="150" required value="<?= e($s['title']) ?>">
                <textarea name="instructions" rows="3"><?= e($s['instructions'] ?? '') ?></textarea>
                <button class="btn small" type="submit">Salva passo</button>
              </form>
            </details>
            <form method="post" onsubmit="return confirm('Eliminare questo passo?');">
              <?= csrfField() ?><input type="hidden" name="action" value="delete_step"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
              <button class="btn small danger" type="submit">Elimina</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if ($p['status'] !== 'archived'): ?>
        <?php if (!$hasFinal): ?>
        <details style="margin-top:10px;">
          <summary style="cursor:pointer;font-weight:600;">➕ Aggiungi un passo</summary>
          <form method="post" style="margin-top:8px;">
            <?= csrfField() ?><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
            <label>Titolo del <?= $steps ? 'passo ' . (count($steps) + 1) : 'Passo 1' ?></label>
            <input type="text" name="title" maxlength="150" required>
            <label>Istruzioni (per te o per l'IA)</label>
            <textarea name="instructions" rows="3" placeholder="Cosa va fatto in questo passo, con quali contenuti, quando…"></textarea>
            <button class="btn" type="submit" name="action" value="add_step">Aggiungi passo</button>
            <button class="btn secondary" type="submit" name="action" value="add_final" title="Aggiunge l'ultimo passo e chiude il progetto">🏁 Inserisci Finale</button>
          </form>
        </details>
        <?php else: ?>
          <p style="color:var(--text-muted);font-size:12.5px;margin:10px 0 0;">Il Finale è stato inserito: per aggiungere altri passi elimina prima il Finale.</p>
        <?php endif; ?>
      <?php endif; ?>

      <div class="mkt-actions" style="margin-top:12px;border-top:1px solid #e5e5ee;padding-top:10px;">
        <?php if ($p['status'] !== 'archived'): ?>
        <details style="display:inline-block;">
          <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica progetto</summary>
          <form method="post" style="margin-top:8px;">
            <?= csrfField() ?><input type="hidden" name="action" value="update_project"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
            <input type="text" name="title" maxlength="150" required value="<?= e($p['title']) ?>">
            <textarea name="description" rows="3"><?= e($p['description'] ?? '') ?></textarea>
            <button class="btn small" type="submit">Salva progetto</button>
          </form>
        </details>
        <?php endif; ?>
        <form method="post">
          <?= csrfField() ?><input type="hidden" name="action" value="<?= $p['status'] === 'archived' ? 'unarchive_project' : 'archive_project' ?>"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
          <button class="btn small secondary" type="submit"><?= $p['status'] === 'archived' ? 'Ripristina' : 'Archivia' ?></button>
        </form>
        <form method="post" onsubmit="return confirm('Eliminare il progetto e tutti i suoi passi? Non si può annullare.');">
          <?= csrfField() ?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?= (int) $p['id'] ?>">
          <button class="btn small danger" type="submit">Elimina progetto</button>
        </form>
      </div>
      </div>
    </details>
    <?php endforeach; ?>
  </div>
  </div>
  <script>
  (function () {
    var q = document.getElementById('mkt-search');
    var items = Array.prototype.slice.call(document.querySelectorAll('.mkt-proj'));
    if (q) q.addEventListener('input', function () {
      var t = q.value.trim().toLowerCase();
      items.forEach(function (d) { d.style.display = !t || (d.getAttribute('data-title') || '').indexOf(t) !== -1 ? '' : 'none'; });
    });
    var ex = document.getElementById('mkt-expand'), co = document.getElementById('mkt-collapse');
    if (ex) ex.addEventListener('click', function () { items.forEach(function (d) { d.open = true; }); });
    if (co) co.addEventListener('click', function () { items.forEach(function (d) { d.open = false; }); });
  })();
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
