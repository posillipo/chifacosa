<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/products.php';
$user = requireLogin();
if (!siteToolEnabled('prodotti')) {
    http_response_code(404);
    exit('Questa funzione non è attiva su questo sito.');
}
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'products';
$pageTitle = 'Catalogo prodotti';
$error = null;
$syncResult = null;
$uid = (int) $profile['id'];

$stmt = getDB()->prepare('SELECT * FROM product_feeds WHERE user_id = ?');
$stmt->execute([$uid]);
$feed = $stmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save_feed') {
        $url = trim($_POST['feed_url'] ?? '');
        $type = array_key_exists($_POST['feed_type'] ?? '', PRODUCT_FEED_TYPES) ? $_POST['feed_type'] : 'google';
        if ($url === '') {
            getDB()->prepare('DELETE FROM product_feeds WHERE user_id = ?')->execute([$uid]);
        } elseif (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $error = 'L\'URL del feed non è valido.';
        } else {
            try {
                getDB()->prepare('INSERT INTO product_feeds (user_id, feed_url, feed_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE feed_url = VALUES(feed_url), feed_type = VALUES(feed_type)')->execute([$uid, $url, $type]);
            } catch (Throwable $ex) {
                // Colonna feed_type assente (migrazione non ancora eseguita): resta valido solo il tipo Google Shopping.
                if ($type !== 'google') {
                    $error = 'Per usare WooCommerce esegui prima la migrazione 2026_11_14_product_feed_type.sql sul database.';
                } else {
                    getDB()->prepare('INSERT INTO product_feeds (user_id, feed_url) VALUES (?, ?) ON DUPLICATE KEY UPDATE feed_url = VALUES(feed_url)')->execute([$uid, $url]);
                }
            }
        }
    } elseif ($action === 'delete_all_products') {
        // Svuota il catalogo del PROFILO ATTIVO (mai di altri: user_id preso dalla sessione, non dal modulo).
        // Il registro delle pubblicazioni dei prodotti si cancella con loro (ON DELETE CASCADE).
        $del = getDB()->prepare('DELETE FROM products WHERE user_id = ?');
        $del->execute([$uid]);
        $deletedCount = $del->rowCount();
        getDB()->prepare('UPDATE product_feeds SET last_synced_at = NULL, last_result = NULL WHERE user_id = ?')->execute([$uid]);
    } elseif ($action === 'sync' && $feed) {
        $syncResult = syncProductFeed($uid, $feed['feed_url'], $feed['feed_type'] ?? 'google');
        $msg = $syncResult['ok']
            ? $syncResult['added'] . ' aggiunti, ' . $syncResult['updated'] . ' aggiornati, ' . $syncResult['hidden'] . ' nascosti (' . $syncResult['total'] . ' totali)'
            : $syncResult['error'];
        getDB()->prepare('UPDATE product_feeds SET last_synced_at = ?, last_result = ? WHERE user_id = ?')
            ->execute([$syncResult['ok'] ? date('Y-m-d H:i:s') : ($feed['last_synced_at'] ?? null), mb_substr($msg, 0, 500), $uid]);
    }
    if ($action === 'save_feed' || $action === 'sync' || $action === 'delete_all_products') {
        $stmt = getDB()->prepare('SELECT * FROM product_feeds WHERE user_id = ?');
        $stmt->execute([$uid]);
        $feed = $stmt->fetch() ?: null;
    }
}

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'category' => trim($_GET['category'] ?? ''),
    'page' => (int) ($_GET['page'] ?? 1),
    'per_page' => 30,
];
$disc = $_GET['discount'] ?? '';
if ($disc === '1') { $filters['has_discount'] = true; } elseif ($disc === '0') { $filters['has_discount'] = false; }
$res = productQuery($uid, $filters);
$categories = productCategories($uid);
$totalAll = (int) getDB()->query('SELECT COUNT(*) FROM products WHERE user_id = ' . $uid)->fetchColumn();
$hiddenCount = (int) getDB()->query('SELECT COUNT(*) FROM products WHERE is_hidden = 1 AND user_id = ' . $uid)->fetchColumn();
$pages = max(1, (int) ceil($res['total'] / $res['per_page']));
$qs = function (array $over = []) use ($filters, $disc) {
    return '?' . http_build_query(array_filter(array_merge(['q' => $filters['q'], 'category' => $filters['category'], 'discount' => $disc], $over), fn ($v) => $v !== '' && $v !== null));
};

