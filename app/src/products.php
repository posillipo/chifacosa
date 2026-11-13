<?php
// Catalogo prodotti importato da un feed Google Shopping (RSS con namespace g:). Si salvano solo
// nome, descrizione, prezzi, link e URL dell'immagine principale (mai il file): un prodotto per
// gruppo di taglie, le taglie non vengono memorizzate.

const PRODUCT_FEED_MAX_BYTES = 60 * 1024 * 1024;
const PRODUCT_GNS = 'http://base.google.com/ns/1.0';

// "449 EUR" -> [449.0, 'EUR']
function productParsePrice(string $raw): array {
    if (!preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*([A-Za-z]{3})?/', trim($raw), $m)) {
        return [null, null];
    }
    return [round((float) str_replace(',', '.', $m[1]), 2), isset($m[2]) ? strtoupper($m[2]) : null];
}

function productFeedDownload(string $url): array {
    if (!filter_var($url, FILTER_VALIDATE_URL) || !isSafePublicUrl($url)) {
        return ['error' => 'URL del feed non valido o non pubblico.'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pfeed_');
    $fh = fopen($tmp, 'wb');
    $written = 0;
    $tooBig = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($fh, &$written, &$tooBig) {
            $written += strlen($chunk);
            if ($written > PRODUCT_FEED_MAX_BYTES) {
                $tooBig = true;
                return 0;
            }
            return fwrite($fh, $chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    if ($tooBig) {
        @unlink($tmp);
        return ['error' => 'Il feed supera il limite di ' . (PRODUCT_FEED_MAX_BYTES / 1048576) . ' MB.'];
    }
    if (!$ok || $code !== 200) {
        @unlink($tmp);
        return ['error' => 'Impossibile scaricare il feed (HTTP ' . $code . ').'];
    }
    return ['path' => $tmp];
}

// Sincronizza il catalogo del profilo con il feed. Ritorna ['ok'=>true, added, updated, hidden, total]
// oppure ['ok'=>false, 'error'=>...].
function syncProductFeed(int $userId, string $feedUrl, string $type = 'google'): array {
    @set_time_limit(300);
    if ($type === 'woocommerce') {
        return syncWooCommerceFeed($userId, $feedUrl);
    }
    $dl = productFeedDownload($feedUrl);
    if (isset($dl['error'])) {
        return ['ok' => false, 'error' => $dl['error']];
    }
    $reader = new XMLReader();
    if (!$reader->open($dl['path'], null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        @unlink($dl['path']);
        return ['ok' => false, 'error' => 'Il feed non è un XML leggibile.'];
    }

    // Un solo prodotto per gruppo: si tiene in memoria solo ciò che serve, non il feed intero.
    $products = [];
    $doc = new DOMDocument();
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'item') {
            continue;
        }
        $node = simplexml_import_dom($doc->importNode($reader->expand(), true));
        $g = $node->children(PRODUCT_GNS);
        $id = trim((string) $g->id);
        $title = trim((string) $g->title);
        if ($id === '' || $title === '') {
            continue;
        }
        $group = trim((string) $g->item_group_id);
        $ref = $group !== '' ? 'g' . $group : 'i' . $id;
        $inStock = stripos((string) $g->availability, 'in stock') !== false || stripos((string) $g->availability, 'in_stock') !== false;
        if (isset($products[$ref])) {
            $products[$ref]['in_stock'] = $products[$ref]['in_stock'] || $inStock;
            continue;
        }
        // La taglia in fondo al titolo ("Cappotto blu 1599 46") non fa parte del nome del prodotto.
        $size = trim((string) $g->size);
        if ($size !== '' && preg_match('/^(.*\S)\s+' . preg_quote($size, '/') . '$/u', $title, $m)) {
            $title = $m[1];
        }
        [$regular, $cur] = productParsePrice((string) $g->price);
        [$sale] = productParsePrice((string) $g->sale_price);
        if ($sale !== null && ($regular === null || $sale >= $regular)) {
            $sale = null;
        }
        $products[$ref] = [
            'title' => mb_substr($title, 0, 255),
            'description' => trim((string) $g->description) ?: null,
            'link' => mb_substr(trim((string) $g->link), 0, 600) ?: null,
            'image_url' => mb_substr(trim((string) $g->image_link), 0, 600) ?: null,
            'price_regular' => $regular,
            'price_sale' => $sale,
            'currency' => $cur ?: 'EUR',
            'category_path' => mb_substr(trim((string) $g->product_type), 0, 255) ?: null,
            'in_stock' => $inStock,
            'featured' => strtolower(trim((string) $g->featured_product)) === 'true',
        ];
    }
    $reader->close();
    @unlink($dl['path']);

    if (!$products) {
        return ['ok' => false, 'error' => 'Nessun prodotto trovato nel feed (formato Google Shopping atteso).'];
    }
    return productFeedSave($userId, $products);
}

// Scrive nel catalogo i prodotti letti da un feed (qualunque tipo): inserisce/aggiorna per
// external_ref e nasconde quelli spariti. Ritorna ['ok'=>true, added, updated, hidden, total].
function productFeedSave(int $userId, array $products): array {
    $db = getDB();
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare('SELECT external_ref FROM products WHERE user_id = ?');
    $stmt->execute([$userId]);
    $known = array_flip(array_column($stmt->fetchAll(), 'external_ref'));

    $up = $db->prepare('INSERT INTO products (user_id, external_ref, title, description, link, image_url, price_regular, price_sale, currency, category_path, in_stock, featured, is_hidden, last_seen_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)
        ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), link=VALUES(link), image_url=VALUES(image_url),
            price_regular=VALUES(price_regular), price_sale=VALUES(price_sale), currency=VALUES(currency), category_path=VALUES(category_path),
            in_stock=VALUES(in_stock), featured=VALUES(featured), is_hidden=0, last_seen_at=VALUES(last_seen_at)');
    $added = 0;
    $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($products as $ref => $p) {
            $up->execute([$userId, $ref, $p['title'], $p['description'], $p['link'], $p['image_url'], $p['price_regular'], $p['price_sale'],
                $p['currency'], $p['category_path'], $p['in_stock'] ? 1 : 0, $p['featured'] ? 1 : 0, $now]);
            isset($known[$ref]) ? $updated++ : $added++;
        }
        // I prodotti spariti dal feed si nascondono (non si cancellano: il registro delle pubblicazioni resta).
        $hid = $db->prepare('UPDATE products SET is_hidden = 1 WHERE user_id = ? AND is_hidden = 0 AND (last_seen_at IS NULL OR last_seen_at < ?)');
        $hid->execute([$userId, $now]);
        $hidden = $hid->rowCount();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('syncProductFeed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Errore durante il salvataggio dei prodotti.'];
    }
    return ['ok' => true, 'added' => $added, 'updated' => $updated, 'hidden' => $hidden, 'total' => count($products)];
}

// Tipi di feed selezionabili in dashboard_products.php.
const PRODUCT_FEED_TYPES = [
    'google' => 'Google Shopping / PrestaShop (feed XML)',
    'woocommerce' => 'WooCommerce (indirizzo del sito)',
];

// Testo semplice da un frammento HTML di WordPress ("&#8217;" -> "’", niente tag).
function productPlainText(string $html): string {
    $t = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>', '</h3>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $t)));
}

