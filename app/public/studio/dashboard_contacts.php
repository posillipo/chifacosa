<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
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
$unread = count(array_filter($requests, fn ($r) => !$r['is_read']));
$answered = count(array_filter($requests, fn ($r) => !empty($repliesByRequest[(int) $r['id']])));
?>
  <?php if ($flash === 'sent'): ?><div class="alert success">Risposta inviata e memorizzata.</div>
  <?php elseif ($flash === 'notsent'): ?><div class="alert error">Risposta memorizzata, ma l'email non è partita (controlla la configurazione SMTP in Admin). Puoi riprovare con "Reinvia".</div>
  <?php elseif ($flash === 'empty'): ?><div class="alert error">Scrivi un testo per la risposta (massimo 5000 caratteri).</div>
  <?php elseif ($flash === 'error'): ?><div class="alert error">Non è stato possibile salvare la risposta. Riprova.</div><?php endif; ?>
  <?php if (!$repliesReady): ?><div class="alert error">Le risposte non sono ancora attive: manca la migrazione del database (2026_11_27_contact_replies).</div><?php endif; ?>

  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($requests) ?></b><span>Richieste ricevute</span></div>
      <div class="tlx-kpi"><b><?= $unread ?></b><span>Da leggere</span></div>
      <div class="tlx-kpi"><b><?= $answered ?></b><span>Con risposta</span></div>
      <div class="tlx-kpi"><b><?= count($requests) - $answered ?></b><span>Senza risposta</span></div>
    </div>

    <div class="tlx-listhead"><h2>Messaggi</h2>
      <div class="tlx-filters" data-filters="#ibx-list">
        <button type="button" class="on" data-f="all">Tutti</button><button type="button" data-f="unread">Da leggere</button><button type="button" data-f="unanswered">Senza risposta</button><button type="button" data-f="answered">Con risposta</button>
      </div>
    </div>

    <?php if (!$requests): ?>
      <div class="tlx-empty"><i class="fa-regular fa-envelope"></i><b>Nessuna richiesta ancora</b><span>Quando qualcuno ti scrive dal modulo Contatti della tua pagina, lo trovi qui.</span></div>
    <?php endif; ?>
    <div class="tlx-empty" data-filter-empty hidden><i class="fa-regular fa-circle-check"></i><b>Niente da mostrare con questo filtro</b></div>

    <div id="ibx-list">
    <?php foreach ($requests as $r):
      $reps = $repliesByRequest[(int) $r['id']] ?? [];
      $state = trim(($r['is_read'] ? '' : 'unread ') . ($reps ? 'answered' : 'unanswered'));
    ?>
      <article class="ibx kit-item<?= !$r['is_read'] ? ' is-new' : '' ?>" id="req-<?= (int) $r['id'] ?>" data-state="<?= e($state) ?>">
        <header class="ibx-h">
          <span class="ibx-av"><?= e(mb_strtoupper(mb_substr($r['sender_name'], 0, 1))) ?></span>
          <div class="ibx-who"><b><?= e($r['sender_name']) ?></b><a href="mailto:<?= e($r['sender_email']) ?>"><?= e($r['sender_email']) ?></a></div>
          <div class="ibx-meta">
            <?php if (!$r['is_read']): ?><span class="stc-status stc-s-warn"><i class="fa-solid fa-circle"></i> Nuovo</span><?php endif; ?>
            <?php if ($reps): ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Risposto</span><?php endif; ?>
            <time><?= e(formatLocalDateTime($r['created_at'], $profile)) ?></time>
          </div>
        </header>
        <p class="ibx-msg"><?= nl2br(e($r['message'])) ?></p>

        <?php foreach ($reps as $rep): ?>
          <div class="ibx-rep">
            <div class="ibx-rep-h"><b>La tua risposta</b> · <?= e(formatLocalDateTime($rep['created_at'], $profile)) ?>
              <?php if ($rep['sent_ok']): ?><span class="ibx-ok"><i class="fa-solid fa-check"></i> inviata</span><?php else: ?><span class="ibx-ko"><i class="fa-solid fa-triangle-exclamation"></i> NON inviata</span><?php endif; ?>
              <?= !empty($rep['created_by']) ? authorBadgeHtml($rep['created_by']) : '' ?></div>
            <div><?= nl2br(e($rep['body'])) ?></div>
            <?php if (!$rep['sent_ok']): ?>
              <form method="post" class="ibx-inline"><?= csrfField() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="reply_id" value="<?= (int) $rep['id'] ?>"><button class="stc-act" type="submit"><i class="fa-solid fa-rotate"></i> <span>Reinvia</span></button></form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($repliesReady): ?>
        <details class="ibx-reply">
          <summary class="stc-act primary"><i class="fa-solid fa-reply"></i> <span><?= $reps ? 'Rispondi ancora' : 'Rispondi' ?></span></summary>
          <form method="post" class="ibx-form"><?= csrfField() ?>
            <input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <label>La tua risposta a <?= e($r['sender_name']) ?></label>
            <textarea name="reply" rows="6" maxlength="5000" required placeholder="Scrivi qui la risposta…"></textarea>
            <p class="tlx-hint">Parte per email a <?= e($r['sender_email']) ?>, con il suo messaggio citato e un invito a creare gratis un profilo su <?= e(siteName()) ?>. Le risposte arrivano alla tua email e la risposta resta memorizzata qui.</p>
            <button class="tlx-submit ibx-send" type="submit"><i class="fa-solid fa-paper-plane"></i> <span>Invia risposta</span></button>
          </form>
        </details>
        <?php endif; ?>

        <div class="stc-actions ibx-act">
          <?php if (!$r['is_read']): ?>
            <form method="post" class="ibx-inline"><?= csrfField() ?><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="stc-act" type="submit"><i class="fa-solid fa-check"></i> <span>Segna come letto</span></button></form>
          <?php endif; ?>
          <form method="post" class="ibx-inline" data-confirm="Eliminare questa richiesta<?= $reps ? ' e le risposte memorizzate' : '' ?>?"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="stc-act danger" type="submit"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button></form>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
