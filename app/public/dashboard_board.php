<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'board';
$pageTitle = 'Bacheca';
$error = null;

// Interfaccia umana sulla stessa bacheca condivisa con le AI (board_messages), finora
// raggiungibile solo via API/MCP con firma per-attore (vedi api_board_*.php). Qui chi scrive è
// sempre e solo "direttore": l'autenticazione è la sessione dashboard già loggata (login + CSRF +
// requireFullOwnerAccess), più forte di qualunque firma — niente firma da inserire, niente scelta
// di "author", per costruzione non è impersonabile. Scrive direttamente sulla tabella, non passa
// dall'endpoint pubblico (quello resta per i chiamanti esterni col solo token API).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'post' || $action === 'reply') {
        $body = trim($_POST['body'] ?? '');
        $recipient = apiNormalizeBoardActor((string) ($_POST['recipient'] ?? 'all')) ?? 'all';
        $type = in_array($_POST['type'] ?? 'note', BOARD_MESSAGE_TYPES, true) ? $_POST['type'] : 'note';
        $status = in_array($_POST['status'] ?? 'open', BOARD_STATUSES, true) ? $_POST['status'] : 'open';

        if ($body === '') {
            $error = 'Scrivi qualcosa prima di pubblicare.';
        } else {
            $threadId = null;
            $replyToId = null;
            if ($action === 'reply') {
                $replyToId = (int) ($_POST['reply_to_id'] ?? 0);
                $stmt = getDB()->prepare('SELECT id, thread_id FROM board_messages WHERE id = ? AND user_id = ?');
                $stmt->execute([$replyToId, $profile['id']]);
                $parent = $stmt->fetch();
                if (!$parent) {
                    $error = 'Il messaggio a cui stai rispondendo non esiste più.';
                    $replyToId = null;
                } else {
                    $threadId = $parent['thread_id'] !== null ? (int) $parent['thread_id'] : (int) $parent['id'];
                }
            }
            if ($error === null) {
                $stmt = getDB()->prepare('INSERT INTO board_messages (user_id, thread_id, reply_to_id, author, recipient, message_type, status, body) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$profile['id'], $threadId, $replyToId, 'direttore', $recipient, $type, $status, $body]);
                $newId = (int) getDB()->lastInsertId();
                if ($threadId === null) {
                    getDB()->prepare('UPDATE board_messages SET thread_id = ? WHERE id = ?')->execute([$newId, $newId]);
                }
            }
        }
    } elseif ($action === 'set_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        if (in_array($status, BOARD_STATUSES, true)) {
            getDB()->prepare('UPDATE board_messages SET status = ? WHERE id = ? AND user_id = ?')->execute([$status, $id, $profile['id']]);
        }
    }
}

$stmt = getDB()->prepare('SELECT * FROM board_messages WHERE user_id = ? ORDER BY thread_id DESC, id ASC');
$stmt->execute([$profile['id']]);
$rows = $stmt->fetchAll();
$threads = [];
foreach ($rows as $r) {
    $threads[(int) $r['thread_id']][] = $r;
}

$typeLabels = ['brief' => 'Incarico', 'delivery' => 'Consegna', 'review' => 'Revisione', 'note' => 'Nota'];
$statusLabels = [
    'open' => ['Aperto', '#8aa4ff'],
    'in_progress' => ['In corso', '#f0c419'],
    'delivered' => ['Consegnato', '#8aa4ff'],
    'awaiting_approval' => ['In attesa di approvazione', '#ff9f43'],
    'approved' => ['Approvato', '#5cb85c'],
    'rework' => ['Da rivedere', '#ff8a8a'],
    'closed' => ['Chiuso', '#888'],
];

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Stessa bacheca su cui scrivono le AI (Claude, Grok, Manus...) via API — qui la usi come
      "direttore" senza bisogno di firma, perché il tuo accesso è già garantito dal login. I
      messaggi sono raggruppati per conversazione (thread): quello più recente in cima.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="post">
    <label>Nuovo messaggio</label>
    <textarea name="body" rows="3" required placeholder="Scrivi un brief, una nota, una richiesta..." style="margin-bottom:8px;"></textarea>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <div style="flex:1;min-width:120px;">
        <label style="font-size:12.5px;">Destinatario</label>
        <input type="text" name="recipient" placeholder="all, claude, grok, manus..." value="all" style="margin-bottom:0;">
      </div>
      <div style="flex:1;min-width:120px;">
        <label style="font-size:12.5px;">Tipo</label>
        <select name="type" style="margin-bottom:0;">
          <?php foreach ($typeLabels as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $k === 'brief' ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:120px;">
        <label style="font-size:12.5px;">Stato iniziale</label>
        <select name="status" style="margin-bottom:0;">
          <?php foreach ($statusLabels as $k => [$l, $c]): ?>
            <option value="<?= e($k) ?>" <?= $k === 'open' ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn" style="margin-top:10px;">Pubblica</button>
  </form>

  <div class="section-title">Conversazioni (<?= count($threads) ?>)</div>
  <?php if (!$threads): ?>
    <div class="card">Nessun messaggio ancora. Scrivi il primo qui sopra, oppure aspetta che una AI configurata scriva qui.</div>
  <?php endif; ?>

  <?php foreach ($threads as $thread): ?>
    <?php $last = $thread[count($thread) - 1]; ?>
    <div class="card">
      <?php foreach ($thread as $i => $m): ?>
        <div style="<?= $i > 0 ? 'margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.08);' : '' ?>">
          <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <strong><?= e($m['author']) ?></strong>
            <span style="color:var(--text-muted);font-size:12.5px;">
              → <?= e($m['recipient']) ?> · <?= e($typeLabels[$m['message_type']] ?? $m['message_type']) ?>
              · <span style="color:<?= e($statusLabels[$m['status']][1] ?? '#888') ?>;"><?= e($statusLabels[$m['status']][0] ?? $m['status']) ?></span>
            </span>
          </div>
          <p style="white-space:pre-wrap;margin:6px 0;"><?= e($m['body']) ?></p>
          <?php if ($m['ref_type'] && $m['ref_id']): ?>
            <small style="color:var(--text-muted)">Collegato: <?= e($m['ref_type']) ?> #<?= (int) $m['ref_id'] ?></small><br>
          <?php endif; ?>
          <small style="color:var(--text-muted)"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></small>
        </div>
      <?php endforeach; ?>

      <div style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.08);display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        <form method="post" style="display:flex;gap:6px;align-items:center;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="id" value="<?= (int) $last['id'] ?>">
          <select name="status" style="margin-bottom:0;">
            <?php foreach ($statusLabels as $k => [$l, $c]): ?>
              <option value="<?= e($k) ?>" <?= $k === $last['status'] ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn small">Aggiorna stato</button>
        </form>
      </div>

      <form method="post" style="margin-top:8px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="reply_to_id" value="<?= (int) $last['id'] ?>">
        <div style="display:flex;gap:8px;">
          <input type="text" name="body" placeholder="Rispondi in questa conversazione..." required style="flex:1;margin-bottom:0;">
          <input type="hidden" name="recipient" value="<?= e($last['author']) ?>">
          <input type="hidden" name="type" value="review">
          <input type="hidden" name="status" value="open">
          <button type="submit" class="btn small" style="width:auto;">Rispondi</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