// Scarica un indirizzo e ritorna il corpo (con gli stessi controlli di sicurezza del feed
// Google Shopping: solo URL pubblici, dimensione massima), oppure null.
function productHttpGet(string $url): ?string {
    $dl = productFeedDownload($url);
    if (isset($dl['path'])) {
        $body = (string) file_get_contents($dl['path']);
        @unlink($dl['path']);
        return $body;
    }
    return null;
}

// Sincronizza con un sito WooCommerce. Si parte dall'indirizzo del sito (anche se è stato
// incollato quello del feed RSS: se ne usa solo la radice) e si usa la Store API pubblica
// (/wp-json/wc/store/v1/products, nessuna chiave): nome, descrizione, prezzi, immagine,
// categoria, disponibilità. Se la Store API non risponde si ricade sul feed RSS di WordPress
// (solo titolo, descrizione e link). I prezzi a 0 o assenti restano "senza prezzo".
function syncWooCommerceFeed(int $userId, string $siteUrl): array {
    @set_time_limit(300);
    $parts = parse_url(trim($siteUrl));
    if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'Indirizzo del sito non valido.'];
    }
    $origin = strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

    $products = [];
    $apiOk = false;
    for ($page = 1; $page <= 50; $page++) {
        $body = productHttpGet($origin . '/wp-json/wc/store/v1/products?per_page=100&page=' . $page);
        $rows = $body !== null ? json_decode($body, true) : null;
        if (!is_array($rows) || ($rows && !isset($rows[0]['id']))) {
            break;
        }
        $apiOk = true;
        foreach ($rows as $r) {
            $title = trim(productPlainText((string) ($r['name'] ?? '')));
            if ($title === '' || empty($r['id'])) {
                continue;
            }
            $pr = $r['prices'] ?? [];
            $div = 10 ** max(0, (int) ($pr['currency_minor_unit'] ?? 2));
            $regular = isset($pr['regular_price']) && $pr['regular_price'] !== '' ? round((float) $pr['regular_price'] / $div, 2) : null;
            $sale = isset($pr['sale_price']) && $pr['sale_price'] !== '' ? round((float) $pr['sale_price'] / $div, 2) : null;
            if ($regular !== null && $regular <= 0) {
                $regular = null;
            }
            if ($sale !== null && ($sale <= 0 || $regular === null || $sale >= $regular)) {
                $sale = null;
            }
            $desc = productPlainText((string) (($r['short_description'] ?? '') !== '' ? $r['short_description'] : ($r['description'] ?? '')));
            $img = $r['images'][0]['src'] ?? '';
            $cat = $r['categories'][0]['name'] ?? '';
            $products['w' . (int) $r['id']] = [
                'title' => mb_substr($title, 0, 255),
                'description' => $desc !== '' ? mb_substr($desc, 0, 5000) : null,
                'link' => mb_substr(trim((string) ($r['permalink'] ?? '')), 0, 600) ?: null,
                'image_url' => $img !== '' ? mb_substr($img, 0, 600) : null,
                'price_regular' => $regular,
                'price_sale' => $sale,
                'currency' => strtoupper((string) ($pr['currency_code'] ?? 'EUR')) ?: 'EUR',
                'category_path' => $cat !== '' ? mb_substr(productPlainText($cat), 0, 255) : null,
                'in_stock' => !isset($r['is_in_stock']) || (bool) $r['is_in_stock'],
                'featured' => false,
            ];
        }
        if (count($rows) < 100) {
            break;
        }
    }

    if (!$apiOk) {
        // Ripiego: feed RSS dei prodotti. Si prova l'indirizzo incollato, poi /feed/?post_type=product.
        foreach ([trim($siteUrl), $origin . '/feed/?post_type=product'] as $candidate) {
            $body = productHttpGet($candidate);
            if ($body === null || stripos($body, '<item') === false) {
                continue;
            }
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            libxml_use_internal_errors($prev);
            if (!$xml || !isset($xml->channel->item)) {
                continue;
            }
            foreach ($xml->channel->item as $it) {
                $title = productPlainText((string) $it->title);
                $guid = (string) $it->guid;
                $link = trim((string) $it->link);
                if ($title === '' || $link === '') {
                    continue;
                }
                $ref = preg_match('/[?&]p=(\d+)/', html_entity_decode($guid), $m) ? 'w' . $m[1] : 'w' . substr(sha1($link), 0, 16);
                $content = (string) $it->children('http://purl.org/rss/1.0/modules/content/')->encoded;
                $desc = productPlainText($content !== '' ? $content : (string) $it->description);
                $products[$ref] = [
                    'title' => mb_substr($title, 0, 255), 'description' => $desc !== '' ? mb_substr($desc, 0, 5000) : null,
                    'link' => mb_substr($link, 0, 600), 'image_url' => null, 'price_regular' => null, 'price_sale' => null,
                    'currency' => 'EUR', 'category_path' => null, 'in_stock' => true, 'featured' => false,
                ];
            }
            if ($products) {
                break;
            }
        }
    }

    if (!$products) {
        return ['ok' => false, 'error' => 'Nessun prodotto trovato: controlla l\'indirizzo del sito WooCommerce (la Store API e il feed RSS non rispondono).'];
    }
    return productFeedSave($userId, $products);
}

