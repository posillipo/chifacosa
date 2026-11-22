<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
<?php
// Scheda di un profilo, dentro la dashboard: raggiunta dalla ricerca profili nella barra in alto.
// Mostra le informazioni essenziali, il pulsante Segui / Non seguire più e il collegamento alla pagina pubblica.
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$activeTab = 'profile_view';
$pageTitle = 'Profilo';
$pageHelpInline = true; // il "?" sta accanto al titolo (vedi sotto)

$slug = trim((string) ($_GET['slug'] ?? ''));
$db = getDB();
$st = $db->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.bio, p.avatar_path, p.genere, p.citta, p.provincia
    FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.slug = ? AND u.is_active = 1');
$st->execute([$slug]);
$target = $slug !== '' ? $st->fetch() : false;
// Profilo privato: la sua scheda si apre solo a lui, a chi lo gestisce, agli admin e ai suoi follower.
if ($target && profilesHavePrivateColumn()) {
    $pv = $db->prepare('SELECT is_private FROM profiles WHERE user_id = ?');
    $pv->execute([(int) $target['id']]);
    if ((int) $pv->fetchColumn() === 1 && !privateProfileViewerAllowed((int) $target['id'])) {
        $target = false;
    }
}
if (!$target) {
    http_response_code(404);
}

$actingId = publicActingUserId() ?: (int) $user['id'];
$isSelf = $target && (int) $target['id'] === $actingId;
$following = $target && !$isSelf && isFollowingAccount($actingId, (int) $target['id']);
$followedBack = $target && !$isSelf && isFollowingAccount((int) $target['id'], $actingId);
$mutual = $following && $followedBack;
$followers = $target ? getAccountFollowerCount((int) $target['id']) : 0;

$latest = [];
if ($target) {
    $tid = (int) $target['id'];
    try {
        $q = $db->prepare("SELECT 'Articolo' kind, title, published_at d FROM blog_posts WHERE user_id = ? AND published_at <= NOW()
            UNION ALL
            SELECT 'Aggiornamento', COALESCE(NULLIF(title, ''), LEFT(testo, 80)), COALESCE(publish_at, created_at) FROM timeline_posts
              WHERE user_id = ? AND visibility = 'public' AND (publish_at IS NULL OR publish_at <= NOW())
            ORDER BY d DESC LIMIT 5");
        $q->execute([$tid, $tid]);
        $latest = $q->fetchAll();
    } catch (Throwable $e) {
        $latest = [];
    }
}
$here = '/dashboard_profile_view.php?slug=' . rawurlencode($slug);
$where = $target ? trim(($target['citta'] ?? '') . (($target['provincia'] ?? '') ? ' (' . $target['provincia'] . ')' : '')) : '';

include __DIR__ . '/../_dash_header.php';
?>
  <div class="page-head"><h1 class="page-head-title">Profilo</h1><?= renderPageHelpButton('dashboard_profile_view', 'Profilo') ?></div>

  <?php if (!$target): ?>
    <div class="card">Profilo non trovato. <a href="/dashboard.php">Torna alla dashboard</a></div>
  <?php else: ?>
    <div class="card" style="text-align:center;padding:28px 20px;">
      <?php if ($target['avatar_path']): ?>
        <img src="/<?= e(ltrim($target['avatar_path'], '/')) ?>" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;">
      <?php else: ?>
        <div style="width:96px;height:96px;border-radius:50%;background:var(--accent);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:38px;font-weight:700;"><?= e(mb_strtoupper(mb_substr($target['display_name'], 0, 1))) ?></div>
      <?php endif; ?>
      <h2 style="margin:12px 0 2px;"><?= e($target['display_name']) ?></h2>
      <div style="color:var(--text-muted);">@<?= e($target['slug']) ?><?= $where !== '' ? ' · ' . e($where) : '' ?><?= !empty($target['genere']) ? ' · ' . e($target['genere']) : '' ?></div>
      <div style="margin:10px 0;color:var(--text-muted);font-size:14px;"><strong style="color:inherit;"><?= (int) $followers ?></strong> follower<?= $followedBack ? ' · <em>ti segue</em>' : '' ?></div>

      <?php if (!empty($target['bio'])): ?>
        <p style="max-width:560px;margin:12px auto;line-height:1.5;"><?= nl2br(e(mb_strimwidth((string) $target['bio'], 0, 600, '…'))) ?></p>
      <?php endif; ?>

      <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:16px;">
        <?php if ($isSelf): ?>
          <a class="btn secondary" href="/dashboard_profile.php"><i class="fa-solid fa-pen"></i> Modifica il tuo profilo</a>
        <?php else: ?>
          <form method="post" action="/follow_account.php" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>">
            <input type="hidden" name="redirect" value="<?= e($here) ?>">
            <?php if ($following): ?>
              <input type="hidden" name="action" value="unfollow">
              <button type="submit" class="btn secondary"><i class="fa-solid fa-user-check"></i> Non seguire più</button>
            <?php else: ?>
              <input type="hidden" name="action" value="follow">
              <button type="submit" class="btn"><i class="fa-solid fa-user-plus"></i> Segui</button>
            <?php endif; ?>
          </form>
          <?php if ($mutual): ?>
            <a class="btn secondary" href="/dashboard_chat.php?with=<?= e(rawurlencode($target['slug'])) ?>"><i class="fa-solid fa-comment-dots"></i> Scrivi un messaggio</a>
          <?php endif; ?>
        <?php endif; ?>
        <a class="btn secondary" href="/<?= e($target['slug']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Pagina pubblica</a>
      </div>
    </div>

    <?php if ($latest): ?>
      <div class="section-title">Ultimi contenuti</div>
      <div class="card">
        <?php foreach ($latest as $it): ?>
          <div style="padding:8px 0;border-top:1px solid rgba(128,128,128,.2);">
            <small style="color:var(--text-muted);text-transform:uppercase;"><?= e($it['kind']) ?> · <?= e(date('d/m/Y', strtotime($it['d']))) ?></small><br>
            <strong><?= e(mb_strimwidth((string) $it['title'], 0, 120, '…')) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
