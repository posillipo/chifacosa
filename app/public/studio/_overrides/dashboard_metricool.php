<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireAdmin(); // strumento riservato agli amministratori del sito
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
$activeTab = 'metricool';
$pageTitle = 'Social Automatico';
$error = null;
$success = isset($_GET['saved']) ? 'Configurazione salvata.' : null;

$allNetworks = [
    'instagram' => 'Instagram',
    'facebook'  => 'Facebook',
    'tiktok'    => 'TikTok',
    'linkedin'  => 'LinkedIn',
    'threads'   => 'Threads',
    'twitter'   => 'X (Twitter)',
    'bluesky'   => 'Bluesky',
];

// L'ID Metricool lo inserisce a mano solo l'amministratore del sito: è il numero che dice dove
// vengono pubblicati i post, quindi un valore sbagliato li manderebbe sui social di un altro.
$canEditBlogId = !empty($user['is_admin']);

// Profili dell'utente loggato: il proprio + quelli che co-gestisce.
$profileIds = [(int) $user['id']];
foreach (getManagedProfiles((int) $user['id']) as $m) {
    $profileIds[] = (int) $m['id'];
}
$profileIds = array_values(array_unique($profileIds));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['target_id'] ?? 0);

    if (!in_array($targetId, $profileIds, true)) {
        $error = 'Profilo non valido.';
    } elseif ($action === 'save') {
        $stmt = getDB()->prepare('SELECT metricool_blog_id FROM profiles WHERE user_id=?');
        $stmt->execute([$targetId]);
        $currentId = (int) $stmt->fetchColumn();

        if ($canEditBlogId) {
            $rawId = trim((string) ($_POST['metricool_blog_id'] ?? ''));
            if ($rawId !== '' && !ctype_digit($rawId)) {
                $error = 'L\'ID Metricool deve essere un numero.';
            }
            $blogId = $rawId !== '' ? (int) $rawId : null;
        } else {
            $blogId = $currentId ?: null;
        }
        $networks = array_intersect(array_keys($allNetworks), (array) ($_POST['networks'] ?? []));
        $autoOn   = !empty($networks) && $blogId ? 1 : 0;
        $netJson  = $networks ? json_encode(array_values($networks)) : null;

        if ($error === null) {
            $stmt = getDB()->prepare('UPDATE profiles SET metricool_blog_id=?, metricool_networks=?, metricool_auto_social=? WHERE user_id=?');
            $stmt->execute([$blogId, $netJson, $autoOn, $targetId]);
            header('Location: /dashboard_metricool.php?saved=1');
            exit;
        }
    } elseif ($action === 'disable') {
        $stmt = getDB()->prepare('UPDATE profiles SET metricool_auto_social=0 WHERE user_id=?');
        $stmt->execute([$targetId]);
        header('Location: /dashboard_metricool.php?saved=1');
        exit;
    }
}

$in = implode(',', array_fill(0, count($profileIds), '?'));
$stmt = getDB()->prepare("SELECT u.id, u.slug, p.display_name, p.metricool_blog_id, p.metricool_networks, p.metricool_auto_social
    FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id IN ($in)");
$stmt->execute($profileIds);
$rows = $stmt->fetchAll();
usort($rows, fn($a, $b) => ((int) $b['id'] === (int) $user['id']) <=> ((int) $a['id'] === (int) $user['id']) ?: strcasecmp($a['display_name'], $b['display_name']));

// Coda del profilo che stai gestendo adesso
$stmtStats = getDB()->prepare(
    'SELECT status, COUNT(*) n FROM social_queue WHERE user_id=? GROUP BY status'
);
$stmtStats->execute([$profile['id']]);
$stats = [];
foreach ($stmtStats->fetchAll() as $row) {
    $stats[$row['status']] = (int) $row['n'];
}

$stmtQ = getDB()->prepare(
    'SELECT * FROM social_queue WHERE user_id=? ORDER BY created_at DESC LIMIT 20'
);
$stmtQ->execute([$profile['id']]);
$queueItems = $stmtQ->fetchAll();

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx">
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <div class="tlx-listhead"><h2>Profili e ID Metricool</h2></div>
    <?php foreach ($rows as $r):
      $rid = (int) $r['id']; $rBlog = (int) ($r['metricool_blog_id'] ?? 0);
      $rNets = $r['metricool_networks'] ? (json_decode($r['metricool_networks'], true) ?: []) : [];
      $rAuto = !empty($r['metricool_auto_social']); ?>
      <form method="post" class="box">
        <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="target_id" value="<?= $rid ?>">
        <h3><?= e($r['display_name']) ?> <small>/<?= e($r['slug']) ?><?= $rid === (int) $user['id'] ? ' · il tuo profilo' : '' ?></small></h3>
        <p><span class="sqx-b" data-s="<?= $rAuto ? 'done' : 'pending' ?>"><?= $rAuto ? 'Pubblicazione automatica attiva' : 'Non attiva' ?></span></p>
        <label>ID Metricool</label>
        <?php if ($canEditBlogId): ?>
          <input type="text" name="metricool_blog_id" inputmode="numeric" pattern="[0-9]*" value="<?= $rBlog ?: '' ?>" placeholder="es. 1234567">
        <?php else: ?>
          <p><?= $rBlog ? '<b>' . $rBlog . '</b>' : 'Non assegnato: contatta l\'amministratore.' ?></p>
        <?php endif; ?>
        <label>Social network da pubblicare</label>
        <div class="sqx-nets">
          <?php foreach ($allNetworks as $net => $netlabel): ?>
            <label class="sqx-net"><input type="checkbox" name="networks[]" value="<?= $net ?>" <?= in_array($net, $rNets, true) ? 'checked' : '' ?>> <span><?= e($netlabel) ?></span></label>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="tlx-submit cmp-done">Salva</button>
      </form>
      <?php if ($rAuto): ?>
        <form method="post" class="sqx-off" data-confirm="Disattivare la pubblicazione automatica?"><?= csrfField() ?><input type="hidden" name="action" value="disable"><input type="hidden" name="target_id" value="<?= $rid ?>"><button class="stc-act danger" type="submit"><i class="fa-solid fa-pause"></i> <span>Disattiva per <?= e($r['display_name']) ?></span></button></form>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($stats): ?>
      <div class="tlx-kpis">
        <?php foreach (['pending' => 'In attesa', 'processing' => 'In lavorazione', 'done' => 'Completati', 'failed' => 'Errori'] as $s => $l): ?>
          <div class="tlx-kpi"><b><?= $stats[$s] ?? 0 ?></b><span><?= $l ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($queueItems): ?>
      <div class="tlx-listhead"><h2>Ultime pubblicazioni in coda</h2></div>
      <?php foreach ($queueItems as $qi): $label = $qi['title'] ?: textExcerpt($qi['body'] ?? '', 60) ?: '—'; ?>
        <div class="pnx">
          <div class="pnx-b"><span class="pnx-t"><?= $qi['content_type'] === 'blog' ? 'Blog' : 'Timeline' ?> · <?= date('d/m H:i', strtotime($qi['created_at'])) ?></span><b><?= e($label) ?></b><?php if ($qi['error_msg']): ?><small class="sqx-err"><?= e(mb_substr($qi['error_msg'], 0, 80)) ?></small><?php endif; ?></div>
          <span class="sqx-b" data-s="<?= e($qi['status']) ?>"><?= e($qi['status']) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
