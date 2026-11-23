<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
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

include __DIR__ . '/../_dash_header.php';
?>
  <div class="page-head"><h1 class="page-head-title">Directory</h1><?= renderPageHelpButton('dashboard_directory', 'Directory') ?></div>
  <div class="tlx">
    <form method="get" class="box dirx-filter">
      <div><label for="dir-prov">Provincia</label>
        <select id="dir-prov" name="prov"><option value="">Tutte le province</option>
          <?php foreach ($provinces as $sg => $pn): ?><option value="<?= e($sg) ?>" <?= $provQ === $sg ? 'selected' : '' ?>><?= e($pn) ?> (<?= e($sg) ?>)</option><?php endforeach; ?>
        </select></div>
      <div><label for="dir-city">Città</label>
        <input type="text" id="dir-city" name="citta" value="<?= e($cityQ) ?>" placeholder="es. Portici" list="dir-cities">
        <datalist id="dir-cities"><?php foreach (($provQ && isset($index[$provQ]) ? $index[$provQ]['cities'] : []) as $cn => $n): ?><option value="<?= e($cn) ?>"><?php endforeach; ?></datalist></div>
      <div class="dirx-go"><button type="submit" class="tlx-submit cmp-done">Cerca</button><?php if ($provQ || $cityQ !== ''): ?><a href="/dashboard_directory.php" class="stc-act">Azzera</a><?php endif; ?></div>
    </form>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
    <div id="dir-map" class="dirx-map" data-profile-base="/dashboard_profile_view.php?slug=" data-points="<?= e(json_encode($points, JSON_UNESCAPED_UNICODE)) ?>"></div>
    <?php if (!$points && $rows): ?><p class="tlx-hint">I profili trovati non hanno ancora una posizione sulla mappa: si calcola dalla città e dalla provincia e compare dopo il prossimo salvataggio del profilo.</p><?php endif; ?>

    <div class="tlx-listhead"><h2>Risultati <small>(<?= count($rows) ?>)</small></h2></div>
    <?php if (!$rows): ?><div class="tlx-empty"><i class="fa-solid fa-map-location-dot"></i><b>Nessuna band trovata</b><span><?= ($provQ || $cityQ !== '') ? 'Prova con un\'altra città o provincia.' : 'Non ci sono ancora profili con città e provincia.' ?></span></div><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <div class="pnx">
        <?php if ($r['avatar_path']): ?><img class="pnx-img rnd" src="/<?= e($r['avatar_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr((string) ($r['display_name'] ?: $r['slug']), 0, 1))) ?></span><?php endif; ?>
        <div class="pnx-b"><b><a href="/dashboard_profile_view.php?slug=<?= e(rawurlencode($r['slug'])) ?>"><?= e($r['display_name'] ?: $r['slug']) ?></a></b><small><?= e($r['citta']) ?> (<?= e(strtoupper((string) $r['provincia'])) ?>)<?= !empty($r['genere']) ? ' · ' . e($r['genere']) : '' ?></small></div>
        <?php if (isset($r['geo_lat']) && $r['geo_lat'] !== null): ?><div class="pnx-act"><a href="#" class="stc-act" data-locate data-lat="<?= e($r['geo_lat']) ?>" data-lng="<?= e($r['geo_lng']) ?>"><i class="fa-solid fa-location-dot"></i> <span>Mappa</span></a></div><?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="tlx-listhead"><h2>Indice per provincia</h2></div>
    <?php if (!$index): ?><div class="tlx-empty"><i class="fa-solid fa-list"></i><b>Indice vuoto</b><span>Nessun profilo ha ancora indicato città e provincia.</span></div><?php endif; ?>
    <?php foreach ($index as $sg => $pv): ?>
      <div class="box dirx-prov">
        <h3><a href="?prov=<?= e($sg) ?>"><?= e($pv['name']) ?> <small>(<?= e($sg) ?>) · <?= (int) $pv['n'] ?></small></a></h3>
        <div class="dirx-cities"><?php foreach ($pv['cities'] as $cn => $n): ?><a class="sqx-chip" href="?prov=<?= e($sg) ?>&amp;citta=<?= e(rawurlencode($cn)) ?>"><?= e($cn) ?> · <?= (int) $n ?></a><?php endforeach; ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
  <script src="/assets/js/directory.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/directory.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
