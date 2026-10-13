<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
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

include __DIR__ . '/_dash_header.php';
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Quando pubblichi un post Timeline o un articolo Blog, il contenuto viene aggiunto a una coda.
      Ogni 10 minuti, Claude legge la coda, genera una <strong>copy ottimizzata per ogni social
      network</strong> (tono, lunghezza, hashtag diversi per Instagram, Facebook, LinkedIn, ecc.)
      e la schedula su Metricool. Nessun intervento manuale richiesto.
    </p>
    <p style="color:var(--text-muted)">
      Per ogni profilo serve l'<strong>ID Metricool</strong> del brand collegato: lo inserisce
      l'amministratore del sito. Poi scegli su quali social pubblicare.
    </p>
  </details>

  <div class="section-title">Profili e ID Metricool</div>
  <?php foreach ($rows as $r): ?>
    <?php
      $rid = (int) $r['id'];
      $rBlog = (int) ($r['metricool_blog_id'] ?? 0);
      $rNets = $r['metricool_networks'] ? (json_decode($r['metricool_networks'], true) ?: []) : [];
      $rAuto = !empty($r['metricool_auto_social']);
    ?>
    <form method="post" class="card" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:16px;align-items:start;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="target_id" value="<?= $rid ?>">
      <div>
        <strong><?= e($r['display_name']) ?></strong>
        <div style="color:var(--text-muted);font-size:12.5px;">/<?= e($r['slug']) ?><?= $rid === (int) $user['id'] ? ' · il tuo profilo' : '' ?></div>
        <div style="font-size:12.5px;margin-top:6px;color:<?= $rAuto ? '#27ae60' : '#e74c3c' ?>;">
          <?= $rAuto ? '✅ Pubblicazione automatica attiva' : '⏸ Non attiva' ?>
        </div>
      </div>
      <div>
        <label>ID Metricool</label>
        <?php if ($canEditBlogId): ?>
          <input type="text" name="metricool_blog_id" inputmode="numeric" pattern="[0-9]*" value="<?= $rBlog ?: '' ?>" placeholder="es. 1234567">
        <?php else: ?>
          <p style="margin:0 0 12px;"><?= $rBlog ? '<strong>' . $rBlog . '</strong>' : '<span style="color:var(--text-muted);">Non assegnato: contatta l\'amministratore.</span>' ?></p>
        <?php endif; ?>

        <label>Social network da pubblicare</label>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
          <?php foreach ($allNetworks as $net => $netlabel): ?>
            <label style="display:flex;align-items:center;gap:6px;font-weight:normal;cursor:pointer;">
              <input type="checkbox" name="networks[]" value="<?= $net ?>" <?= in_array($net, $rNets, true) ? 'checked' : '' ?>>
              <?= e($netlabel) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="submit" class="btn small">Salva</button>
          <?php if ($rAuto): ?>
            <button type="submit" name="action" value="disable" class="btn small danger"
                    onclick="return confirm('Disattivare la pubblicazione automatica?');">Disattiva</button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  <?php endforeach; ?>

  <!-- Statistiche coda -->
  <?php if ($stats): ?>
    <div class="section-title" style="margin-top:24px;">📊 Coda pubblicazioni</div>
    <div class="card">
      <div style="display:flex;gap:24px;flex-wrap:wrap;">
        <?php
        $labels = ['pending' => '⏳ In attesa', 'processing' => '⚙️ In lavorazione', 'done' => '✅ Completati', 'failed' => '❌ Errori'];
        foreach ($labels as $s => $l): ?>
          <div style="text-align:center;">
            <div style="font-size:1.6em;font-weight:700;"><?= $stats[$s] ?? 0 ?></div>
            <div style="font-size:12.5px;color:var(--text-muted);"><?= $l ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($queueItems): ?>
    <div class="section-title" style="margin-top:24px;">📋 Ultime pubblicazioni in coda</div>
    <div class="card" style="padding:0;overflow:hidden;">
      <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
        <thead>
          <tr style="background:var(--bg-alt,#f5f5f5);">
            <th style="padding:10px 14px;text-align:left;">Tipo</th>
            <th style="padding:10px 14px;text-align:left;">Contenuto</th>
            <th style="padding:10px 14px;text-align:left;">Stato</th>
            <th style="padding:10px 14px;text-align:left;">Data</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($queueItems as $qi): ?>
            <?php
            $statusColors = ['pending' => '#e67e22', 'processing' => '#2980b9', 'done' => '#27ae60', 'failed' => '#e74c3c'];
            $color = $statusColors[$qi['status']] ?? '#666';
            $label = $qi['title'] ?: textExcerpt($qi['body'] ?? '', 60) ?: '—';
            ?>
            <tr style="border-top:1px solid var(--border-color,#e0e0e0);">
              <td style="padding:9px 14px;"><?= $qi['content_type'] === 'blog' ? '📝 Blog' : '📱 Timeline' ?></td>
              <td style="padding:9px 14px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($label) ?></td>
              <td style="padding:9px 14px;"><span style="color:<?= $color ?>;font-weight:600;"><?= e($qi['status']) ?></span><?php if ($qi['error_msg']): ?><br><small style="color:#e74c3c;"><?= e(mb_substr($qi['error_msg'], 0, 80)) ?></small><?php endif; ?></td>
              <td style="padding:9px 14px;color:var(--text-muted);"><?= date('d/m H:i', strtotime($qi['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php include __DIR__ . '/_dash_footer.php'; ?>
