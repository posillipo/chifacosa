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
            if (isset($r['error'])) { $error = $r['error']; } else { header('Location: /dashboard_marketing.php?open=' . $r['id'] . '#p' . $r['id']); exit; }
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
        header('Location: /dashboard_marketing.php' . ($pid ? '?open=' . $pid . '#p' . $pid : ''));
        exit;
    }
}

try {
    $showArchived = !empty($_GET['archived']);
    $projects = marketingListProjects($uid, $showArchived);
    if ($showArchived) {
        $projects = array_values(array_filter($projects, fn ($p) => $p['status'] === 'archived'));
    }
    $archivedCount = (int) getDB()->query("SELECT COUNT(*) FROM marketing_projects WHERE status='archived' AND user_id=" . $uid)->fetchColumn();
} catch (Throwable $ex) {
    $projects = [];
    $archivedCount = 0;
    $error = $error ?? 'Il modulo Marketing non è ancora attivo su questo database: applicare la migrazione 2026_11_21_marketing_plans.sql.';
}
$openId = (int) ($_GET['open'] ?? 0);

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      <strong>Marketing &gt; Pianificate</strong>: ogni <strong>progetto</strong> è una serie di passi da eseguire
      in ordine — Passo 1, Passo 2, Passo 3… — chiusa dal <strong>Finale</strong>. Aggiungi tutti i passi che servono;
      quando hai finito premi <em>Inserisci Finale</em> per l'ultimo passo. Per ogni passo scrivi un titolo e le
      istruzioni (per te o per un'intelligenza artificiale). Le IA collegate tramite i server MCP leggono i progetti
      del profilo, possono crearne di nuovi e segnano i passi come completati: <strong>sempre nell'ordine</strong>, un
      passo alla volta, senza saltare avanti.
    </p>
  </details>

  <style>
  .container { max-width: 1240px; }
  .dash-two-col { display: block; }
  .dash-two-col > div { min-width: 0; }
  @media (min-width: 760px) {
    .dash-two-col { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.6fr); gap: 20px; align-items: start; }
  }
  .mkt-area { display: flex; gap: 8px; margin: 0 0 14px; flex-wrap: wrap; }
  .mkt-area span { background: var(--accent); color: var(--accent-text, #fff); border-radius: 999px; padding: 5px 14px; font-weight: 700; font-size: 13.5px; }
  .mkt-step { border-left: 3px solid #c9c9d6; padding: 8px 0 8px 12px; margin: 10px 0; }
  .mkt-step.done { border-left-color: #2e9e5b; }
  .mkt-step.next { border-left-color: var(--accent); background: rgba(108,92,231,.06); border-radius: 0 8px 8px 0; }
  .mkt-step.final { border-left-style: double; border-left-width: 5px; }
  .mkt-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); }
  .mkt-badge { font-size: 11.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; color: #fff; white-space: nowrap; }
  .mkt-instr { white-space: pre-wrap; margin: 4px 0 6px; font-size: 14px; }
  .mkt-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
  .mkt-actions form { display: inline; }
  </style>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="section-title">Marketing</div>
  <div class="mkt-area"><span>Pianificate</span></div>

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
    <?php if ($archivedCount > 0 || $showArchived): ?>
      <p style="font-size:13.5px;">
        <?php if ($showArchived): ?><a href="/dashboard_marketing.php">← Torna ai progetti</a>
        <?php else: ?><a href="/dashboard_marketing.php?archived=1">Mostra i progetti archiviati (<?= $archivedCount ?>)</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>

  <div>
    <div class="section-title" style="margin-top:0;"><?= $showArchived ? 'Progetti archiviati' : 'Progetti' ?> (<?= count($projects) ?>)</div>
    <?php if (!$projects): ?>
      <div class="card" style="color:var(--text-muted);"><?= $showArchived ? 'Nessun progetto archiviato.' : 'Nessun progetto ancora: creane uno qui a sinistra.' ?></div>
    <?php endif; ?>

    <?php foreach ($projects as $pi => $p):
      $steps = $p['steps'];
      $next = marketingNextStep($p);
      $hasFinal = false; $doneN = 0;
      foreach ($steps as $s) { $hasFinal = $hasFinal || (int) $s['is_final']; $doneN += $s['status'] === 'done' ? 1 : 0; }
      $badge = ['active' => ['In corso', '#6c5ce7'], 'completed' => ['Completato', '#2e9e5b'], 'archived' => ['Archiviato', '#6c757d']][$p['status']];
    ?>
    <div class="card" id="p<?= (int) $p['id'] ?>">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <strong style="flex:1;min-width:0;overflow-wrap:anywhere;">Progetto <?= count($projects) - $pi ?> — <?= e($p['title']) ?></strong>
        <span class="mkt-badge" style="background:<?= e($badge[1]) ?>;"><?= e($badge[0]) ?></span>
        <?php if ($steps): ?><span style="font-size:12.5px;color:var(--text-muted);"><?= $doneN ?>/<?= count($steps) ?> passi</span><?php endif; ?>
        <?php if ($p['created_by'] === 'ai'): ?><span class="mkt-badge" style="background:#0d9488;" title="Creato da un'intelligenza artificiale tramite MCP">IA</span><?php endif; ?>
      </div>
      <?php if ($p['description']): ?><p class="mkt-instr" style="color:var(--text-muted);"><?= e($p['description']) ?></p><?php endif; ?>

      <?php if (!$steps): ?><p style="color:var(--text-muted);font-size:13.5px;">Nessun passo ancora: aggiungi il Passo 1 qui sotto.</p><?php endif; ?>
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
        <?php if ($s['instructions']): ?><div class="mkt-instr"><?= e($s['instructions']) ?></div><?php endif; ?>
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
        <details<?= $openId === (int) $p['id'] ? ' open' : '' ?> style="margin-top:10px;">
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
    <?php endforeach; ?>
  </div>
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
