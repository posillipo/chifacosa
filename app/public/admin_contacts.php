<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'contacts';
$pageTitle = 'Contatti ricevuti';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $cid = (int) ($_POST['id'] ?? 0);
    if ($action === 'mark_read') {
        getDB()->prepare('UPDATE contact_requests SET is_read = 1 WHERE id = ?')->execute([$cid]);
    } elseif ($action === 'delete') {
        getDB()->prepare('DELETE FROM contact_requests WHERE id = ?')->execute([$cid]);
    }
    header('Location: /admin_contacts.php');
    exit;
}

$stmt = getDB()->query('SELECT c.*, u.slug, p.display_name
                         FROM contact_requests c
                         JOIN users u ON u.id = c.user_id
                         JOIN profiles p ON p.user_id = u.id
                         ORDER BY c.created_at DESC
                         LIMIT 200');
$contacts = $stmt->fetchAll();

include __DIR__ . '/_admin_header.php';
?>
  <div class="section-title">Tutte le richieste di contatto/booking (<?= count($contacts) ?>, ultime 200)</div>

  <?php if (!$contacts): ?><div class="card">Nessuna richiesta ricevuta su nessun profilo.</div><?php endif; ?>

  <?php foreach ($contacts as $c): ?>
    <div class="card" style="<?= $c['is_read'] ? 'opacity:0.7' : '' ?>">
      <small style="color:var(--text-muted)">Ricevuto dal profilo:</small>
      <a href="/admin_user_detail.php?id=<?= (int)$c['user_id'] ?>"><strong><?= e($c['display_name']) ?></strong></a>
      <span style="color:var(--text-muted)"> (<?= e(siteName()) ?>/<?= e($c['slug']) ?>)</span>
      <br><br>
      <strong><?= e($c['sender_name']) ?></strong>
      <small style="color:var(--text-muted)"> &lt;<?= e($c['sender_email']) ?>&gt; · <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></small>
      <p><?= nl2br(e($c['message'])) ?></p>
      <div style="display:flex;gap:6px;">
        <?php if (!$c['is_read']): ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="mark_read">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="btn small secondary" type="submit">Segna come letto</button>
        </form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Eliminare questa richiesta?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="btn small danger" type="submit">Elimina</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
