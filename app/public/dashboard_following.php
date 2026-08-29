<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'following';
$pageTitle = 'Seguiti';

$stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name, p.avatar_path
    FROM account_follows af JOIN users u ON u.id = af.followed_user_id JOIN profiles p ON p.user_id = u.id
    WHERE af.follower_user_id = ? ORDER BY p.display_name ASC');
$stmt->execute([$profile['id']]);
$following = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      L'elenco dei profili che segui — i loro contenuti compaiono nella tua Timeline
      personale. Per seguire un nuovo profilo, vai sulla sua pagina pubblica e clicca "Segui".
    </p>
  </details>

  <div class="section-title">Segui (<?= count($following) ?>)</div>
  <?php if (!$following): ?>
    <div class="card">Non segui ancora nessun profilo.</div>
  <?php endif; ?>
  <?php foreach ($following as $f): ?>
    <div class="link-item" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <a href="/<?= e($f['slug']) ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;flex:1;min-width:0;">
        <?php if ($f['avatar_path']): ?>
          <img src="/<?= e($f['avatar_path']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
        <?php else: ?>
          <div style="width:44px;height:44px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;"><?= e(mb_strtoupper(mb_substr($f['display_name'], 0, 1))) ?></div>
        <?php endif; ?>
        <div>
          <strong><?= e($f['display_name']) ?></strong><br>
          <small style="color:var(--text-muted)">@<?= e($f['slug']) ?></small>
        </div>
      </a>
      <?php if (areMutualFollowers((int) $profile['id'], (int) $f['id'])): ?>
        <a href="/dashboard_chat.php?with=<?= e($f['slug']) ?>" class="btn small" style="flex-shrink:0;">💬 Messaggia</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
