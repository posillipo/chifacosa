<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'service_inquiries';
$pageTitle = 'Richieste sui servizi';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'mark_read') {
        $stmt = getDB()->prepare('UPDATE service_inquiries SET is_read=1 WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'delete') {
        $stmt = getDB()->prepare('DELETE FROM service_inquiries WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    }
    header('Location: /dashboard_service_inquiries.php');
    exit;
}

$stmt = getDB()->prepare('SELECT si.*, sv.title AS service_title FROM service_inquiries si
    JOIN services sv ON sv.id = si.service_id
    WHERE si.user_id=? ORDER BY si.created_at DESC');
$stmt->execute([$profile['id']]);
$inquiries = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <div class="section-title">Richieste ricevute (<?= count($inquiries) ?>)</div>
  <?php if (!$inquiries): ?>
    <div class="card">Nessuna richiesta di informazioni ricevuta finora.</div>
  <?php endif; ?>
  <?php foreach ($inquiries as $r): ?>
    <div class="card" style="<?= $r['is_read'] ? 'opacity:0.7' : '' ?>">
      <small style="color:var(--accent);font-weight:700;">Servizio: <?= e($r['service_title']) ?></small><br>
      <strong><?= e($r['guest_name']) ?></strong>
      <small style="color:var(--text-muted)"> &lt;<?= e($r['guest_email']) ?>&gt;<?= $r['guest_phone'] ? ' · ' . e($r['guest_phone']) : '' ?> · <?= formatLocalDateTime($r['created_at'], $profile) ?></small>
      <?php if ($r['message']): ?><p><?= nl2br(e($r['message'])) ?></p><?php endif; ?>
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
