<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
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

include __DIR__ . '/../_dash_header.php';
$unreadTot = array_sum(array_map(fn ($c) => (int) $c['unread'], $conversations));
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($conversations) ?></b><span>Conversazioni</span></div>
      <div class="tlx-kpi"><b><?= $unreadTot ?></b><span>Da leggere</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_following.php"><b><i class="fa-solid fa-user-group"></i></b><span>Seguiti</span></a>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_invite.php"><b><i class="fa-solid fa-user-plus"></i></b><span>Invita</span></a>
    </div>
    <div class="tlx-listhead"><h2>Conversazioni</h2></div>
    <?php if (!$conversations): ?><div class="tlx-empty"><i class="fa-regular fa-comments"></i><b>Nessuna conversazione ancora</b><span>Puoi scrivere a chi ti segue a sua volta: vai su <a href="/dashboard_following.php">Seguiti</a>.</span></div><?php endif; ?>
    <?php foreach ($conversations as $c): ?>
      <a href="/dashboard_chat.php?with=<?= e($c['other_slug']) ?>" class="pnx<?= $c['unread'] > 0 ? ' pnx-new' : '' ?>">
        <?php if ($c['avatar_path']): ?><img class="pnx-img rnd" src="/<?= e($c['avatar_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr($c['display_name'], 0, 1))) ?></span><?php endif; ?>
        <div class="pnx-b"><b><?= e($c['display_name']) ?></b><small><?= e(textExcerpt($c['last_message'], 70)) ?></small></div>
        <div class="pnx-act"><?php if ($c['unread'] > 0): ?><span class="kit-badge"><?= (int) $c['unread'] ?></span><?php endif; ?><small class="lkx-hint"><?= $c['last_at'] ? e(date('d/m H:i', strtotime($c['last_at']))) : '' ?></small></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
