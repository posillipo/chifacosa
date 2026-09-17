<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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

include __DIR__ . '/_dash_header.php';
?>
  <div class="card">
    <strong>Cos'è "Primo Piano"</strong>
    <p style="color:var(--text-muted);margin-bottom:0;">
      Gli elementi che fissi qui compaiono sempre in cima alla Timeline pubblica (Home e pagina
      Timeline), in un carosello dedicato — ma solo quando ne hai fissati almeno 2: con uno solo
      non compare nulla. Restano comunque visibili anche nella loro normale posizione
      cronologica più sotto: fissarli non li rimuove dal flusso normale.
    </p>
  </div>

  <form method="get" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cerca per titolo tra tutti i tuoi contenuti..." style="flex:1;min-width:220px;margin-bottom:0;">
    <button type="submit" class="btn" style="width:auto;">Cerca</button>
    <?php if ($q !== ''): ?><a href="/dashboard_featured.php" class="btn small secondary">Azzera</a><?php endif; ?>
  </form>

  <?php if ($q !== ''): ?>
  <div class="section-title">Risultati (<?= count($searchResults) ?>)</div>
  <?php if (!$searchResults): ?>
    <div class="alert error">Nessun contenuto trovato per questa ricerca.</div>
  <?php endif; ?>
  <?php foreach ($searchResults as $r): ?>
    <div class="card" style="display:flex;gap:14px;align-items:center;">
      <?php if ($r['cover']):
        $coverUrl = str_starts_with($r['cover'], 'http') ? $r['cover'] : '/' . $r['cover'];
      ?>
        <img src="<?= e($coverUrl) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div style="color:var(--text-muted);font-size:12px;text-transform:uppercase;"><?= e($r['label']) ?></div>
        <strong><?= e(textExcerpt($r['titolo'], 90)) ?></strong>
      </div>
      <?php if ($r['pinned']): ?>
        <span class="btn small secondary" style="width:auto;flex-shrink:0;" aria-disabled="true">📌 Già fissato</span>
      <?php else: ?>
        <form method="post" style="flex-shrink:0;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="pin">
          <input type="hidden" name="tipo" value="<?= e($r['tipo']) ?>">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="q" value="<?= e($q) ?>">
          <button type="submit" class="btn small" style="width:auto;">📌 Fissa</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="section-title">Elementi fissati (<?= count($pinnedItems) ?>)<?= count($pinnedItems) === 1 ? ' — ne serve almeno un altro per mostrare il carosello' : '' ?></div>
  <?php if (!$pinnedItems): ?>
    <div class="alert error">Non hai ancora fissato nulla — cercalo qui sopra.</div>
  <?php endif; ?>
  <?php foreach ($pinnedItems as $i => $it): ?>
    <div class="card" style="display:flex;gap:14px;align-items:center;">
      <?php if ($it['cover']):
        $coverUrl = str_starts_with($it['cover'], 'http') ? $it['cover'] : '/' . $it['cover'];
      ?>
        <img src="<?= e($coverUrl) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div style="color:var(--text-muted);font-size:12px;text-transform:uppercase;"><?= e(PINNABLE_CONTENT_TYPES[$it['tipo']]['label'] ?? $it['tipo']) ?></div>
        <strong><?= e(textExcerpt($it['titolo'], 90)) ?></strong>
        <p style="margin:2px 0 0;"><a href="<?= e($it['url']) ?>" target="_blank" style="font-size:12.5px;">↗ Vedi online</a></p>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <?php if ($i > 0): ?>
        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="move"><input type="hidden" name="pin_id" value="<?= (int) $it['pin_id'] ?>"><input type="hidden" name="direction" value="up"><button type="submit" class="icon-btn" title="Sposta su">↑</button></form>
        <?php endif; ?>
        <?php if ($i < count($pinnedItems) - 1): ?>
        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="move"><input type="hidden" name="pin_id" value="<?= (int) $it['pin_id'] ?>"><input type="hidden" name="direction" value="down"><button type="submit" class="icon-btn" title="Sposta giù">↓</button></form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Togliere il pin da questo elemento?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="unpin">
          <input type="hidden" name="pin_id" value="<?= (int) $it['pin_id'] ?>">
          <button type="submit" class="btn small danger" style="width:auto;">Rimuovi</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