include __DIR__ . '/../_dash_header.php';
$visibleN = $totalAll - $hiddenCount;
$feedDyn = ['google' => ['URL del feed (Google Shopping, XML)', 'https://esempio.it/feed-google-shopping.xml', 'Feed prodotti in formato Google Shopping (RSS), come quello di PrestaShop.'],
            'woocommerce' => ['Indirizzo del sito WooCommerce', 'https://www.esempio.it', "Basta l'indirizzo del sito: i prodotti si leggono dalla sua API pubblica (prezzi, immagini, categorie). Senza API si usa il feed RSS, con meno dati."]];
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (isset($deletedCount)): ?><div class="alert success"><?= (int) $deletedCount ?> prodotti eliminati da questo profilo. Ora puoi ripetere l'importazione con <strong>Aggiorna prodotti</strong>.</div><?php endif; ?>
  <?php if ($syncResult): ?><div class="alert <?= $syncResult['ok'] ? 'success' : 'error' ?>"><?= $syncResult['ok'] ? 'Aggiornamento completato: ' . (int) $syncResult['added'] . ' prodotti aggiunti, ' . (int) $syncResult['updated'] . ' aggiornati, ' . (int) $syncResult['hidden'] . ' nascosti (' . (int) $syncResult['total'] . ' totali).' : 'Aggiornamento non riuscito: ' . e($syncResult['error']) ?></div><?php endif; ?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= $totalAll ?></b><span>Prodotti importati</span></div>
      <div class="tlx-kpi"><b><?= $visibleN ?></b><span>Visibili</span></div>
      <div class="tlx-kpi"><b><?= $hiddenCount ?></b><span>Nascosti</span></div>
      <div class="tlx-kpi"><b><?= !empty($feed['last_synced_at']) ? e(formatLocalDateTime($feed['last_synced_at'], $profile, 'd/m H:i')) : 'Mai' ?></b><span>Ultimo aggiornamento</span></div>
    </div>

    <form method="post" class="box">
      <?= csrfField() ?><input type="hidden" name="action" value="save_feed">
      <h3><i class="fa-solid fa-bag-shopping"></i> Feed prodotti</h3>
      <label for="feed-type">Tipo di feed</label>
      <select name="feed_type" id="feed-type" data-dyn>
        <?php foreach (PRODUCT_FEED_TYPES as $k => $label): $d = $feedDyn[$k] ?? $feedDyn['google']; ?>
          <option value="<?= e($k) ?>" data-l="<?= e($d[0]) ?>" data-p="<?= e($d[1]) ?>" data-h="<?= e($d[2]) ?>" <?= ($feed['feed_type'] ?? 'google') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="feed-url" data-dyn-label>URL del feed</label>
      <input type="url" name="feed_url" id="feed-url" value="<?= e($feed['feed_url'] ?? '') ?>" data-dyn-input>
      <p class="tlx-hint" data-dyn-help></p>
      <div class="box-row"><button type="submit" class="tlx-submit cmp-done">Salva</button><span class="tlx-hint">Lascia vuoto e salva per scollegare il feed (i prodotti già importati restano).</span></div>
    </form>

    <?php if ($feed): ?>
      <div class="box">
        <h3><i class="fa-solid fa-rotate"></i> Aggiornamento</h3>
        <p><?= $visibleN ?> prodotti nel catalogo<?= $hiddenCount ? ' (' . $hiddenCount . ' nascosti)' : '' ?>.<?= !empty($feed['last_synced_at']) ? ' Ultimo aggiornamento: ' . e(formatLocalDateTime($feed['last_synced_at'], $profile)) . '.' : ' Non ancora aggiornato.' ?><?php if (!empty($feed['last_result'])): ?><br><small>Esito: <?= e($feed['last_result']) ?></small><?php endif; ?></p>
        <form method="post" data-busy="Aggiornamento in corso… (può richiedere un minuto)"><?= csrfField() ?><input type="hidden" name="action" value="sync"><button type="submit" class="stc-act primary"><i class="fa-solid fa-rotate"></i> <span>Aggiorna prodotti</span></button></form>
      </div>
    <?php endif; ?>

    <?php if ($totalAll > 0):
      $stmtPub = getDB()->prepare('SELECT COUNT(*) FROM product_publications WHERE user_id = ?'); $stmtPub->execute([$uid]); $pubCount = (int) $stmtPub->fetchColumn();
      $delMsg = 'Eliminare TUTTI i ' . $totalAll . ' prodotti del profilo "' . ($profile['display_name'] ?? $profile['slug'] ?? '') . '"?'
              . ($pubCount > 0 ? ' Verranno cancellate anche ' . $pubCount . ' registrazioni di pubblicazioni social collegate.' : '')
              . " L'operazione non si può annullare, ma potrai importare di nuovo il feed.";
    ?>
      <div class="tlx-listhead"><h2>Prodotti <small>(<?= (int) $res['total'] ?>)</small></h2></div>
      <form method="get" class="blx-toolbar">
        <div class="blx-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Cerca un prodotto..."></div>
        <select name="category" aria-label="Categoria"><option value="">Tutte le categorie</option><?php foreach ($categories as $c): ?><option value="<?= e($c['path']) ?>" <?= $filters['category'] === $c['path'] ? 'selected' : '' ?>><?= e($c['path']) ?> (<?= (int) $c['products'] ?>)</option><?php endforeach; ?></select>
        <select name="discount" aria-label="Sconto"><option value="">Tutti</option><option value="1" <?= $disc === '1' ? 'selected' : '' ?>>Scontati</option><option value="0" <?= $disc === '0' ? 'selected' : '' ?>>Non scontati</option></select>
        <button type="submit" class="tlx-chip">Filtra</button>
      </form>
      <div class="prx">
        <?php foreach ($res['rows'] as $row): $p = productSerialize($row); ?>
          <article class="prx-c">
            <?php if ($p['image_url']): ?><img src="<?= e($p['image_url']) ?>" loading="lazy" referrerpolicy="no-referrer" alt=""><?php else: ?><span class="stc-ph prx-ph"><i class="fa-solid fa-bag-shopping"></i></span><?php endif; ?>
            <div class="prx-b">
              <b><?= e($p['title']) ?></b>
              <div class="prx-p"><?php if ($p['has_discount']): ?>€<?= number_format($p['price_sale'], 2, ',', '.') ?> <s>€<?= number_format($p['price_regular'], 2, ',', '.') ?></s> <span class="prx-off">−<?= (int) $p['discount_percent'] ?>%</span><?php elseif ($p['price_regular'] !== null): ?>€<?= number_format($p['price_regular'], 2, ',', '.') ?><?php endif; ?></div>
              <small class="lkx-hint"><?= $p['category'] ? e($p['category']) : '' ?><?= $p['in_stock'] ? '' : ' · esaurito' ?><?= $p['times_published'] ? ' · pubblicato ' . (int) $p['times_published'] . ' volte' : '' ?></small>
              <?php if ($p['link']): ?><a href="<?= e($p['link']) ?>" target="_blank" rel="noopener" class="lkx-hint">Pagina prodotto ↗</a><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if ($pages > 1): ?>
        <div class="pnx-pg">
          <?php if ($res['page'] > 1): ?><a class="stc-act" href="<?= e($qs(['page' => $res['page'] - 1])) ?>"><i class="fa-solid fa-arrow-left"></i> <span>Precedenti</span></a><?php endif; ?>
          <span>Pagina <?= (int) $res['page'] ?> di <?= $pages ?></span>
          <?php if ($res['page'] < $pages): ?><a class="stc-act" href="<?= e($qs(['page' => $res['page'] + 1])) ?>"><span>Successivi</span> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="box box-danger">
        <h3><i class="fa-solid fa-triangle-exclamation"></i> Elimina i prodotti importati</h3>
        <p>Svuota il catalogo di questo profilo (<?= $totalAll ?> prodotti). Il feed resta collegato: dopo puoi ripetere l'importazione con «Aggiorna prodotti». Gli altri profili non vengono toccati.</p>
        <form method="post" data-confirm="<?= e($delMsg) ?>"><?= csrfField() ?><input type="hidden" name="action" value="delete_all_products"><button type="submit" class="stc-act danger"><i class="fa-solid fa-trash"></i> <span>Elimina tutti i prodotti</span></button></form>
      </div>
    <?php endif; ?>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
