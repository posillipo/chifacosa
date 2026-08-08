<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'dashboard';
$pageTitle = 'Dashboard';

$db = getDB();
$totalUsers = (int) $db->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
$activeUsers = (int) $db->query('SELECT COUNT(*) c FROM users WHERE is_active = 1')->fetch()['c'];
$disabledUsers = $totalUsers - $activeUsers;
$unverifiedUsers = (int) $db->query('SELECT COUNT(*) c FROM users WHERE email_verified = 0')->fetch()['c'];
$last7 = (int) $db->query('SELECT COUNT(*) c FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY')->fetch()['c'];
$last30 = (int) $db->query('SELECT COUNT(*) c FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetch()['c'];
$totalContacts = (int) $db->query('SELECT COUNT(*) c FROM contact_requests')->fetch()['c'];
$unreadContacts = (int) $db->query('SELECT COUNT(*) c FROM contact_requests WHERE is_read = 0')->fetch()['c'];
$totalLinks = (int) $db->query('SELECT COUNT(*) c FROM links')->fetch()['c'];
$totalTracks = (int) $db->query('SELECT COUNT(*) c FROM audio_tracks')->fetch()['c'];
$totalPosts = (int) $db->query('SELECT COUNT(*) c FROM blog_posts')->fetch()['c'];
$totalEvents = (int) $db->query('SELECT COUNT(*) c FROM events')->fetch()['c'];

include __DIR__ . '/_admin_header.php';
?>
  <div class="section-title">Panoramica</div>

  <div class="card">
    <strong>Utenti</strong>
    <p style="color:var(--text-muted)">
      <?= $totalUsers ?> totali · <?= $activeUsers ?> attivi · <?= $disabledUsers ?> disattivati ·
      <?= $unverifiedUsers ?> da verificare<br>
      Nuove iscrizioni: <?= $last7 ?> negli ultimi 7 giorni, <?= $last30 ?> negli ultimi 30 giorni
    </p>
  </div>

  <div class="card">
    <strong>Contenuti creati sulla piattaforma</strong>
    <p style="color:var(--text-muted)">
      <?= $totalLinks ?> link · <?= $totalTracks ?> brani · <?= $totalEvents ?> concerti ·
      <?= $totalPosts ?> articoli blog
    </p>
  </div>

  <div class="card">
    <strong>Richieste di contatto/booking</strong>
    <p style="color:var(--text-muted)">
      <?= $totalContacts ?> totali ricevute, di cui <?= $unreadContacts ?> non ancora lette
    </p>
    <a href="/admin_contacts.php">Vedi tutte le richieste →</a>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