function productDiscountPercent(array $p): ?int {
    if ($p['price_sale'] === null || !$p['price_regular'] || (float) $p['price_sale'] >= (float) $p['price_regular']) {
        return null;
    }
    return (int) round((1 - (float) $p['price_sale'] / (float) $p['price_regular']) * 100);
}

function productSerialize(array $p): array {
    $pct = productDiscountPercent($p);
    return [
        'id' => (int) $p['id'],
        'title' => $p['title'],
        'description' => $p['description'],
        'price_regular' => $p['price_regular'] !== null ? (float) $p['price_regular'] : null,
        'price_sale' => $p['price_sale'] !== null ? (float) $p['price_sale'] : null,
        'currency' => $p['currency'],
        'has_discount' => $pct !== null,
        'discount_percent' => $pct,
        'image_url' => $p['image_url'],
        'link' => $p['link'],
        'category' => $p['category_path'],
        'in_stock' => (bool) (int) $p['in_stock'],
        'featured' => (bool) (int) $p['featured'],
        'hidden' => (bool) (int) $p['is_hidden'],
        'times_published' => isset($p['times_published']) ? (int) $p['times_published'] : null,
        'last_published_at' => $p['last_published_at'] ?? null,
    ];
}

// Elenco filtrato. $f: q, category (prefisso del percorso), has_discount, in_stock, featured,
// only_unpublished, not_published_since_days, post_type, include_hidden, page, per_page.
function productQuery(int $userId, array $f): array {
    $where = ['p.user_id = ?'];
    $args = [$userId];
    if (empty($f['include_hidden'])) {
        $where[] = 'p.is_hidden = 0';
    }
    if (!empty($f['q'])) {
        $where[] = '(p.title LIKE ? OR p.description LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
        array_push($args, $like, $like);
    }
    if (!empty($f['category'])) {
        $where[] = 'p.category_path LIKE ?';
        $args[] = str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['category']) . '%';
    }
    if (isset($f['has_discount'])) {
        $disc = 'p.price_sale IS NOT NULL AND p.price_regular IS NOT NULL AND p.price_sale < p.price_regular';
        $where[] = $f['has_discount'] ? "($disc)" : "NOT ($disc)";
    }
    if (isset($f['in_stock'])) {
        $where[] = 'p.in_stock = ' . ($f['in_stock'] ? 1 : 0);
    }
    if (!empty($f['featured'])) {
        $where[] = 'p.featured = 1';
    }
    $typeSql = '';
    if (!empty($f['post_type'])) {
        $typeSql = ' AND pp.post_type = ' . getDB()->quote((string) $f['post_type']);
    }
    if (!empty($f['only_unpublished'])) {
        $where[] = "NOT EXISTS (SELECT 1 FROM product_publications pp WHERE pp.product_id = p.id$typeSql)";
    } elseif (isset($f['not_published_since_days'])) {
        $where[] = "NOT EXISTS (SELECT 1 FROM product_publications pp WHERE pp.product_id = p.id$typeSql AND pp.published_at > ?)";
        $args[] = date('Y-m-d H:i:s', time() - max(0, (int) $f['not_published_since_days']) * 86400);
    }
    $whereSql = implode(' AND ', $where);
    $db = getDB();
    $cnt = $db->prepare("SELECT COUNT(*) FROM products p WHERE $whereSql");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();

    $perPage = max(1, min(100, (int) ($f['per_page'] ?? 25)));
    $page = max(1, (int) ($f['page'] ?? 1));
    $order = !empty($f['random']) ? 'RAND()' : 'p.featured DESC, p.id DESC';
    $stmt = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM product_publications x WHERE x.product_id = p.id) AS times_published,
            (SELECT MAX(x.published_at) FROM product_publications x WHERE x.product_id = p.id) AS last_published_at
        FROM products p WHERE $whereSql ORDER BY $order LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $stmt->execute($args);
    return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'rows' => $stmt->fetchAll()];
}

// Categorie come albero piatto: ogni percorso (e ogni suo prefisso) con il numero di prodotti.
function productCategories(int $userId): array {
    $stmt = getDB()->prepare('SELECT category_path, COUNT(*) c FROM products WHERE user_id = ? AND is_hidden = 0 AND category_path IS NOT NULL GROUP BY category_path');
    $stmt->execute([$userId]);
    $counts = [];
    foreach ($stmt->fetchAll() as $r) {
        $parts = array_map('trim', explode('>', $r['category_path']));
        for ($i = 1; $i <= count($parts); $i++) {
            $path = implode(' > ', array_slice($parts, 0, $i));
            $counts[$path] = ($counts[$path] ?? 0) + (int) $r['c'];
        }
    }
    ksort($counts);
    $out = [];
    foreach ($counts as $path => $c) {
        $out[] = ['path' => $path, 'name' => trim(substr($path, (int) strrpos($path, '>') + ($path !== '' && str_contains($path, '>') ? 1 : 0))), 'products' => $c];
    }
    return $out;
}
