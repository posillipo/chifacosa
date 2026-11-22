<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'followers';
$pageTitle = 'Follower';

$total = getFollowerCount((int)$profile['id']);

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM followers WHERE user_id=? AND verified=1 AND created_at >= NOW() - INTERVAL 7 DAY");
$stmt->execute([$profile['id']]);
$last7 = (int) $stmt->fetch()['c'];

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM followers WHERE user_id=? AND verified=1 AND created_at >= NOW() - INTERVAL 30 DAY");
$stmt->execute([$profile['id']]);
$last30 = (int) $stmt->fetch()['c'];

$stmt = getDB()->prepare('SELECT email, first_name, last_name, phone, postal_code, created_at FROM followers WHERE user_id=? AND verified=1 ORDER BY created_at DESC LIMIT 200');
$stmt->execute([$profile['id']]);
$followers = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= $total ?></b><span>Follower totali</span></div>
      <div class="tlx-kpi"><b><?= $last7 ?></b><span>Ultimi 7 giorni</span></div>
      <div class="tlx-kpi"><b><?= $last30 ?></b><span>Ultimi 30 giorni</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_invite.php"><b><i class="fa-solid fa-user-plus"></i></b><span>Invita persone</span></a>
    </div>
    <div class="tlx-listhead"><h2>Elenco follower <small>(<?= count($followers) ?>, ultimi 200)</small></h2></div>
    <?php if ($followers): ?><div class="blx-toolbar"><div class="blx-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search="#fl-list" placeholder="Cerca per email, nome o telefono..." aria-label="Cerca"></div></div><?php endif; ?>
    <?php if (!$followers): ?><div class="tlx-empty"><i class="fa-regular fa-heart"></i><b>Nessun follower ancora</b><span>Il pulsante «Segui» è già visibile sulla tua pagina pubblica.</span></div><?php endif; ?>
    <div class="tlx-empty" data-filter-empty hidden><i class="fa-solid fa-magnifying-glass"></i><b>Nessun risultato</b></div>
    <div id="fl-list">
    <?php foreach ($followers as $f): $fullName = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '')); ?>
      <div class="pnx kit-item" data-state="">
        <span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr($fullName !== '' ? $fullName : $f['email'], 0, 1))) ?></span>
        <div class="pnx-b"><b><?= e($fullName !== '' ? $fullName : $f['email']) ?></b><small><a href="mailto:<?= e($f['email']) ?>"><?= e($f['email']) ?></a><?php if ($f['phone']): ?> · <?= e($f['phone']) ?><?php endif; ?><?php if ($f['postal_code']): ?> · CAP <?= e($f['postal_code']) ?><?php endif; ?></small></div>
        <div class="pnx-act"><small class="lkx-hint">dal <?= e(date('d/m/Y', strtotime($f['created_at']))) ?></small></div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
