<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Puoi condividere la gestione di questo profilo con chi già ti segue — un co-admin può
      pubblicare in Timeline e gestire Brani, ma non può cambiare password, tipo di account,
      tema grafico, eliminare il profilo, o gestire altri admin: quello resta sempre riservato
      solo a te. Puoi revocare l'accesso in qualsiasi momento.
    </p>
  </details>

  <div class="section-title">Co-admin attuali (<?= count($admins) ?>)</div>
  <?php if (!$admins): ?>
    <div class="card">Non hai ancora condiviso la gestione del profilo con nessuno.</div>
  <?php endif; ?>
  <?php foreach ($admins as $a): ?>
    <div class="link-item" style="display:flex;align-items:center;justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:12px;">
        <?php if ($a['avatar_path']): ?>
          <img src="/<?= e($a['avatar_path']) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
        <?php endif; ?>
        <div><strong><?= e($a['display_name']) ?></strong><br><small style="color:var(--text-muted)">@<?= e($a['slug']) ?></small></div>
      </div>
      <form method="post" onsubmit="return confirm('Revocare l\'accesso di gestione a questo utente?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="revoke">
        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
        <button class="btn small danger" type="submit">Revoca</button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="section-title" style="margin-top:24px;">I tuoi follower (<?= count($followers) ?>)</div>
  <?php if (!$followers): ?>
    <div class="card">Nessuno ti segue ancora — non hai ancora nessuno da poter promuovere.</div>
  <?php endif; ?>
  <?php foreach ($followers as $f): ?>
    <?php if (in_array($f['id'], $adminIds, true)) continue; ?>
    <div class="link-item" style="display:flex;align-items:center;justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:12px;">
        <?php if ($f['avatar_path']): ?>
          <img src="/<?= e($f['avatar_path']) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
        <?php endif; ?>
        <div><strong><?= e($f['display_name']) ?></strong><br><small style="color:var(--text-muted)">@<?= e($f['slug']) ?></small></div>
      </div>
      <form method="post" onsubmit="return confirm('Rendere questo utente co-admin del tuo profilo?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="promote">
        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
        <button class="btn small" type="submit">Rendi admin</button>
      </form>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
