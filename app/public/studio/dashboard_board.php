<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/api_helpers.php';
$user = requireLogin();
if (!siteToolEnabled('bacheca')) {
    http_response_code(404);
    exit('Questa funzione non è attiva su questo sito.');
}
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

include __DIR__ . '/../_dash_header.php';
$closedSet = ['approved', 'closed'];
$nOpen = 0;
foreach ($threads as $th) { $l = $th[count($th) - 1]; if (!in_array($l['status'], $closedSet, true)) { $nOpen++; } }
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($threads) ?></b><span>Conversazioni</span></div>
      <div class="tlx-kpi"><b><?= $nOpen ?></b><span>Da seguire</span></div>
      <div class="tlx-kpi"><b><?= count($threads) - $nOpen ?></b><span>Concluse</span></div>
      <div class="tlx-kpi"><b><?= count($rows) ?></b><span>Messaggi</span></div>
    </div>

    <button type="button" class="cmp-trigger" data-dlg="bd-dlg"><span class="tlx-av"><i class="fa-solid fa-thumbtack"></i></span><span class="cmp-trigger-t">Scrivi un brief, una nota, una richiesta…</span><span class="cmp-trigger-i"><i class="fa-regular fa-paper-plane"></i></span></button>

    <div class="tlx-listhead"><h2>Conversazioni <small>(<?= count($threads) ?>)</small></h2>
      <div class="tlx-filters" data-filters="#bd-list"><button type="button" class="on" data-f="all">Tutte</button><button type="button" data-f="open">Da seguire</button><button type="button" data-f="done">Concluse</button></div>
    </div>
    <?php if (!$threads): ?><div class="tlx-empty"><i class="fa-regular fa-comments"></i><b>Nessun messaggio ancora</b><span>Scrivi il primo qui sopra, oppure aspetta che una AI configurata scriva qui.</span></div><?php endif; ?>
    <div class="tlx-empty" data-filter-empty hidden><i class="fa-regular fa-circle-check"></i><b>Niente da mostrare con questo filtro</b></div>

    <div id="bd-list">
    <?php foreach ($threads as $thread): $last = $thread[count($thread) - 1]; ?>
      <section class="bdx kit-item" data-state="<?= in_array($last['status'], $closedSet, true) ? 'done' : 'open' ?>">
        <?php foreach ($thread as $m): $me = $m['author'] === 'direttore'; ?>
          <div class="bdx-m">
            <div class="bdx-h">
              <span class="bdx-av<?= $me ? ' me' : '' ?>"><?= e(mb_strtoupper(mb_substr($m['author'], 0, 1))) ?></span>
              <b><?= e($m['author']) ?></b><span class="lkx-hint">→ <?= e($m['recipient']) ?></span>
              <span class="tlx-tag"><?= e($typeLabels[$m['message_type']] ?? $m['message_type']) ?></span>
              <span class="bdx-st s-<?= e($m['status']) ?>"><?= e($statusLabels[$m['status']][0] ?? $m['status']) ?></span>
            </div>
            <p class="bdx-body"><?= e($m['body']) ?></p>
            <div class="bdx-meta"><?php if ($m['ref_type'] && $m['ref_id']): ?>Collegato: <?= e($m['ref_type']) ?> #<?= (int) $m['ref_id'] ?> · <?php endif; ?><?= e(date('d/m/Y H:i', strtotime($m['created_at']))) ?></div>
          </div>
        <?php endforeach; ?>
        <div class="bdx-foot">
          <form method="post" data-autosubmit>
            <?= csrfField() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= (int) $last['id'] ?>">
            <select name="status" aria-label="Stato della conversazione">
              <?php foreach ($statusLabels as $k => [$l, $c]): ?><option value="<?= e($k) ?>" <?= $k === $last['status'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </form>
          <form method="post" class="bdx-reply">
            <?= csrfField() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="reply_to_id" value="<?= (int) $last['id'] ?>">
            <input type="hidden" name="recipient" value="<?= e($last['author']) ?>"><input type="hidden" name="type" value="review"><input type="hidden" name="status" value="open">
            <input type="text" name="body" placeholder="Rispondi in questa conversazione..." required aria-label="Rispondi">
            <button type="submit" class="stc-act primary"><i class="fa-solid fa-paper-plane"></i> <span>Rispondi</span></button>
          </form>
        </div>
      </section>
    <?php endforeach; ?>
    </div>
  </div>

  <?php studioDlgOpen('bd-dlg', ['multipart' => false, 'new_title' => 'Nuovo messaggio', 'edit_title' => 'Nuovo messaggio', 'action_new' => 'post', 'action_edit' => 'post']); ?>
    <label for="bd-body">Messaggio</label>
    <textarea name="body" id="bd-body" rows="5" required placeholder="Scrivi un brief, una nota, una richiesta..."></textarea>
    <label for="bd-rec">Destinatario</label>
    <input type="text" name="recipient" id="bd-rec" value="all" placeholder="all, claude, grok, manus...">
    <div class="kit-row2">
      <div><label for="bd-type">Tipo</label><select name="type" id="bd-type"><?php foreach ($typeLabels as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'brief' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label for="bd-st">Stato iniziale</label><select name="status" id="bd-st"><?php foreach ($statusLabels as $k => [$l, $c]): ?><option value="<?= e($k) ?>" <?= $k === 'open' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
  <?php studioDlgClose('Pubblica'); ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
