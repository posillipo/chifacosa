<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
$activeTab = 'log';
$pageTitle = 'Log';

$stmt = getDB()->prepare('SELECT COUNT(*) c FROM profile_admins WHERE owner_user_id = ?');
$stmt->execute([$profile['id']]);
$adminCount = (int) $stmt->fetch()['c'];

$logs = [];
if ($adminCount > 0) {
    $stmt = getDB()->prepare('SELECT l.*, p.display_name AS actor_name, u.slug AS actor_slug
        FROM admin_action_logs l JOIN users u ON u.id = l.actor_user_id JOIN profiles p ON p.user_id = u.id
        WHERE l.owner_user_id = ? ORDER BY l.created_at DESC LIMIT 200');
    $stmt->execute([$profile['id']]);
    $logs = $stmt->fetchAll();
}

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx">
    <?php if ($adminCount === 0): ?>
      <div class="tlx-empty"><i class="fa-solid fa-clock-rotate-left"></i><b>Il registro non è ancora attivo</b><span>Non hai condiviso la gestione del profilo con nessuno. Vai su <a href="/dashboard_team.php">Team e co-admin</a> per farlo.</span></div>
    <?php elseif (!$logs): ?>
      <div class="tlx-empty"><i class="fa-solid fa-clock-rotate-left"></i><b>Registro attivo</b><span>Nessuna azione registrata finora.</span></div>
    <?php else: ?>
      <div class="tlx-listhead"><h2>Azioni recenti <small>(<?= count($logs) ?>)</small></h2></div>
      <?php foreach ($logs as $l): ?>
        <div class="pnx">
          <div class="pnx-b"><span class="pnx-t"><?= formatLocalDateTime($l['created_at'], $profile) ?></span><b>@<?= e($l['actor_slug']) ?> — <?= e($l['action']) ?></b><?php if ($l['details']): ?><small><?= e($l['details']) ?></small><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
