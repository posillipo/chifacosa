<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
// Riguarda tutti i tipi di contenuto del profilo, non solo Timeline/Brani che amo: riservato al
// solo owner, come le altre pagine che toccano l'identità/vetrina del profilo nel suo insieme.
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'featured';
$pageTitle = 'Primo Piano';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'pin') {
        $type = (string) ($_POST['tipo'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            pinContentItem((int) $profile['id'], $type, $id);
        }
    } elseif ($action === 'unpin') {
        unpinContentItem((int) $profile['id'], (int) ($_POST['pin_id'] ?? 0));
    } elseif ($action === 'move') {
        movePinnedItem((int) $profile['id'], (int) ($_POST['pin_id'] ?? 0), ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down');
    }
    $backQs = trim($_POST['q'] ?? '') !== '' ? '?' . http_build_query(['q' => trim($_POST['q'])]) : '';
    header('Location: /dashboard_featured.php' . $backQs);
    exit;
}

$q = trim($_GET['q'] ?? '');
$searchResults = $q !== '' ? searchPinnableContent((int) $profile['id'], $q) : [];
$pinnedItems = getPinnedItemsForUser((int) $profile['id'], false);

include __DIR__ . '/../_dash_header.php';
$pinN = count($pinnedItems);
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= $pinN ?></b><span>Elementi fissati</span></div>
      <div class="tlx-kpi"><b><?= $pinN >= 2 ? 'Sì' : 'No' ?></b><span>Carosello visibile</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>/timeline" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>Vedi la Timeline</span></a>
      <div class="tlx-kpi"><b><?= $pinN >= 2 ? '✓' : max(0, 2 - $pinN) ?></b><span><?= $pinN >= 2 ? 'Tutto a posto' : 'Ne servono ancora' ?></span></div>
    </div>
    <details class="vdx-edit"><summary><i class="fa-regular fa-circle-question"></i> Cos'è «Primo Piano»</summary>
      <p class="tlx-hint">Gli elementi che fissi qui compaiono sempre in cima alla Timeline pubblica (Home e pagina Timeline), in un carosello dedicato, ma solo quando ne hai fissati almeno 2. Restano visibili anche nella loro normale posizione cronologica: fissarli non li rimuove dal flusso normale.</p>
    </details>

    <form method="get" class="blx-toolbar">
      <div class="blx-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cerca per titolo tra tutti i tuoi contenuti..."></div>
      <button type="submit" class="tlx-chip">Cerca</button>
      <?php if ($q !== ''): ?><a href="/dashboard_featured.php" class="tlx-chip">Azzera</a><?php endif; ?>
    </form>

    <?php if ($q !== ''): ?>
      <div class="tlx-listhead"><h2>Risultati <small>(<?= count($searchResults) ?>)</small></h2></div>
      <?php if (!$searchResults): ?><div class="tlx-empty"><i class="fa-solid fa-magnifying-glass"></i><b>Nessun contenuto trovato</b><span>Prova con altre parole.</span></div><?php endif; ?>
      <?php foreach ($searchResults as $r): $cu = $r['cover'] ? (str_starts_with($r['cover'], 'http') ? $r['cover'] : '/' . $r['cover']) : ''; ?>
        <div class="pnx">
          <?php if ($cu): ?><img class="pnx-img" src="<?= e($cu) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img stc-ph"><i class="fa-regular fa-file"></i></span><?php endif; ?>
          <div class="pnx-b"><span class="pnx-t"><?= e($r['label']) ?></span><b><?= e(textExcerpt($r['titolo'], 90)) ?></b></div>
          <div class="pnx-act">
            <?php if ($r['pinned']): ?><span class="stc-status stc-s-grey"><i class="fa-solid fa-thumbtack"></i> Già fissato</span>
            <?php else: ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="pin"><input type="hidden" name="tipo" value="<?= e($r['tipo']) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>"><button type="submit" class="stc-act primary"><i class="fa-solid fa-thumbtack"></i> <span>Fissa</span></button></form><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="tlx-listhead"><h2>Elementi fissati <small>(<?= $pinN ?>)</small></h2><?php if ($pinN === 1): ?><span class="lkx-hint">ne serve almeno un altro per mostrare il carosello</span><?php endif; ?></div>
    <?php if (!$pinnedItems): ?><div class="tlx-empty"><i class="fa-solid fa-thumbtack"></i><b>Non hai ancora fissato nulla</b><span>Cerca un contenuto qui sopra e tocca «Fissa».</span></div><?php endif; ?>
    <?php foreach ($pinnedItems as $i => $it): $cu = $it['cover'] ? (str_starts_with($it['cover'], 'http') ? $it['cover'] : '/' . $it['cover']) : ''; ?>
      <div class="pnx">
        <span class="kit-grip kit-num"><b><?= $i + 1 ?></b></span>
        <?php if ($cu): ?><img class="pnx-img" src="<?= e($cu) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img stc-ph"><i class="fa-regular fa-file"></i></span><?php endif; ?>
        <div class="pnx-b"><span class="pnx-t"><?= e(PINNABLE_CONTENT_TYPES[$it['tipo']]['label'] ?? $it['tipo']) ?></span><b><?= e(textExcerpt($it['titolo'], 90)) ?></b><small><a href="<?= e($it['url']) ?>" target="_blank">Vedi online ↗</a></small></div>
        <div class="pnx-act">
          <?php if ($i > 0): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="move"><input type="hidden" name="pin_id" value="<?= (int) $it['pin_id'] ?>"><input type="hidden" name="direction" value="up"><button type="submit" class="lkx-btn" title="Sposta su" aria-label="Sposta su"><i class="fa-solid fa-arrow-up"></i></button></form><?php endif; ?>
          <?php if ($i < $pinN - 1): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="move"><input type="hidden" name="pin_id" value="<?= (int) $it['pin_id'] ?>"><input type="hidden" name="direction" value="down"><button type="submit" class="lkx-btn" title="Sposta giù" aria-label="Sposta giù"><i class="fa-solid fa-arrow-down"></i></button></form><?php endif; ?>
          <form method="post" data-confirm="Togliere il pin da questo elemento?"><?= csrfField() ?><input type="hidden" name="action" value="unpin"><input type="hidden" name="pin_id" value="<?= (int) $it['pin_id'] ?>"><button type="submit" class="lkx-btn danger" title="Rimuovi" aria-label="Rimuovi"><i class="fa-solid fa-xmark"></i></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
