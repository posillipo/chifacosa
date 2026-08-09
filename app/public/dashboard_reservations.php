<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
requireBandOrLabel($user);
$activeTab = 'reservations';
$pageTitle = 'Prenotazioni';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $validStatuses = ['confirmed', 'declined', 'cancelled', 'no_show', 'completed'];

    if ($action === 'update_status' && in_array($_POST['status'] ?? '', $validStatuses, true)) {
        $stmt = getDB()->prepare('UPDATE table_reservations SET status = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$_POST['status'], $id, $user['id']]);
    }
    header('Location: /dashboard_reservations.php?view=' . urlencode($_GET['view'] ?? 'requests'));
    exit;
}

$view = ($_GET['view'] ?? 'requests') === 'clients' ? 'clients' : 'requests';

$stmt = getDB()->prepare('SELECT tr.*, ev.title AS event_title, ev.event_date
                          FROM table_reservations tr
                          LEFT JOIN events ev ON ev.id = tr.event_id
                          WHERE tr.user_id = ?
                          ORDER BY ev.event_date DESC, tr.created_at DESC');
$stmt->execute([$user['id']]);
$reservations = $stmt->fetchAll();

$stmt = getDB()->prepare("SELECT guest_email,
                                  SUBSTRING_INDEX(GROUP_CONCAT(guest_name ORDER BY created_at DESC), ',', 1) AS guest_name,
                                  SUBSTRING_INDEX(GROUP_CONCAT(guest_phone ORDER BY created_at DESC), ',', 1) AS guest_phone,
                                  COUNT(*) AS reservation_count,
                                  MAX(created_at) AS last_reservation_at,
                                  SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_show_count
                          FROM table_reservations
                          WHERE user_id = ?
                          GROUP BY guest_email
                          ORDER BY last_reservation_at DESC");
$stmt->execute([$user['id']]);
$clients = $stmt->fetchAll();

$statusLabels = [
    'pending' => 'In attesa',
    'confirmed' => 'Confermata',
    'declined' => 'Rifiutata',
    'cancelled' => 'Annullata',
    'no_show' => 'Non presentato',
    'completed' => 'Presentato',
];
$statusColors = [
    'pending' => '#f2b84b',
    'confirmed' => 'var(--accent)',
    'declined' => '#ff8a8a',
    'cancelled' => 'var(--text-muted)',
    'no_show' => '#ff8a8a',
    'completed' => '#5fd68a',
];

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Le prenotazioni arrivano dagli eventi per cui hai attivato "Accetta prenotazioni tavolo"
      (da Eventi). Qui puoi vedere le singole richieste ed anche i tuoi clienti — chi ha già
      prenotato almeno una volta, con l'email e il telefono lasciati, per poterli ricontattare
      quando vuoi.
    </p>
  </details>

  <div class="tabs">
    <a href="/dashboard_reservations.php?view=requests" class="<?= $view === 'requests' ? 'active' : '' ?>">Richieste (<?= count($reservations) ?>)</a>
    <a href="/dashboard_reservations.php?view=clients" class="<?= $view === 'clients' ? 'active' : '' ?>">Clienti (<?= count($clients) ?>)</a>
  </div>

  <?php if ($view === 'requests'): ?>
    <?php if (!$reservations): ?>
      <div class="alert error">Nessuna prenotazione ancora. Attiva "Accetta prenotazioni tavolo" su un evento per iniziare a riceverle.</div>
    <?php endif; ?>
    <?php foreach ($reservations as $r): ?>
      <div class="link-item" style="align-items:flex-start;">
        <div>
          <strong><?= e($r['guest_name']) ?></strong>
          <span style="color:var(--text-muted);"> · <?= (int) $r['party_size'] ?> person<?= (int) $r['party_size'] === 1 ? 'a' : 'e' ?></span>
          <span style="color:<?= $statusColors[$r['status']] ?? 'var(--text-muted)' ?>;font-weight:700;font-size:12px;"> · <?= e($statusLabels[$r['status']] ?? $r['status']) ?></span>
          <br><small style="color:var(--text-muted)">
            <?= e($r['event_title'] ?? 'Evento eliminato') ?><?= $r['event_date'] ? ' · ' . date('d/m/Y H:i', strtotime($r['event_date'])) : '' ?>
          </small>
          <br><small><a href="mailto:<?= e($r['guest_email']) ?>"><?= e($r['guest_email']) ?></a><?= $r['guest_phone'] ? ' · ' . e($r['guest_phone']) : '' ?></small>
          <?php if ($r['notes']): ?><br><small style="color:var(--text-muted)">Note: <?= e($r['notes']) ?></small><?php endif; ?>
        </div>
        <?php if ($r['status'] === 'confirmed'): ?>
          <div class="icon-btn-group">
            <form method="post" title="Segna presentato">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="status" value="completed">
              <button class="icon-btn" type="submit"><i class="fa-solid fa-check"></i></button>
            </form>
            <form method="post" title="Non presentato">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="status" value="no_show">
              <button class="icon-btn" type="submit"><i class="fa-solid fa-user-xmark"></i></button>
            </form>
            <form method="post" title="Annulla prenotazione">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="status" value="cancelled">
              <button class="icon-btn danger" type="submit"><i class="fa-solid fa-xmark"></i></button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <?php if (!$clients): ?>
      <div class="alert error">Ancora nessun cliente.</div>
    <?php endif; ?>
    <?php foreach ($clients as $c): ?>
      <div class="link-item">
        <div>
          <strong><?= e($c['guest_name']) ?></strong>
          <span style="color:var(--text-muted);"> · <?= (int) $c['reservation_count'] ?> prenotazion<?= (int) $c['reservation_count'] === 1 ? 'e' : 'i' ?></span>
          <?php if ((int) $c['no_show_count'] > 0): ?>
            <span style="color:#ff8a8a;font-size:12px;"> · <?= (int) $c['no_show_count'] ?> non presentato/i</span>
          <?php endif; ?>
          <br><small><a href="mailto:<?= e($c['guest_email']) ?>"><?= e($c['guest_email']) ?></a><?= $c['guest_phone'] ? ' · ' . e($c['guest_phone']) : '' ?></small>
          <br><small style="color:var(--text-muted)">Ultima prenotazione: <?= date('d/m/Y', strtotime($c['last_reservation_at'])) ?></small>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
