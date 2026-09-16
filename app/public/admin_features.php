<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'features';
$pageTitle = 'Funzioni del sito';
$success = null;

// Sezioni principali (tutto tranne i singoli moduli "che amo", raccolti sotto separatamente) —
// stesso elenco/ordine di PUBLIC_NAV_ITEM_KEYS, escludendo solo "Home" (sempre raggiungibile
// comunque, disattivarla dal menu non avrebbe un vero effetto se non confondere).
$mainSections = [];
foreach (PUBLIC_NAV_ITEM_KEYS as $name => $key) {
    if ($name === 'Home' || isset(CHE_AMO_MODULES[$key])) {
        continue;
    }
    $mainSections[$name] = $key;
}
// Tutte le chiavi che questa pagina espone davvero come checkbox — solo queste vanno considerate
// quando si calcola cosa disattivare: "Home" (mai mostrata qui) non deve finire per errore tra le
// disattivate solo perché non compare in $_POST['enabled'].
$toggleableKeys = array_merge(array_values($mainSections), array_keys(CHE_AMO_MODULES));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $enabled = $_POST['enabled'] ?? [];
    $disabled = [];
    foreach ($toggleableKeys as $navKey) {
        if (!in_array($navKey, $enabled, true)) {
            $disabled[] = $navKey;
        }
    }
    setSiteDisabledNavKeys($disabled);
    $success = 'Impostazioni salvate.';
}

$disabledKeys = getSiteDisabledNavKeys();

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Attiva o disattiva intere sezioni per <strong>tutti</strong> i profili di questa
      installazione, indipendentemente da cosa ha scelto ciascun profilo dal proprio "Menu di
      Navigazione". Utile per adattare il sito al tipo di attività che lo usa — es. un locale che
      non ha bisogno del modulo "Squadre che amo", o che non deve mostrare affatto la sezione
      "Che Amo".
    </p>
    <p style="color:var(--text-muted)">
      Una sezione disattivata qui sparisce ovunque (menu pubblico, pagine, scheda di gestione in
      dashboard) per tutti i profili, anche quelli creati in futuro — un profilo non può
      riattivarla da solo. Disattivare "Che Amo" nasconde l'intera vetrina; puoi anche lasciarla
      attiva e disattivare solo alcuni dei suoi moduli.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label>Sezioni principali</label>
    <?php foreach ($mainSections as $name => $key): ?>
      <label style="display:flex;align-items:center;gap:10px;font-weight:normal;margin-bottom:10px;">
        <input type="checkbox" name="enabled[]" value="<?= e($key) ?>" style="width:auto;" <?= in_array($key, $disabledKeys, true) ? '' : 'checked' ?>>
        <?= e($name) ?>
      </label>
    <?php endforeach; ?>

    <label style="margin-top:18px;">Moduli di "Che Amo"</label>
    <p style="color:var(--text-muted);font-size:13px;margin-top:-6px;">Restano comunque nascosti se "Che Amo" qui sopra è disattivato.</p>
    <?php foreach (CHE_AMO_MODULES as $key => $m): ?>
      <label style="display:flex;align-items:center;gap:10px;font-weight:normal;margin-bottom:10px;padding-left:24px;">
        <input type="checkbox" name="enabled[]" value="<?= e($key) ?>" style="width:auto;" <?= in_array($key, $disabledKeys, true) ? '' : 'checked' ?>>
        <i class="<?= e($m['icon']) ?>" style="width:18px;text-align:center;color:var(--text-muted);"></i>
        <?= e($m['label']) ?>
      </label>
    <?php endforeach; ?>

    <button type="submit" class="btn" style="margin-top:8px;">Salva</button>
  </form>
<?php include __DIR__ . '/_admin_footer.php'; ?>
