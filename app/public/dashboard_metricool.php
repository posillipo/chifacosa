<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
$activeTab = 'metricool';
$pageTitle = 'Social Automatico';
$error = null;
$success = null;

// Brand ID → label map (from Metricool, hardcoded to avoid API call on every page load)
// Keys are Metricool brand IDs, values are display labels.
// Update this list by checking admin_metricool.php if new brands are added.
$metricoolBrands = [
    2567772 => 'Gianluca Di Pietro',
    3764086 => 'Dream Cinema Frosinone',
    4051862 => 'Fratelli Ragusa',
    4812943 => 'Baia Beach Club',
    5019366 => 'La Caraffa',
    5222258 => 'Antonio Liberti Compagnia Della Bellezza',
    5300254 => 'myband.it',
    7041529 => 'Notaio Angelone',
];

$allNetworks = [
    'instagram' => 'Instagram',
    'facebook'  => 'Facebook',
    'tiktok'    => 'TikTok',
    'linkedin'  => 'LinkedIn',
    'threads'   => 'Threads',
    'twitter'   => 'X (Twitter)',
    'bluesky'   => 'Bluesky',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $blogId   = (int) ($_POST['metricool_blog_id'] ?? 0) ?: null;
        $networks = array_intersect(array_keys($allNetworks), (array) ($_POST['networks'] ?? []));
        $autoOn   = !empty($networks) && $blogId ? 1 : 0;
        $netJson  = $networks ? json_encode(array_values($networks)) : null;

        $stmt = getDB()->prepare('UPDATE profiles SET metricool_blog_id=?, metricool_networks=?, metricool_auto_social=? WHERE user_id=?');
        $stmt->execute([$blogId, $netJson, $autoOn, $profile['id']]);
        $success = $blogId && $networks
            ? 'Configurazione salvata. La pubblicazione automatica è attiva.'
            : 'Configurazione salvata. Seleziona un brand e almeno un social per attivare la pubblicazione automatica.';
        $user    = currentUser();
        $profile = getActingProfile($user);

    } elseif ($action === 'disable') {
        $stmt = getDB()->prepare('UPDATE profiles SET metricool_auto_social=0 WHERE user_id=?');
        $stmt->execute([$profile['id']]);
        $success = 'Pubblicazione automatica disattivata.';
        $user    = currentUser();
        $profile = getActingProfile($user);
    }
}

// Reload fresh profile
$user    = currentUser();
$profile = getActingProfile($user);

$currentBlogId  = (int) ($profile['metricool_blog_id'] ?? 0);
$currentNetworks = $profile['metricool_networks'] ? json_decode($profile['metricool_networks'], true) : [];
$autoActive      = !empty($profile['metricool_auto_social']);

// Queue stats
$stmtStats = getDB()->prepare(
    'SELECT status, COUNT(*) n FROM social_queue WHERE user_id=? GROUP BY status'
);
$stmtStats->execute([$profile['id']]);
$stats = [];
foreach ($stmtStats->fetchAll() as $row) {
    $stats[$row['status']] = (int) $row['n'];
}

// Recent queue items
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
  </details>

  <!-- Configurazione -->
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">

    <label>Brand Metricool</label>
    <select name="metricool_blog_id">
      <option value="">— Nessuno —</option>
      <?php foreach ($metricoolBrands as $bid => $blabel): ?>
        <option value="<?= $bid ?>" <?= $currentBlogId === $bid ? 'selected' : '' ?>><?= e($blabel) ?></option>
      <?php endforeach; ?>
    </select>
    <p style="color:var(--text-muted);font-size:13px;margin:-8px 0 12px;">
      Seleziona il brand Metricool corrispondente a questo profilo.
    </p>

    <label>Social network da pubblicare</label>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
      <?php foreach ($allNetworks as $net => $netlabel): ?>
        <label style="display:flex;align-items:center;gap:6px;font-weight:normal;cursor:pointer;">
          <input type="checkbox" name="networks[]" value="<?= $net ?>"
                 <?= in_array($net, $currentNetworks, true) ? 'checked' : '' ?>>
          <?= e($netlabel) ?>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="submit" class="btn">Salva configurazione</button>
      <?php if ($autoActive): ?>
        <button type="submit" name="action" value="disable" class="btn danger"
                onclick="return confirm('Disattivare la pubblicazione automatica?');">Disattiva</button>
      <?php endif; ?>
    </div>
  </form>

  <!-- Stato -->
  <?php if ($autoActive): ?>
    <div class="card" style="border-left:4px solid #27ae60;">
      <strong>✅ Pubblicazione automatica attiva</strong>
      <p style="color:var(--text-muted);margin:6px 0 0;">
        Brand: <strong><?= e($metricoolBrands[$currentBlogId] ?? $currentBlogId) ?></strong> ·
        Social: <strong><?= e(implode(', ', array_map(fn($n) => $allNetworks[$n] ?? $n, $currentNetworks))) ?></strong>
      </p>
    </div>
  <?php else: ?>
    <div class="card" style="border-left:4px solid #e74c3c;">
      <strong>⏸ Pubblicazione automatica non attiva</strong>
      <p style="color:var(--text-muted);margin:6px 0 0;">
        Seleziona un brand Metricool e almeno un social per attivarla.
      </p>
    </div>
  <?php endif; ?>

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
