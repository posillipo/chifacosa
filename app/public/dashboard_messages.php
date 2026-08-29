<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'messages';
$pageTitle = 'Messaggi';

// Elenco delle "altre persone" con cui esiste almeno un messaggio scambiato, con l'ultimo
// messaggio e il conteggio dei non letti, ordinato per messaggio più recente.
$stmt = getDB()->prepare("SELECT
        other.id AS other_id, other.slug AS other_slug, p.display_name, p.avatar_path,
        (SELECT message FROM direct_messages
            WHERE (sender_id = ? AND recipient_id = other.id) OR (sender_id = other.id AND recipient_id = ?)
            ORDER BY created_at DESC LIMIT 1) AS last_message,
        (SELECT created_at FROM direct_messages
            WHERE (sender_id = ? AND recipient_id = other.id) OR (sender_id = other.id AND recipient_id = ?)
            ORDER BY created_at DESC LIMIT 1) AS last_at,
        (SELECT COUNT(*) FROM direct_messages WHERE sender_id = other.id AND recipient_id = ? AND read_at IS NULL) AS unread
    FROM users other
    JOIN profiles p ON p.user_id = other.id
    WHERE other.id IN (
        SELECT recipient_id FROM direct_messages WHERE sender_id = ?
        UNION
        SELECT sender_id FROM direct_messages WHERE recipient_id = ?
    )
    ORDER BY last_at DESC");
$stmt->execute([$profile['id'], $profile['id'], $profile['id'], $profile['id'], $profile['id'], $profile['id'], $profile['id']]);
$conversations = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Puoi scrivere solo con chi ti segue e che segui a tua volta — nessun messaggio con
      sconosciuti. Chi riceve il tuo primo messaggio della giornata riceve un'email di
      notifica (senza mai rivelare il contenuto), al massimo una volta al giorno.
    </p>
  </details>

  <?php if (!$conversations): ?>
    <div class="card">
      Nessuna conversazione ancora. Vai su <a href="/dashboard_following.php">Seguiti</a> per
      scrivere a chi ti segue a sua volta.
    </div>
  <?php endif; ?>
  <?php foreach ($conversations as $c): ?>
    <a href="/dashboard_chat.php?with=<?= e($c['other_slug']) ?>" class="link-item" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;">
      <?php if ($c['avatar_path']): ?>
        <img src="/<?= e($c['avatar_path']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
      <?php else: ?>
        <div style="width:44px;height:44px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;"><?= e(mb_strtoupper(mb_substr($c['display_name'], 0, 1))) ?></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <strong><?= e($c['display_name']) ?></strong>
        <?php if ($c['unread'] > 0): ?>
          <span style="background:var(--accent);color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:999px;margin-left:6px;"><?= (int) $c['unread'] ?></span>
        <?php endif; ?>
        <br>
        <small style="color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:100%;"><?= e(textExcerpt($c['last_message'], 60)) ?></small>
      </div>
    </a>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
