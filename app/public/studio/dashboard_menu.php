<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: azioni identiche alla pagina originale + 'reorder_items' e 'reorder_categories' (trascinamento) */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'menu';
$pageTitle = 'Menù';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_preconto') {
        $enabled = !empty($_POST['menu_preconto_enabled']) ? 1 : 0;
        $stmt = getDB()->prepare('UPDATE profiles SET menu_preconto_enabled=? WHERE user_id=?');
        $stmt->execute([$enabled, $profile['id']]);
        $user = currentUser();
        $profile = getActingProfile($user);
    } elseif ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $stmt = getDB()->prepare('INSERT INTO menu_categories (user_id, name, sort_order) VALUES (?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM menu_categories WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $name, $profile['id']]);
        } else {
            $error = 'Inserisci un nome per la categoria.';
        }
    } elseif ($action === 'update_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $stmt = getDB()->prepare('UPDATE menu_categories SET name=? WHERE id=? AND user_id=?');
            $stmt->execute([$name, $id, $profile['id']]);
        } else {
            $error = 'Inserisci un nome per la categoria.';
        }
    } elseif ($action === 'delete_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM menu_categories WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'reorder_categories') {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))));
        $upd = getDB()->prepare('UPDATE menu_categories SET sort_order=? WHERE id=? AND user_id=?');
        foreach ($ids as $pos => $rid) { $upd->execute([$pos + 1, $rid, $profile['id']]); }
        http_response_code(204);
        exit;
    } elseif ($action === 'reorder_items') {
        $catId = (int) ($_POST['category_id'] ?? 0);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))));
        $upd = getDB()->prepare('UPDATE menu_items SET sort_order=? WHERE id=? AND user_id=? AND category_id=?');
        foreach ($ids as $pos => $rid) { $upd->execute([$pos + 1, $rid, $profile['id'], $catId]); }
        http_response_code(204);
        exit;
    } elseif ($action === 'move_category_up' || $action === 'move_category_down') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id, sort_order FROM menu_categories WHERE user_id=? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$profile['id']]);
        $all = $stmt->fetchAll();
        $idx = null;
        foreach ($all as $i => $row) {
            if ((int)$row['id'] === $id) { $idx = $i; break; }
        }
        if ($idx !== null) {
            $swapIdx = $action === 'move_category_up' ? $idx - 1 : $idx + 1;
            if (isset($all[$swapIdx])) {
                $a = $all[$idx];
                $b = $all[$swapIdx];
                getDB()->prepare('UPDATE menu_categories SET sort_order=? WHERE id=? AND user_id=?')->execute([$b['sort_order'], $a['id'], $profile['id']]);
                getDB()->prepare('UPDATE menu_categories SET sort_order=? WHERE id=? AND user_id=?')->execute([$a['sort_order'], $b['id'], $profile['id']]);
            }
        }
    } elseif ($action === 'add_item' || $action === 'update_item') {
        $itemId = (int) ($_POST['id'] ?? 0);
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priceRaw = trim(str_replace(',', '.', $_POST['price'] ?? ''));
        $price = $priceRaw !== '' && is_numeric($priceRaw) ? (float) $priceRaw : null;
        $allergens = array_filter(array_map('intval', $_POST['allergens'] ?? []));
        $allergens = array_values(array_intersect($allergens, array_keys(MENU_ALLERGENS)));
        $allergensCsv = $allergens ? implode(',', $allergens) : null;

        // Verifica che la categoria appartenga davvero a questo utente, per evitare che un
        // ID arbitrario nel form associ un piatto alla categoria di qualcun altro.
        $catStmt = getDB()->prepare('SELECT id FROM menu_categories WHERE id=? AND user_id=?');
        $catStmt->execute([$categoryId, $profile['id']]);

        if ($name === '' || !$catStmt->fetch()) {
            $error = 'Inserisci un nome per il piatto e scegli una categoria valida.';
        } elseif ($action === 'add_item') {
            $stmt = getDB()->prepare('INSERT INTO menu_items (user_id, category_id, name, description, price, allergens, sort_order) VALUES (?,?,?,?,?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM menu_items WHERE category_id=?) t))');
            $stmt->execute([$profile['id'], $categoryId, $name, $description ?: null, $price, $allergensCsv, $categoryId]);
        } else {
            $stmt = getDB()->prepare('UPDATE menu_items SET category_id=?, name=?, description=?, price=?, allergens=? WHERE id=? AND user_id=?');
            $stmt->execute([$categoryId, $name, $description ?: null, $price, $allergensCsv, $itemId, $profile['id']]);
        }
    } elseif ($action === 'delete_item') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM menu_items WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'toggle_item') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE menu_items SET is_active = NOT is_active WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'move_item_up' || $action === 'move_item_down') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id, category_id, sort_order FROM menu_items WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        $item = $stmt->fetch();
        if ($item) {
            $stmt = getDB()->prepare('SELECT id, sort_order FROM menu_items WHERE user_id=? AND category_id=? ORDER BY sort_order ASC, id ASC');
            $stmt->execute([$profile['id'], $item['category_id']]);
            $all = $stmt->fetchAll();
            $idx = null;
            foreach ($all as $i => $row) {
                if ((int)$row['id'] === $id) { $idx = $i; break; }
            }
            if ($idx !== null) {
                $swapIdx = $action === 'move_item_up' ? $idx - 1 : $idx + 1;
                if (isset($all[$swapIdx])) {
                    $a = $all[$idx];
                    $b = $all[$swapIdx];
                    getDB()->prepare('UPDATE menu_items SET sort_order=? WHERE id=? AND user_id=?')->execute([$b['sort_order'], $a['id'], $profile['id']]);
                    getDB()->prepare('UPDATE menu_items SET sort_order=? WHERE id=? AND user_id=?')->execute([$a['sort_order'], $b['id'], $profile['id']]);
                }
            }
        }
    }

    if (!$error) {
        header('Location: /dashboard_menu.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM menu_categories WHERE user_id=? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$profile['id']]);
$categories = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT * FROM menu_items WHERE user_id=? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$profile['id']]);
$allItems = $stmt->fetchAll();
$itemsByCategory = [];
foreach ($allItems as $it) {
    $itemsByCategory[(int) $it['category_id']][] = $it;
}

