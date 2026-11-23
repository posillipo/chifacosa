<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'directory';
$pageTitle = 'Directory';
$pageHelpInline = true; // il "?" sta accanto al titolo

// Filtri: provincia (sigla valida) e città (testo). Solo band/etichette con profilo visibile.
$provQ = provinceNormalize($_GET['prov'] ?? '');
$cityQ = trim((string) ($_GET['citta'] ?? ''));
$rows = directoryEnsureGeo(directoryProfiles($provQ, $cityQ));
$index = directoryIndex();
$points = directoryPoints($rows);
$provinces = provinceList();

include __DIR__ . '/_dash_header.php';
?>
  <div class="page-head"><h1 class="page-head-title">Directory</h1><?= renderPageHelpButton('dashboard_directory', 'Directory') ?></div>

  <form method="get" class="card" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <div style="flex:1 1 200px;"><label>Provincia</label>
      <select name="prov"><option value="">Tutte le province</option>
        <?php foreach ($provinces as $sg => $pn): ?><option value="<?= e($sg) ?>" <?= $provQ === $sg ? 'selected' : '' ?>><?= e($pn) ?> (<?= e($sg) ?>)</option><?php endforeach; ?>
      </select></div>
    <div style="flex:1 1 200px;"><label>Città</label>
      <input type="text" name="citta" value="<?= e($cityQ) ?>" placeholder="es. Portici" list="dir-cities">
      <datalist id="dir-cities"><?php foreach (($provQ && isset($index[$provQ]) ? $index[$provQ]['cities'] : []) as $cn => $n): ?><option value="<?= e($cn) ?>"><?php endforeach; ?></datalist></div>
    <div><button type="submit" class="btn">Cerca</button> <?php if ($provQ || $cityQ !== ''): ?><a href="/dashboard_directory.php" class="btn secondary">Azzera</a><?php endif; ?></div>
  </form>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
  <div id="dir-map" style="height:340px;border-radius:12px;margin:12px 0;" data-profile-base="/dashboard_profile_view.php?slug=" data-points="<?= e(json_encode($points, JSON_UNESCAPED_UNICODE)) ?>"></div>

  <div class="section-title">Risultati (<?= count($rows) ?>)</div>
  <?php if (!$rows): ?><div class="card">Nessuna band trovata<?= ($provQ || $cityQ !== '') ? ' con questi filtri' : '' ?>.</div><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <div class="link-item">
      <div><strong><a href="/dashboard_profile_view.php?slug=<?= e(rawurlencode($r['slug'])) ?>"><?= e($r['display_name'] ?: $r['slug']) ?></a></strong>
        <br><small style="color:var(--text-muted)"><?= e($r['citta']) ?> (<?= e(strtoupper((string) $r['provincia'])) ?>)<?= !empty($r['genere']) ? ' · ' . e($r['genere']) : '' ?></small></div>
      <?php if (isset($r['geo_lat']) && $r['geo_lat'] !== null): ?><a href="#" class="btn small secondary" data-locate data-lat="<?= e($r['geo_lat']) ?>" data-lng="<?= e($r['geo_lng']) ?>">Sulla mappa</a><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="section-title">Indice per provincia</div>
  <?php if (!$index): ?><div class="card">Non ci sono ancora profili con città e provincia.</div><?php endif; ?>
  <?php foreach ($index as $sg => $pv): ?>
    <div class="card">
      <strong><a href="?prov=<?= e($sg) ?>"><?= e($pv['name']) ?> (<?= e($sg) ?>)</a></strong> — <?= (int) $pv['n'] ?>
      <br><?php foreach ($pv['cities'] as $cn => $n): ?><a href="?prov=<?= e($sg) ?>&amp;citta=<?= e(rawurlencode($cn)) ?>"><?= e($cn) ?></a> (<?= (int) $n ?>) &nbsp;<?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
  <script src="<?= assetUrl('/assets/js/directory.js') ?>" defer></script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
