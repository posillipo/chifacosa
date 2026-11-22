<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
$activeTab = 'reservations';
$pageTitle = 'Prenotazioni';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $validStatuses = ['confirmed', 'declined', 'cancelled', 'no_show', 'completed'];

    if ($action === 'update_status' && in_array($_POST['status'] ?? '', $validStatuses, true)) {
        $stmt = getDB()->prepare('UPDATE table_reservations SET status = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$_POST['status'], $id, $profile['id']]);
    }
    $redirectParams = ['view' => $_GET['view'] ?? 'requests'];
    if (!empty($_GET['event_id'])) {
        $redirectParams['event_id'] = (int) $_GET['event_id'];
    }
    header('Location: /dashboard_reservations.php?' . http_build_query($redirectParams));
    exit;
}

$view = ($_GET['view'] ?? 'requests') === 'clients' ? 'clients' : 'requests';
$eventFilter = (int) ($_GET['event_id'] ?? 0);

// Solo gli eventi che hanno almeno una prenotazione, per non riempire il filtro di eventi vuoti.
$stmt = getDB()->prepare('SELECT DISTINCT ev.id, ev.title, ev.event_date
                          FROM events ev
                          JOIN table_reservations tr ON tr.event_id = ev.id
                          WHERE ev.user_id = ?
                          ORDER BY ev.event_date DESC');
$stmt->execute([$profile['id']]);
$eventsWithReservations = $stmt->fetchAll();

$sql = 'SELECT tr.*, ev.title AS event_title, ev.event_date
        FROM table_reservations tr
        LEFT JOIN events ev ON ev.id = tr.event_id
        WHERE tr.user_id = ?';
$params = [$profile['id']];
if ($eventFilter > 0) {
    $sql .= ' AND tr.event_id = ?';
    $params[] = $eventFilter;
}
$sql .= ' ORDER BY ev.event_date DESC, tr.created_at DESC';
$stmt = getDB()->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT COUNT(*) FROM table_reservations WHERE user_id = ?');
$stmt->execute([$profile['id']]);
$totalReservationsCount = (int) $stmt->fetchColumn();

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
$stmt->execute([$profile['id']]);
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

include __DIR__ . '/../_dash_header.php';
$cnt = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'no_show' => 0];
foreach ($reservations as $x) { if (isset($cnt[$x['status']])) { $cnt[$x['status']]++; } }
$stTone = ['pending' => 'warn', 'confirmed' => 'ok', 'declined' => 'danger', 'cancelled' => 'grey', 'no_show' => 'danger', 'completed' => 'ok'];
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= $totalReservationsCount ?></b><span>Prenotazioni</span></div>
      <div class="tlx-kpi"><b><?= $cnt['confirmed'] ?></b><span>Confermate</span></div>
      <div class="tlx-kpi"><b><?= $cnt['no_show'] ?></b><span>Non presentati</span></div>
      <div class="tlx-kpi"><b><?= count($clients) ?></b><span>Clienti</span></div>
    </div>

    <div class="blx-head">
      <nav class="blx-tabs" aria-label="Vista">
        <a href="/dashboard_reservations.php?view=requests" class="<?= $view === 'requests' ? 'on' : '' ?>"><i class="fa-solid fa-chair"></i> Richieste <em><?= $totalReservationsCount ?></em></a>
        <a href="/dashboard_reservations.php?view=clients" class="<?= $view === 'clients' ? 'on' : '' ?>"><i class="fa-solid fa-users"></i> Clienti <em><?= count($clients) ?></em></a>
      </nav>
      <a class="blx-new" href="/dashboard_events.php"><i class="fa-regular fa-calendar"></i> <span>Eventi</span></a>
    </div>

    <?php if ($view === 'requests'): ?>
      <?php if ($eventsWithReservations): ?>
        <form method="get" class="blx-toolbar" data-autosubmit>
          <input type="hidden" name="view" value="requests">
          <select name="event_id" aria-label="Filtra per evento">
            <option value="">Tutti gli eventi (<?= $totalReservationsCount ?>)</option>
            <?php foreach ($eventsWithReservations as $ev): ?><option value="<?= (int) $ev['id'] ?>" <?= $eventFilter === (int) $ev['id'] ? 'selected' : '' ?>><?= e($ev['title']) ?> — <?= date('d/m/Y', strtotime($ev['event_date'])) ?></option><?php endforeach; ?>
          </select>
          <div class="tlx-filters" data-filters="#rs-list"><button type="button" class="on" data-f="all">Tutte</button><button type="button" data-f="confirmed">Confermate</button><button type="button" data-f="completed">Presentate</button><button type="button" data-f="no_show">Non presentati</button><button type="button" data-f="cancelled">Annullate</button></div>
        </form>
      <?php endif; ?>
      <?php if (!$reservations): ?><div class="tlx-empty"><i class="fa-solid fa-chair"></i><b><?= $eventFilter > 0 ? 'Nessuna prenotazione per questo evento' : 'Nessuna prenotazione ancora' ?></b><span>Attiva «Accetta prenotazioni» su un evento per iniziare a riceverle.</span></div><?php endif; ?>
      <div class="tlx-empty" data-filter-empty hidden><i class="fa-regular fa-circle-check"></i><b>Niente da mostrare con questo filtro</b></div>
      <div id="rs-list">
      <?php foreach ($reservations as $r): $digits = preg_replace('~\D+~', '', (string) $r['guest_phone']); ?>
        <article class="ibx kit-item" data-state="<?= e($r['status']) ?>">
          <header class="ibx-h">
            <span class="ibx-av"><?= e(mb_strtoupper(mb_substr($r['guest_name'], 0, 1))) ?></span>
            <div class="ibx-who"><b><?= e($r['guest_name']) ?> <small class="lkx-hint">· <?= (int) $r['party_size'] ?> person<?= (int) $r['party_size'] === 1 ? 'a' : 'e' ?></small></b><a href="mailto:<?= e($r['guest_email']) ?>"><?= e($r['guest_email']) ?></a></div>
            <div class="ibx-meta"><span class="stc-status stc-s-<?= e($stTone[$r['status']] ?? 'grey') ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></div>
          </header>
          <div class="ibx-svc"><i class="fa-regular fa-calendar"></i> <b><?= e($r['event_title'] ?? 'Evento eliminato') ?></b><?= $r['event_date'] ? ' · ' . e(formatLocalDateTime($r['event_date'], $profile)) : '' ?></div>
          <?php if ($r['notes']): ?><p class="ibx-msg"><?= nl2br(e($r['notes'])) ?></p><?php endif; ?>
          <div class="stc-actions ibx-act">
            <?php if ($r['guest_phone']): ?>
              <a class="stc-act" href="tel:<?= e($r['guest_phone']) ?>"><i class="fa-solid fa-phone"></i> <span><?= e($r['guest_phone']) ?></span></a>
              <?php if (strlen($digits) >= 8): ?><a class="stc-act" href="https://wa.me/<?= e($digits) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> <span>WhatsApp</span></a><?php endif; ?>
            <?php endif; ?>
            <?php if ($r['status'] === 'confirmed'): foreach ([['completed', 'fa-check', 'Presentato', ''], ['no_show', 'fa-user-xmark', 'Non presentato', ''], ['cancelled', 'fa-xmark', 'Annulla', ' danger']] as [$st, $ic, $lb, $cl]): ?>
              <form method="post" class="ibx-inline"><?= csrfField() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>"><button class="stc-act<?= $cl ?>" type="submit"><i class="fa-solid <?= $ic ?>"></i> <span><?= $lb ?></span></button></form>
            <?php endforeach; endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php else: ?>
      <?php if (!$clients): ?><div class="tlx-empty"><i class="fa-solid fa-users"></i><b>Ancora nessun cliente</b><span>I clienti compaiono dopo la prima prenotazione.</span></div><?php endif; ?>
      <div class="blx-grid">
      <?php foreach ($clients as $c): ?>
        <div class="blx-card"><div class="blx-card-main">
          <span class="ibx-av"><?= e(mb_strtoupper(mb_substr($c['guest_name'], 0, 1))) ?></span>
          <b><?= e($c['guest_name']) ?></b>
          <small><?= (int) $c['reservation_count'] ?> prenotazion<?= (int) $c['reservation_count'] === 1 ? 'e' : 'i' ?><?= (int) $c['no_show_count'] > 0 ? ' · ' . (int) $c['no_show_count'] . ' non presentato/i' : '' ?></small>
          <a href="mailto:<?= e($c['guest_email']) ?>"><?= e($c['guest_email']) ?></a>
          <?php if ($c['guest_phone']): ?><small><?= e($c['guest_phone']) ?></small><?php endif; ?>
          <small>Ultima: <?= e(date('d/m/Y', strtotime($c['last_reservation_at']))) ?></small>
        </div></div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