// Modalità modifica: se è presente ?edit_item=ID, precarichiamo quel piatto nel form
$editingItem = null;
$editItemId = (int) ($_GET['edit_item'] ?? 0);
if ($editItemId > 0) {
    foreach ($allItems as $it) {
        if ((int) $it['id'] === $editItemId) { $editingItem = $it; break; }
    }
}

// Modalità modifica: se è presente ?edit_category=ID, precarichiamo quella categoria nel form
$editingCategory = null;
$editCategoryId = (int) ($_GET['edit_category'] ?? 0);
if ($editCategoryId > 0) {
    foreach ($categories as $c) {
        if ((int) $c['id'] === $editCategoryId) { $editingCategory = $c; break; }
    }
}

include __DIR__ . '/../_dash_header.php';
$nItems = count($allItems); $nHidden = count(array_filter($allItems, fn ($i) => !$i['is_active']));
$preconto = !empty($profile['menu_preconto_enabled']);
$KIT = "  <script src=\"https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js\" defer></script>\n";
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($categories) ?></b><span>Categorie</span></div>
      <div class="tlx-kpi"><b><?= $nItems ?></b><span>Piatti</span></div>
      <div class="tlx-kpi"><b><?= $nHidden ?></b><span>Nascosti</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>/menu" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>Vedi il menù pubblico</span></a>
    </div>

    <form method="post" class="mnx-pre" data-autosubmit>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_preconto">
      <div><b><i class="fa-solid fa-receipt"></i> Preconto sul menù pubblico</b>
        <p>Il cliente sceglie i piatti e vede il totale in tempo reale, ma solo dopo aver lasciato nome, cognome, email, telefono e CAP e aver confermato l'email: diventa anche tuo follower.</p></div>
      <label class="tlx-switch"><input type="checkbox" name="menu_preconto_enabled" value="1" <?= $preconto ? 'checked' : '' ?>><i></i><span><?= $preconto ? 'Attivo' : 'Spento' ?></span></label>
    </form>

    <form method="post" class="blx-add">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_category">
      <input type="text" name="name" required maxlength="80" placeholder="Nuova categoria (es. Antipasti, Primi, Dolci)" aria-label="Nome della nuova categoria">
      <button type="submit" class="blx-new"><i class="fa-solid fa-plus"></i> <span>Categoria</span></button>
    </form>

    <?php if (!$categories): ?>
      <div class="tlx-empty"><i class="fa-solid fa-utensils"></i><b>Il menù è vuoto</b><span>Crea una categoria qui sopra, poi aggiungi i piatti con il pulsante «+ Piatto».</span></div>
    <?php endif; ?>

    <div class="mnx-cats" data-sortable="reorder_categories" data-handle=".mnx-cgrip">
    <?php foreach ($categories as $cat):
      $items = $itemsByCategory[(int) $cat['id']] ?? [];
      $catFill = json_encode(['id' => (int) $cat['id'], 'name' => $cat['name']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
      <section class="mnx-cat" data-id="<?= (int) $cat['id'] ?>">
        <header class="mnx-ch">
          <span class="kit-grip mnx-cgrip" aria-label="Trascina la categoria"><i class="fa-solid fa-grip-vertical"></i></span>
          <h2><?= e($cat['name']) ?> <small><?= count($items) ?></small></h2>
          <div class="mnx-cact">
            <button type="button" class="stc-act primary" data-dlg="mn-item-dlg" data-preset='<?= e(json_encode(['category_id' => (int) $cat['id']])) ?>'><i class="fa-solid fa-plus"></i> <span>Piatto</span></button>
            <button type="button" class="lkx-btn" data-dlg="mn-cat-dlg" data-fill='<?= e($catFill) ?>' title="Rinomina la categoria" aria-label="Rinomina la categoria"><i class="fa-solid fa-pen"></i></button>
            <form method="post" class="ibx-inline" data-confirm="Eliminare la categoria «<?= e($cat['name']) ?>» e tutti i piatti al suo interno?"><?= csrfField() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= (int) $cat['id'] ?>"><button type="submit" class="lkx-btn danger" title="Elimina la categoria" aria-label="Elimina la categoria"><i class="fa-solid fa-trash"></i></button></form>
          </div>
        </header>
        <?php if (!$items): ?><p class="mnx-empty">Nessun piatto in questa categoria. Tocca «+ Piatto» per aggiungerne uno.</p><?php endif; ?>
        <div class="mnx-items" data-sortable="reorder_items" data-extra='<?= e(json_encode(['category_id' => (int) $cat['id']])) ?>' data-handle=".mnx-igrip">
        <?php foreach ($items as $it):
          $als = parseMenuAllergens($it['allergens'] ?? null);
          $itFill = json_encode(['id' => (int) $it['id'], 'category_id' => (int) $it['category_id'], 'name' => $it['name'], 'description' => $it['description'] ?? '',
            'price' => $it['price'] !== null ? number_format((float) $it['price'], 2, ',', '') : '', 'allergens' => array_map('strval', $als)], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        ?>
          <article class="mnx-item<?= $it['is_active'] ? '' : ' off' ?>" data-id="<?= (int) $it['id'] ?>" data-row data-on="<?= (int) $it['is_active'] ?>">
            <span class="kit-grip mnx-igrip" aria-label="Trascina il piatto"><i class="fa-solid fa-grip-vertical"></i></span>
            <div class="mnx-ib">
              <div class="mnx-name"><b><?= e($it['name']) ?></b><?php if ($it['price'] !== null): ?><span class="mnx-price">€ <?= e(number_format((float) $it['price'], 2, ',', '.')) ?></span><?php endif; ?></div>
              <?php if ($it['description']): ?><small><?= e($it['description']) ?></small><?php endif; ?>
              <?php if ($als): ?><div class="mnx-al"><?php foreach ($als as $a): ?><span title="<?= e(MENU_ALLERGENS[$a] ?? '') ?>"><?= (int) $a ?></span><?php endforeach; ?></div><?php endif; ?>
            </div>
            <div class="lkx-act">
              <label class="tlx-switch lkx-sw" title="Visibile nel menù"><input type="checkbox" <?= $it['is_active'] ? 'checked' : '' ?> data-post='<?= e(json_encode(['action' => 'toggle_item', 'id' => (int) $it['id']])) ?>' data-msg-on="Piatto visibile" data-msg-off="Piatto nascosto"><i></i></label>
              <button type="button" class="lkx-btn" data-dlg="mn-item-dlg" data-fill='<?= e($itFill) ?>' title="Modifica" aria-label="Modifica"><i class="fa-solid fa-pen"></i></button>
              <form method="post" class="ibx-inline" data-confirm="Eliminare questo piatto?"><?= csrfField() ?><input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><button type="submit" class="lkx-btn danger" title="Elimina" aria-label="Elimina"><i class="fa-solid fa-trash"></i></button></form>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
    </div>
  </div>

  <?php studioDlgOpen('mn-item-dlg', ['multipart' => false, 'new_title' => 'Nuovo piatto', 'edit_title' => 'Modifica piatto', 'action_new' => 'add_item', 'action_edit' => 'update_item']); ?>
    <label for="mn-cat">Categoria</label>
    <select name="category_id" id="mn-cat" required>
      <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <label for="mn-name">Nome del piatto</label>
    <input type="text" name="name" id="mn-name" required maxlength="150">
    <label for="mn-desc">Descrizione <small>(opzionale, es. ingredienti)</small></label>
    <input type="text" name="description" id="mn-desc" placeholder="es. pomodorini, olio EVO e basilico">
    <label for="mn-price">Prezzo in € <small>(opzionale)</small></label>
    <input type="text" name="price" id="mn-price" inputmode="decimal" placeholder="es. 8,00">
    <label>Allergeni <small>(opzionale)</small></label>
    <div class="kit-pills">
      <?php foreach (MENU_ALLERGENS as $aId => $aLabel): ?>
        <label title="<?= e($aLabel) ?>"><input type="checkbox" name="allergens[]" value="<?= (int) $aId ?>"><span><?= (int) $aId ?> · <?= e($aLabel) ?></span></label>
      <?php endforeach; ?>
    </div>
  <?php studioDlgClose('Salva piatto'); ?>

  <?php studioDlgOpen('mn-cat-dlg', ['multipart' => false, 'new_title' => 'Rinomina categoria', 'edit_title' => 'Rinomina categoria', 'action_new' => 'update_category', 'action_edit' => 'update_category']); ?>
    <label for="mn-cname">Nome della categoria</label>
    <input type="text" name="name" id="mn-cname" required maxlength="80">
  <?php studioDlgClose('Salva'); ?>

  <?= $KIT ?>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
