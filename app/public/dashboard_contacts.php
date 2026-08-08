<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$activeTab = 'contacts';
$pageTitle = 'Contatti';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'mark_read') {
        $stmt = getDB()->prepare('UPDATE contact_requests SET is_read=1 WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
    } elseif ($action === 'delete') {
        $stmt = getDB()->prepare('DELETE FROM contact_requests WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
    }
    header('Location: /dashboard_contacts.php');
    exit;
}

$stmt = getDB()->prepare('SELECT * FROM contact_requests WHERE user_id=? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <div class="section-title">Richieste ricevute (<?= count($requests) ?>)</div>
  <?php if (!$requests): ?>
    <div class="card">Nessuna richiesta ricevuta finora.</div>
  <?php endif; ?>
  <?php foreach ($requests as $r): ?>
    <div class="card" style="<?= $r['is_read'] ? 'opacity:0.7' : '' ?>">
      <strong><?= e($r['sender_name']) ?></strong>
      <small style="color:var(--text-muted)"> &lt;<?= e($r['sender_email']) ?>&gt; · <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></small>
      <p><?= nl2br(e($r['message'])) ?></p>
      <div style="display:flex;gap:6px;">
        <?php if (!$r['is_read']): ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="mark_read">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn small secondary" type="submit">Segna come letto</button>
        </form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Eliminare questa richiesta?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn small danger" type="submit">Elimina</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
