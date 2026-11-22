<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'following';
$pageTitle = 'Seguiti';

$stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name, p.avatar_path
    FROM account_follows af JOIN users u ON u.id = af.followed_user_id JOIN profiles p ON p.user_id = u.id
    WHERE af.follower_user_id = ? ORDER BY p.display_name ASC');
$stmt->execute([$profile['id']]);
$following = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
$mut = 0; foreach ($following as $x) { if (areMutualFollowers((int) $profile['id'], (int) $x['id'])) { $mut++; } }
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($following) ?></b><span>Profili che segui</span></div>
      <div class="tlx-kpi"><b><?= $mut ?></b><span>Si seguono a vicenda</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_messages.php"><b><i class="fa-regular fa-comments"></i></b><span>Messaggi</span></a>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard.php"><b><i class="fa-solid fa-compass"></i></b><span>Scopri nuovi profili</span></a>
    </div>
    <div class="tlx-listhead"><h2>Segui <small>(<?= count($following) ?>)</small></h2></div>
    <?php if (!$following): ?><div class="tlx-empty"><i class="fa-regular fa-user"></i><b>Non segui ancora nessun profilo</b><span>Cercane uno dalla barra in alto o dalla scheda «Scopri».</span></div><?php endif; ?>
    <?php foreach ($following as $f): $isMut = areMutualFollowers((int) $profile['id'], (int) $f['id']); ?>
      <div class="pnx">
        <a class="pnx-lnk" href="/dashboard_profile_view.php?slug=<?= e($f['slug']) ?>">
          <?php if ($f['avatar_path']): ?><img class="pnx-img rnd" src="/<?= e($f['avatar_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr($f['display_name'], 0, 1))) ?></span><?php endif; ?>
          <div class="pnx-b"><b><?= e($f['display_name']) ?></b><small>@<?= e($f['slug']) ?><?= $isMut ? ' · vi seguite a vicenda' : '' ?></small></div>
        </a>
        <div class="pnx-act">
          <a class="stc-act" href="/<?= e($f['slug']) ?>" target="_blank" title="Pagina pubblica"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
          <?php if ($isMut): ?><a class="stc-act primary" href="/dashboard_chat.php?with=<?= e($f['slug']) ?>"><i class="fa-regular fa-comment"></i> <span>Messaggia</span></a><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
