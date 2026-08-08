<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$activeTab = 'followers';
$pageTitle = 'Follower';

$total = getFollowerCount((int)$user['id']);

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM followers WHERE user_id=? AND verified=1 AND created_at >= NOW() - INTERVAL 7 DAY");
$stmt->execute([$user['id']]);
$last7 = (int) $stmt->fetch()['c'];

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM followers WHERE user_id=? AND verified=1 AND created_at >= NOW() - INTERVAL 30 DAY");
$stmt->execute([$user['id']]);
$last30 = (int) $stmt->fetch()['c'];

$stmt = getDB()->prepare('SELECT email, created_at FROM followers WHERE user_id=? AND verified=1 ORDER BY created_at DESC LIMIT 200');
$stmt->execute([$user['id']]);
$followers = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      I visitatori della tua pagina pubblica possono iscriversi lasciando la propria email (con
      doppia conferma anti-spam). Quando pubblichi un nuovo articolo sul blog o un nuovo
      concerto, i tuoi follower ricevono automaticamente una notifica via email.
    </p>
  </details>

  <div class="card">
    <strong><?= $total ?></strong> follower totali ·
    <?= $last7 ?> negli ultimi 7 giorni · <?= $last30 ?> negli ultimi 30 giorni
  </div>

  <div class="section-title">Elenco follower (<?= count($followers) ?>, ultimi 200)</div>
  <?php if (!$followers): ?>
    <div class="card">Nessun follower ancora. Il pulsante "Segui" è già visibile sulla tua pagina pubblica.</div>
  <?php endif; ?>
  <?php foreach ($followers as $f): ?>
    <div class="link-item">
      <span><?= e($f['email']) ?></span>
      <small style="color:var(--text-muted)">dal <?= date('d/m/Y', strtotime($f['created_at'])) ?></small>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
