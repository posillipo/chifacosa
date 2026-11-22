<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
$activeTab = 'team';
$pageTitle = 'Team e co-admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['id'] ?? 0);

    if ($action === 'promote' && $targetId) {
        // Si può promuovere solo qualcuno che ti segue davvero (evita di aggiungere admin a
        // caso passando un ID a mano)
        $stmt = getDB()->prepare('SELECT 1 FROM account_follows WHERE follower_user_id = ? AND followed_user_id = ?');
        $stmt->execute([$targetId, $profile['id']]);
        if ($stmt->fetch() && $targetId !== (int) $profile['id']) {
            $stmt = getDB()->prepare('INSERT IGNORE INTO profile_admins (owner_user_id, admin_user_id) VALUES (?, ?)');
            $stmt->execute([$profile['id'], $targetId]);
        }
    } elseif ($action === 'revoke' && $targetId) {
        $stmt = getDB()->prepare('DELETE FROM profile_admins WHERE owner_user_id = ? AND admin_user_id = ?');
        $stmt->execute([$profile['id'], $targetId]);
    }
    header('Location: /dashboard_team.php');
    exit;
}

// Follower attuali del profilo (candidati alla promozione)
$stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name, p.avatar_path
    FROM account_follows af JOIN users u ON u.id = af.follower_user_id JOIN profiles p ON p.user_id = u.id
    WHERE af.followed_user_id = ? ORDER BY p.display_name ASC');
$stmt->execute([$profile['id']]);
$followers = $stmt->fetchAll();

// Co-admin attuali
$stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name, p.avatar_path
    FROM profile_admins pa JOIN users u ON u.id = pa.admin_user_id JOIN profiles p ON p.user_id = u.id
    WHERE pa.owner_user_id = ? ORDER BY p.display_name ASC');
$stmt->execute([$profile['id']]);
$admins = $stmt->fetchAll();
$adminIds = array_column($admins, 'id');

include __DIR__ . '/../_dash_header.php';
$cands = array_values(array_filter($followers, fn ($f) => !in_array($f['id'], $adminIds, true)));
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($admins) ?></b><span>Co-admin</span></div>
      <div class="tlx-kpi"><b><?= count($cands) ?></b><span>Follower da poter promuovere</span></div>
    </div>
    <div class="box"><p>Un co-admin può gestire il tuo profilo (contenuti, eventi, link…) senza conoscere la tua password. Puoi promuovere solo chi ti segue già, e revocare l'accesso quando vuoi.</p></div>

    <div class="tlx-listhead"><h2>Co-admin attuali <small>(<?= count($admins) ?>)</small></h2></div>
    <?php if (!$admins): ?><div class="tlx-empty"><i class="fa-solid fa-user-shield"></i><b>Nessun co-admin</b><span>Non hai ancora condiviso la gestione del profilo con nessuno.</span></div><?php endif; ?>
    <?php foreach ($admins as $a): ?>
      <div class="pnx">
        <?php if ($a['avatar_path']): ?><img class="pnx-img rnd" src="/<?= e($a['avatar_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr($a['display_name'], 0, 1))) ?></span><?php endif; ?>
        <div class="pnx-b"><b><?= e($a['display_name']) ?></b><small>@<?= e($a['slug']) ?></small></div>
        <form method="post" class="pnx-act" data-confirm="Revocare l'accesso di gestione a questo utente?"><?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="stc-act danger" type="submit"><i class="fa-solid fa-user-xmark"></i> <span>Revoca</span></button></form>
      </div>
    <?php endforeach; ?>

    <div class="tlx-listhead"><h2>I tuoi follower <small>(<?= count($followers) ?>)</small></h2></div>
    <?php if (!$followers): ?><div class="tlx-empty"><i class="fa-regular fa-heart"></i><b>Nessuno ti segue ancora</b><span>Non hai ancora nessuno da poter promuovere.</span></div><?php endif; ?>
    <?php foreach ($cands as $f): ?>
      <div class="pnx">
        <?php if ($f['avatar_path']): ?><img class="pnx-img rnd" src="/<?= e($f['avatar_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr($f['display_name'], 0, 1))) ?></span><?php endif; ?>
        <div class="pnx-b"><b><?= e($f['display_name']) ?></b><small>@<?= e($f['slug']) ?></small></div>
        <form method="post" class="pnx-act" data-confirm="Rendere questo utente co-admin del tuo profilo?"><?= csrfField() ?><input type="hidden" name="action" value="promote"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="stc-act primary" type="submit"><i class="fa-solid fa-user-shield"></i> <span>Rendi admin</span></button></form>
      </div>
    <?php endforeach; ?>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
