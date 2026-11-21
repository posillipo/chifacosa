<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'contacts';
$pageTitle = 'Contatti';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'mark_read') {
        $stmt = getDB()->prepare('UPDATE contact_requests SET is_read=1 WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'delete') {
        $stmt = getDB()->prepare('DELETE FROM contact_requests WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'reply' || $action === 'resend') {
        $stmt = getDB()->prepare('SELECT * FROM contact_requests WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        $req = $stmt->fetch();
        $flash = 'error';
        if ($req) {
            try {
                if ($action === 'reply') {
                    $text = trim($_POST['reply'] ?? '');
                    if ($text === '' || mb_strlen($text) > 5000) {
                        $flash = 'empty';
                        throw new RuntimeException('empty');
                    }
                    // La risposta si memorizza SEMPRE, anche se l'invio non va a buon fine.
                    getDB()->prepare('INSERT INTO contact_replies (contact_request_id, user_id, body, created_by) VALUES (?,?,?,?)')
                        ->execute([$id, $profile['id'], $text, dashboardAuthorSignature($user)]);
                    $replyId = (int) getDB()->lastInsertId();
                } else {
                    $replyId = (int) ($_POST['reply_id'] ?? 0);
                    $rs = getDB()->prepare('SELECT * FROM contact_replies WHERE id=? AND contact_request_id=? AND user_id=?');
                    $rs->execute([$replyId, $id, $profile['id']]);
                    $text = (string) (($rs->fetch() ?: [])['body'] ?? '');
                    if ($text === '') {
                        throw new RuntimeException('missing');
                    }
                }
                $ok = notifyContactReply($req, $profile, $text);
                if ($ok) {
                    getDB()->prepare('UPDATE contact_replies SET sent_ok=1, sent_at=NOW() WHERE id=?')->execute([$replyId]);
                }
                getDB()->prepare('UPDATE contact_requests SET is_read=1 WHERE id=?')->execute([$id]);
                $flash = $ok ? 'sent' : 'notsent';
            } catch (Throwable $e) {
                if ($flash === 'error') {
                    error_log('dashboard_contacts reply: ' . $e->getMessage());
                }
            }
        }
        header('Location: /dashboard_contacts.php?r=' . $flash . '#req-' . $id);
        exit;
    }
    header('Location: /dashboard_contacts.php');
    exit;
}

$stmt = getDB()->prepare('SELECT * FROM contact_requests WHERE user_id=? ORDER BY created_at DESC');
$stmt->execute([$profile['id']]);
$requests = $stmt->fetchAll();

// Risposte già scritte, raggruppate per richiesta. Se la tabella non esiste ancora (migrazione non
// eseguita) la pagina funziona lo stesso, senza storico e senza risposte.
$repliesByRequest = [];
$repliesReady = true;
try {
    $rs = getDB()->prepare('SELECT * FROM contact_replies WHERE user_id=? ORDER BY created_at ASC');
    $rs->execute([$profile['id']]);
    foreach ($rs->fetchAll() as $rep) {
        $repliesByRequest[(int) $rep['contact_request_id']][] = $rep;
    }
} catch (Throwable $e) {
    $repliesReady = false;
}
$flash = $_GET['r'] ?? '';

include __DIR__ . '/../_dash_header.php';
?>
  <?php if ($flash === 'sent'): ?><div class="alert success">Risposta inviata e memorizzata.</div>
  <?php elseif ($flash === 'notsent'): ?><div class="alert error">Risposta memorizzata, ma l'email non è partita (controlla la configurazione SMTP in Admin). Puoi riprovare con "Reinvia".</div>
  <?php elseif ($flash === 'empty'): ?><div class="alert error">Scrivi un testo per la risposta (massimo 5000 caratteri).</div>
  <?php elseif ($flash === 'error'): ?><div class="alert error">Non è stato possibile salvare la risposta. Riprova.</div><?php endif; ?>
  <?php if (!$repliesReady): ?><div class="alert error">Le risposte non sono ancora attive: manca la migrazione del database (2026_11_27_contact_replies).</div><?php endif; ?>
  <div class="section-title">Richieste ricevute (<?= count($requests) ?>)</div>
  <?php if (!$requests): ?>
    <div class="card">Nessuna richiesta ricevuta finora.</div>
  <?php endif; ?>
  <?php foreach ($requests as $r): ?>
    <?php $reps = $repliesByRequest[(int) $r['id']] ?? []; ?>
    <div class="card" id="req-<?= (int) $r['id'] ?>" style="<?= $r['is_read'] && !$reps ? 'opacity:0.7' : '' ?>">
      <strong><?= e($r['sender_name']) ?></strong>
      <small style="color:var(--text-muted)"> &lt;<?= e($r['sender_email']) ?>&gt; · <?= formatLocalDateTime($r['created_at'], $profile) ?></small>
      <?php if ($reps): ?><span style="background:#27ae60;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px;">✓ Risposto</span><?php endif; ?>
      <p><?= nl2br(e($r['message'])) ?></p>

      <?php foreach ($reps as $rep): ?>
        <div style="margin:0 0 12px 18px;padding:10px 14px;border-left:3px solid var(--accent);background:var(--bg-alt,#f6f6f9);border-radius:0 10px 10px 0;">
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">
            <strong>La tua risposta</strong> · <?= e(formatLocalDateTime($rep['created_at'], $profile)) ?>
            <?= $rep['sent_ok'] ? '· <span style="color:#27ae60;font-weight:700;">inviata ✓</span>' : '· <span style="color:#e74c3c;font-weight:700;">NON inviata</span>' ?>
            <?= !empty($rep['created_by']) ? authorBadgeHtml($rep['created_by']) : '' ?>
          </div>
          <div style="font-size:14px;"><?= nl2br(e($rep['body'])) ?></div>
          <?php if (!$rep['sent_ok']): ?>
            <form method="post" style="margin-top:8px;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="resend">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="reply_id" value="<?= (int) $rep['id'] ?>">
              <button class="btn small secondary" type="submit">↻ Reinvia</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($repliesReady): ?>
      <details style="margin-bottom:10px;" <?= $flash && (int) ($_GET['id'] ?? 0) === (int) $r['id'] ? 'open' : '' ?>>
        <summary class="btn small" style="display:inline-block;cursor:pointer;"><i class="fa-solid fa-reply"></i> <?= $reps ? 'Rispondi ancora' : 'Rispondi' ?></summary>
        <form method="post" style="margin-top:10px;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reply">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <label>La tua risposta a <?= e($r['sender_name']) ?></label>
          <textarea name="reply" rows="6" maxlength="5000" required placeholder="Scrivi qui la risposta…"></textarea>
          <p style="color:var(--text-muted);font-size:12.5px;margin:-6px 0 10px;">
            Viene inviata per email a <?= e($r['sender_email']) ?>, con il suo messaggio citato e un invito a creare gratis un profilo su <?= e(siteName()) ?>. Le risposte dell'interessato arrivano alla tua email. La risposta resta memorizzata qui.
          </p>
          <button class="btn" type="submit">Invia risposta</button>
        </form>
      </details>
      <?php endif; ?>
      <div style="display:flex;gap:6px;">
        <?php if (!$r['is_read']): ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="mark_read">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn small secondary" type="submit">Segna come letto</button>
        </form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Eliminare questa richiesta<?= $reps ? ' e le risposte memorizzate' : '' ?>?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn small danger" type="submit">Elimina</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
