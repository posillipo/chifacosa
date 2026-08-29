<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (!$isMutual) {
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

$stmt = getDB()->prepare('SELECT * FROM direct_messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?) ORDER BY created_at ASC LIMIT 300');
$stmt->execute([$profile['id'], $other['id'], $other['id'], $profile['id']]);
$messages = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <div class="card" style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
    <?php if ($other['avatar_path']): ?>
      <img src="/<?= e($other['avatar_path']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
    <?php endif; ?>
    <div>
      <strong><?= e($other['display_name']) ?></strong><br>
      <small style="color:var(--text-muted)"><a href="/<?= e($other['slug']) ?>" target="_blank">@<?= e($other['slug']) ?> ↗</a></small>
    </div>
  </div>

  <?php if (!$isMutual): ?>
    <div class="alert error">Potete leggere questa conversazione, ma per scrivere ancora dovete seguirvi a vicenda.</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
    <?php foreach ($messages as $m): ?>
      <?php $isMine = (int) $m['sender_id'] === (int) $profile['id']; ?>
      <div style="align-self:<?= $isMine ? 'flex-end' : 'flex-start' ?>;max-width:75%;">
        <div class="card" style="margin-bottom:2px;<?= $isMine ? 'background:var(--accent);color:#fff;' : '' ?>">
          <?= nl2br(e($m['message'])) ?>
        </div>
        <small style="color:var(--text-muted);font-size:11px;"><?= date('d/m H:i', strtotime($m['created_at'])) ?></small>
      </div>
    <?php endforeach; ?>
    <?php if (!$messages): ?>
      <div class="card">Nessun messaggio ancora — scrivi il primo!</div>
    <?php endif; ?>
  </div>

  <?php if ($isMutual): ?>
    <form method="post">
      <?= csrfField() ?>
      <textarea name="message" rows="3" placeholder="Scrivi un messaggio..." required></textarea>
      <button type="submit" class="btn">Invia</button>
    </form>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
