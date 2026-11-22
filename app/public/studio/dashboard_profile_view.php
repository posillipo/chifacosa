<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
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
  <div class="tlx tlx-narrow">
  <?php if (!$target): ?>
    <div class="tlx-empty"><i class="fa-regular fa-user"></i><b>Profilo non trovato</b><span><a href="/dashboard.php">Torna alla dashboard</a></span></div>
  <?php else: ?>
    <div class="box pvx">
      <?php if ($target['avatar_path']): ?><img class="pvx-av" src="/<?= e(ltrim($target['avatar_path'], '/')) ?>" alt=""><?php else: ?><span class="pvx-av stc-ph"><?= e(mb_strtoupper(mb_substr($target['display_name'], 0, 1))) ?></span><?php endif; ?>
      <h2><?= e($target['display_name']) ?></h2>
      <div class="pvx-sub">@<?= e($target['slug']) ?><?= $where !== '' ? ' · ' . e($where) : '' ?><?= !empty($target['genere']) ? ' · ' . e($target['genere']) : '' ?></div>
      <div class="pvx-st"><b><?= (int) $followers ?></b> follower<?= $followedBack ? ' · ti segue' : '' ?><?= $mutual ? ' · vi seguite a vicenda' : '' ?></div>
      <?php if (!empty($target['bio'])): ?><p class="pvx-bio"><?= nl2br(e(mb_strimwidth((string) $target['bio'], 0, 600, '…'))) ?></p><?php endif; ?>
      <div class="box-row pvx-act">
        <?php if ($isSelf): ?>
          <a class="stc-act primary" href="/dashboard_profile.php"><i class="fa-solid fa-pen"></i> <span>Modifica il tuo profilo</span></a>
        <?php else: ?>
          <form method="post" action="/follow_account.php"><?= csrfField() ?><input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>"><input type="hidden" name="redirect" value="<?= e($here) ?>">
            <?php if ($following): ?><input type="hidden" name="action" value="unfollow"><button type="submit" class="stc-act"><i class="fa-solid fa-user-check"></i> <span>Non seguire più</span></button>
            <?php else: ?><input type="hidden" name="action" value="follow"><button type="submit" class="stc-act primary"><i class="fa-solid fa-user-plus"></i> <span>Segui</span></button><?php endif; ?></form>
          <?php if ($mutual): ?><a class="stc-act" href="/dashboard_chat.php?with=<?= e(rawurlencode($target['slug'])) ?>"><i class="fa-regular fa-comment"></i> <span>Scrivi un messaggio</span></a><?php endif; ?>
        <?php endif; ?>
        <a class="stc-act" href="/<?= e($target['slug']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Pagina pubblica</span></a>
      </div>
    </div>
    <?php if ($latest): ?>
      <div class="tlx-listhead"><h2>Ultimi contenuti</h2></div>
      <?php foreach ($latest as $it): ?>
        <div class="pnx"><div class="pnx-b"><span class="pnx-t"><?= e($it['kind']) ?> · <?= e(date('d/m/Y', strtotime($it['d']))) ?></span><b><?= e(mb_strimwidth((string) $it['title'], 0, 120, '…')) ?></b></div></div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
  </div>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
