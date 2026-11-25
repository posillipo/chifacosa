<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'messages';
$pageTitle = 'Messaggi';

$withSlug = trim($_GET['with'] ?? '');
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.email, p.display_name, p.avatar_path FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$withSlug]);
$other = $stmt->fetch();

if (!$other || (int) $other['id'] === (int) $profile['id']) {
    header('Location: /dashboard_messages.php');
    exit;
}

$isMutual = areMutualFollowers((int) $profile['id'], (int) $other['id']);
// Solo gli amministratori del sito possono scrivere anche a chi non li segue (e non seguono): uno o più messaggi.
$canWrite = $isMutual || !empty($user['is_admin']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    // «Cancella conversazione»: la nasconde solo a chi la cancella, l'altra persona continua a vederla.
    if (($_POST['action'] ?? '') === 'delete_conversation') {
        dmDeleteConversation((int) $profile['id'], (int) $other['id']);
        header('Location: /dashboard_messages.php');
        exit;
    }
    if (!$canWrite) {
        $error = 'Potete scrivervi solo se vi seguite a vicenda.';
    } else {
        $message = trim($_POST['message'] ?? '');
        if ($message !== '') {
            // Controlla se è il primo messaggio di OGGI tra questi due utenti (in entrambe le
            // direzioni) — solo in quel caso invia la notifica email, al massimo una al giorno.
            $stmt = getDB()->prepare("SELECT COUNT(*) c FROM direct_messages
                WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
                AND created_at >= CURDATE()");
            $stmt->execute([$profile['id'], $other['id'], $other['id'], $profile['id']]);
            $isFirstToday = (int) $stmt->fetch()['c'] === 0;

            $stmt = getDB()->prepare('INSERT INTO direct_messages (sender_id, recipient_id, message) VALUES (?, ?, ?)');
            $stmt->execute([$profile['id'], $other['id'], $message]);

            if ($isFirstToday) {
                $conversationUrl = siteUrl('/dashboard_chat.php?with=' . $profile['slug']);
                notifyNewMessage($other['email'], $other['display_name'], $profile['display_name'], $conversationUrl);
            }
        }
    }
    header('Location: /dashboard_chat.php?with=' . $withSlug);
    exit;
}

// Segna come letti i messaggi ricevuti da questa persona
$stmt = getDB()->prepare('UPDATE direct_messages SET read_at = NOW() WHERE sender_id = ? AND recipient_id = ? AND read_at IS NULL');
$stmt->execute([$other['id'], $profile['id']]);

$stmt = getDB()->prepare("SELECT * FROM direct_messages WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))" . dmHideDeletedSql((int) $profile['id']) . " ORDER BY created_at ASC LIMIT 300");
$stmt->execute([$profile['id'], $other['id'], $other['id'], $profile['id']]);
$messages = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
?>
  <div class="chx">
    <header class="chx-h">
      <a href="/dashboard_messages.php" class="lkx-btn" aria-label="Tutte le conversazioni"><i class="fa-solid fa-arrow-left"></i></a>
      <?php if ($other['avatar_path']): ?><img class="pnx-img rnd" src="/<?= e($other['avatar_path']) ?>" alt=""><?php else: ?><span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr($other['display_name'], 0, 1))) ?></span><?php endif; ?>
      <div class="pnx-b"><b><?= e($other['display_name']) ?></b><small><a href="/dashboard_profile_view.php?slug=<?= e($other['slug']) ?>">@<?= e($other['slug']) ?></a></small></div>
      <?php if ($messages && directMessagesHaveDeleteColumns()): ?><form method="post" class="chx-del" data-confirm="Cancellare questa conversazione? Sparisce solo da te: l'altra persona continua a vederla."><?= csrfField() ?><input type="hidden" name="action" value="delete_conversation"><button type="submit" class="stc-act danger" title="Cancella conversazione"><i class="fa-solid fa-trash"></i> <span>Cancella conversazione</span></button></form><?php endif; ?>
    </header>
    <?php if (!$canWrite): ?><div class="alert error">Potete leggere questa conversazione, ma per scrivere ancora dovete seguirvi a vicenda.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <div class="chx-list" id="chx-list">
      <?php if (!$messages): ?><div class="tlx-empty"><i class="fa-regular fa-comment-dots"></i><b>Nessun messaggio ancora</b><span>Scrivi il primo!</span></div><?php endif; ?>
      <?php $lastDay = ''; foreach ($messages as $m): $mine = (int) $m['sender_id'] === (int) $profile['id']; $day = date('d/m/Y', strtotime($m['created_at'])); ?>
        <?php if ($day !== $lastDay): $lastDay = $day; ?><div class="tlx-day chx-day"><span><?= $day === date('d/m/Y') ? 'Oggi' : e($day) ?></span></div><?php endif; ?>
        <div class="chx-m<?= $mine ? ' me' : '' ?>"><div class="chx-b"><?= nl2br(e($m['message'])) ?></div><small><?= e(date('H:i', strtotime($m['created_at']))) ?></small></div>
      <?php endforeach; ?>
    </div>

    <?php if ($canWrite): ?>
      <form method="post" class="chx-form" id="chx-form">
        <?= csrfField() ?>
        <textarea name="message" rows="1" placeholder="Scrivi un messaggio..." required aria-label="Messaggio"></textarea>
        <button type="submit" class="tlx-submit" aria-label="Invia"><i class="fa-solid fa-paper-plane"></i></button>
      </form>
    <?php endif; ?>
  </div>
  <script src="/studio/assets/studio-chat.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-chat.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
