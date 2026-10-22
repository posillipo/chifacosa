<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/products.php';
$user = requireLogin();
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
        if ($url === '') {
            getDB()->prepare('DELETE FROM product_feeds WHERE user_id = ?')->execute([$uid]);
        } elseif (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $error = 'L\'URL del feed non è valido.';
        } else {
            getDB()->prepare('INSERT INTO product_feeds (user_id, feed_url) VALUES (?, ?) ON DUPLICATE KEY UPDATE feed_url = VALUES(feed_url)')->execute([$uid, $url]);
        }
    } elseif ($action === 'sync' && $feed) {
        $syncResult = syncProductFeed($uid, $feed['feed_url']);
        $msg = $syncResult['ok']
            ? $syncResult['added'] . ' aggiunti, ' . $syncResult['updated'] . ' aggiornati, ' . $syncResult['hidden'] . ' nascosti (' . $syncResult['total'] . ' totali)'
            : $syncResult['error'];
        getDB()->prepare('UPDATE product_feeds SET last_synced_at = ?, last_result = ? WHERE user_id = ?')
            ->execute([$syncResult['ok'] ? date('Y-m-d H:i:s') : ($feed['last_synced_at'] ?? null), mb_substr($msg, 0, 500), $uid]);
    }
    if ($action === 'save_feed' || $action === 'sync') {
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

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Incolla l'URL di un feed prodotti in formato Google Shopping (RSS) e premi
      <strong>Aggiorna prodotti</strong>: vengono importati nome, descrizione, prezzo di listino e
      scontato, link alla pagina e immagine principale. Le immagini <strong>non</strong> vengono
      scaricate: si salva solo il loro indirizzo. Le taglie sono ignorate (un prodotto per modello).
      Un prodotto che sparisce dal feed viene nascosto, non cancellato. L'aggiornamento è solo
      manuale. I prodotti sono poi disponibili agli strumenti MCP per programmare i post social.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($syncResult): ?>
    <div class="alert <?= $syncResult['ok'] ? 'success' : 'error' ?>">
      <?= $syncResult['ok']
        ? 'Aggiornamento completato: ' . (int) $syncResult['added'] . ' prodotti aggiunti, ' . (int) $syncResult['updated'] . ' aggiornati, ' . (int) $syncResult['hidden'] . ' nascosti (' . (int) $syncResult['total'] . ' nel feed).'
        : 'Aggiornamento non riuscito: ' . e($syncResult['error']) ?>
    </div>
  <?php endif; ?>

  <div class="section-title">Feed prodotti</div>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_feed">
    <label>URL del feed (Google Shopping, XML)</label>
    <input type="url" name="feed_url" value="<?= e($feed['feed_url'] ?? '') ?>" placeholder="https://esempio.it/feed-google-shopping.xml">
    <button type="submit" class="btn">Salva</button>
    <span style="color:var(--text-muted);font-size:12.5px;">Lascia vuoto e salva per scollegare il feed (i prodotti già importati restano).</span>
  </form>

  <?php if ($feed): ?>
    <div class="card">
      <strong>Aggiornamento</strong>
      <p style="color:var(--text-muted);margin:6px 0 14px;">
        <?= $totalAll - $hiddenCount ?> prodotti nel catalogo<?= $hiddenCount ? ' (' . $hiddenCount . ' nascosti)' : '' ?>.
        <?php if (!empty($feed['last_synced_at'])): ?>
          Ultimo aggiornamento: <?= e(formatLocalDateTime($feed['last_synced_at'], $profile)) ?>.
        <?php else: ?>Non ancora aggiornato.<?php endif; ?>
        <?php if (!empty($feed['last_result'])): ?><br><span style="font-size:12.5px;">Esito: <?= e($feed['last_result']) ?></span><?php endif; ?>
      </p>
      <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Aggiornamento in corso… (può richiedere un minuto)';">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="sync">
        <button type="submit" class="btn">Aggiorna prodotti</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($totalAll > 0): ?>
    <div class="section-title">Prodotti (<?= (int) $res['total'] ?>)</div>
    <form method="get" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
      <div style="flex:1;min-width:160px;"><label>Cerca</label><input type="text" name="q" value="<?= e($filters['q']) ?>"></div>
      <div style="flex:1;min-width:180px;"><label>Categoria</label>
        <select name="category">
          <option value="">Tutte</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= e($c['path']) ?>" <?= $filters['category'] === $c['path'] ? 'selected' : '' ?>><?= e($c['path']) ?> (<?= (int) $c['products'] ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Sconto</label>
        <select name="discount">
          <option value="">Tutti</option>
          <option value="1" <?= $disc === '1' ? 'selected' : '' ?>>Scontati</option>
          <option value="0" <?= $disc === '0' ? 'selected' : '' ?>>Non scontati</option>
        </select></div>
      <button type="submit" class="btn">Filtra</button>
    </form>

    <?php foreach ($res['rows'] as $row): $pr = productSerialize($row); ?>
      <div class="blog-item" style="display:flex;gap:14px;align-items:flex-start;">
        <?php if ($pr['image_url']): ?>
          <img src="<?= e($pr['image_url']) ?>" loading="lazy" referrerpolicy="no-referrer" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;" alt="">
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <strong><?= e($pr['title']) ?></strong>
          <div style="color:var(--text-muted);font-size:12.5px;">
            <?php if ($pr['has_discount']): ?>
              €<?= number_format($pr['price_sale'], 2, ',', '.') ?> <s>€<?= number_format($pr['price_regular'], 2, ',', '.') ?></s> (−<?= (int) $pr['discount_percent'] ?>%)
            <?php elseif ($pr['price_regular'] !== null): ?>
              €<?= number_format($pr['price_regular'], 2, ',', '.') ?>
            <?php endif; ?>
            <?= $pr['category'] ? ' · ' . e($pr['category']) : '' ?>
            <?= $pr['in_stock'] ? '' : ' · esaurito' ?>
            <?= $pr['times_published'] ? ' · pubblicato ' . (int) $pr['times_published'] . ' volte' : '' ?>
            <?php if ($pr['link']): ?> · <a href="<?= e($pr['link']) ?>" target="_blank" rel="noopener">pagina prodotto</a><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
      <p style="display:flex;gap:10px;align-items:center;">
        <?php if ($res['page'] > 1): ?><a class="btn small secondary" href="<?= e($qs(['page' => $res['page'] - 1])) ?>">← Precedenti</a><?php endif; ?>
        <span>Pagina <?= (int) $res['page'] ?> di <?= $pages ?></span>
        <?php if ($res['page'] < $pages): ?><a class="btn small secondary" href="<?= e($qs(['page' => $res['page'] + 1])) ?>">Successivi →</a><?php endif; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
