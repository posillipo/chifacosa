<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Il registro si attiva automaticamente quando condividi la gestione del profilo con
      almeno un'altra persona (Team e co-admin) — da quel momento, ogni azione fatta da un
      co-admin (aggiornamenti in Timeline, nuovi brani, ecc.) viene registrata qui, con nome di
      chi l'ha fatta e quando.
    </p>
  </details>

  <?php if ($adminCount === 0): ?>
    <div class="card">
      Il registro non è ancora attivo: non hai condiviso la gestione del profilo con nessuno.
      Vai su <a href="/dashboard_team.php">Team e co-admin</a> per farlo.
    </div>
  <?php elseif (!$logs): ?>
    <div class="card">Registro attivo, ma nessuna azione registrata finora.</div>
  <?php else: ?>
    <div class="section-title">Azioni recenti (<?= count($logs) ?>)</div>
    <?php foreach ($logs as $l): ?>
      <div class="link-item">
        <div>
          <strong>@<?= e($l['actor_slug']) ?></strong> — <?= e($l['action']) ?>
          <?php if ($l['details']): ?><br><small style="color:var(--text-muted)"><?= e($l['details']) ?></small><?php endif; ?>
        </div>
        <small style="color:var(--text-muted)"><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></small>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
