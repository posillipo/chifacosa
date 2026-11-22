<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'invite';
$pageTitle = 'Invita';

$inviteLink = siteUrl('/register.php?ref=' . $profile['slug']);

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM access_requests WHERE referrer_user_id = ? AND invite_used = 1");
$stmt->execute([$profile['id']]);
$totalJoined = (int) $stmt->fetch()['c'];

include __DIR__ . '/../_dash_header.php';
$shareJson = json_encode(['t' => 'Unisciti a ' . siteName(), 'u' => $inviteLink], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= $totalJoined ?></b><span>Persone iscritte con il tuo link</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_followers.php"><b><i class="fa-solid fa-heart"></i></b><span>I tuoi follower</span></a>
    </div>
    <div class="box">
      <h3><i class="fa-solid fa-user-plus"></i> Il tuo link di invito</h3>
      <p>Mandalo a chi vuoi: chi si iscrive da qui risulta invitato da te.</p>
      <div class="box-pre"><?= e($inviteLink) ?><button type="button" class="stc-act" data-copy="<?= e($inviteLink) ?>" aria-label="Copia"><i class="fa-regular fa-copy"></i></button></div>
      <div class="box-row">
        <button type="button" class="stc-act primary" data-share='<?= e($shareJson) ?>'><i class="fa-solid fa-share-nodes"></i> <span>Condividi</span></button>
        <a class="stc-act" href="https://wa.me/?text=<?= rawurlencode('Unisciti a ' . siteName() . ': ' . $inviteLink) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> <span>WhatsApp</span></a>
        <a class="stc-act" href="mailto:?subject=<?= rawurlencode('Unisciti a ' . siteName()) ?>&body=<?= rawurlencode($inviteLink) ?>"><i class="fa-regular fa-envelope"></i> <span>Email</span></a>
      </div>
    </div>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
