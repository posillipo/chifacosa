<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$activeTab = 'menu';
$pageTitle = 'Menù';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $stmt = getDB()->prepare('INSERT INTO menu_categories (user_id, name, sort_order) VALUES (?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM menu_categories WHERE user_id=?) t))');
            $stmt->execute([$user['id'], $name, $user['id']]);
        } else {
            $error = 'Inserisci un nome per la categoria.';
        }
    } elseif ($action === 'delete_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM menu_categories WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
    } elseif ($action === 'move_category_up' || $action === 'move_category_down') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id, sort_order FROM menu_categories WHERE user_id=? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$user['id']]);
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
                getDB()->prepare('UPDATE menu_categories SET sort_order=? WHERE id=? AND user_id=?')->execute([$b['sort_order'], $a['id'], $user['id']]);
                getDB()->prepare('UPDATE menu_categories SET sort_order=? WHERE id=? AND user_id=?')->execute([$a['sort_order'], $b['id'], $user['id']]);
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
        $catStmt->execute([$categoryId, $user['id']]);

        if ($name === '' || !$catStmt->fetch()) {
            $error = 'Inserisci un nome per il piatto e scegli una categoria valida.';
        } elseif ($action === 'add_item') {
            $stmt = getDB()->prepare('INSERT INTO menu_items (user_id, category_id, name, description, price, allergens, sort_order) VALUES (?,?,?,?,?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM menu_items WHERE category_id=?) t))');
            $stmt->execute([$user['id'], $categoryId, $name, $description ?: null, $price, $allergensCsv, $categoryId]);
        } else {
            $stmt = getDB()->prepare('UPDATE menu_items SET category_id=?, name=?, description=?, price=?, allergens=? WHERE id=? AND user_id=?');
            $stmt->execute([$categoryId, $name, $description ?: null, $price, $allergensCsv, $itemId, $user['id']]);
        }
    } elseif ($action === 'delete_item') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('DELETE FROM menu_items WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
    } elseif ($action === 'toggle_item') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE menu_items SET is_active = NOT is_active WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
    } elseif ($action === 'move_item_up' || $action === 'move_item_down') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id, category_id, sort_order FROM menu_items WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
        $item = $stmt->fetch();
        if ($item) {
            $stmt = getDB()->prepare('SELECT id, sort_order FROM menu_items WHERE user_id=? AND category_id=? ORDER BY sort_order ASC, id ASC');
            $stmt->execute([$user['id'], $item['category_id']]);
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
                    getDB()->prepare('UPDATE menu_items SET sort_order=? WHERE id=? AND user_id=?')->execute([$b['sort_order'], $a['id'], $user['id']]);
                    getDB()->prepare('UPDATE menu_items SET sort_order=? WHERE id=? AND user_id=?')->execute([$a['sort_order'], $b['id'], $user['id']]);
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
$stmt->execute([$user['id']]);
$categories = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT * FROM menu_items WHERE user_id=? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$user['id']]);
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

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Organizza il tuo menù in categorie (es. Antipasti, Primi, Dolci) e aggiungi i piatti con
      prezzo e, se vuoi, gli allergeni previsti dalla normativa UE. La tua pagina pubblica
      mostrerà il menù completo, diviso per categoria, sotto <code>/<?= e($user['slug']) ?>/menu</code>.
    </p>
  </details>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_category">
    <label>Nuova categoria (es. "Antipasti", "Primi", "Dolci")</label>
    <div style="display:flex;gap:8px;">
      <input type="text" name="name" required style="flex:1;margin-bottom:0;">
      <button type="submit" class="btn" style="width:auto;">Aggiungi categoria</button>
    </div>
  </form>

  <?php if (!$categories): ?>
    <div class="alert error">Nessuna categoria ancora — creane una qui sopra per iniziare ad aggiungere piatti.</div>
  <?php endif; ?>

  <?php if ($editingItem): ?>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_item">
    <input type="hidden" name="id" value="<?= (int) $editingItem['id'] ?>">
    <strong>Modifica piatto</strong>
    <label>Categoria</label>
    <select name="category_id" required>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $editingItem['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Nome piatto</label>
    <input type="text" name="name" value="<?= e($editingItem['name']) ?>" required>
    <label>Descrizione (opzionale, es. ingredienti)</label>
    <input type="text" name="description" value="<?= e($editingItem['description'] ?? '') ?>">
    <label>Prezzo (€, opzionale)</label>
    <input type="text" name="price" value="<?= $editingItem['price'] !== null ? e(number_format((float) $editingItem['price'], 2, ',', '')) : '' ?>" placeholder="es. 8,00">
    <label>Allergeni (opzionale)</label>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;margin-bottom:14px;">
      <?php $editingAllergens = parseMenuAllergens($editingItem['allergens'] ?? null); ?>
      <?php foreach (MENU_ALLERGENS as $aId => $aLabel): ?>
        <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
          <input type="checkbox" name="allergens[]" value="<?= $aId ?>" style="width:auto;" <?= in_array($aId, $editingAllergens, true) ? 'checked' : '' ?>>
          <?= $aId ?>. <?= e($aLabel) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;">
      <button type="submit" class="btn">Salva modifiche</button>
      <a href="/dashboard_menu.php" class="btn secondary">Annulla</a>
    </div>
  </form>
  <?php elseif ($categories): ?>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_item">
    <strong>Aggiungi piatto</strong>
    <label>Categoria</label>
    <select name="category_id" required>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Nome piatto</label>
    <input type="text" name="name" required>
    <label>Descrizione (opzionale, es. ingredienti)</label>
    <input type="text" name="description" placeholder="es. pomodorini, olio EVO e basilico">
    <label>Prezzo (€, opzionale)</label>
    <input type="text" name="price" placeholder="es. 8,00">
    <label>Allergeni (opzionale)</label>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;margin-bottom:14px;">
      <?php foreach (MENU_ALLERGENS as $aId => $aLabel): ?>
        <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
          <input type="checkbox" name="allergens[]" value="<?= $aId ?>" style="width:auto;">
          <?= $aId ?>. <?= e($aLabel) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn">Aggiungi piatto</button>
  </form>
  <?php endif; ?>

  <?php foreach ($categories as $ci => $cat): ?>
    <div class="section-title" style="display:flex;justify-content:space-between;align-items:center;">
      <span><?= e($cat['name']) ?></span>
      <div class="icon-btn-group">
        <form method="post" title="Sposta su">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="move_category_up">
          <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
          <button class="icon-btn" type="submit" <?= $ci === 0 ? 'disabled' : '' ?>><i class="fa-solid fa-chevron-up"></i></button>
        </form>
        <form method="post" title="Sposta giù">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="move_category_down">
          <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
          <button class="icon-btn" type="submit" <?= $ci === count($categories) - 1 ? 'disabled' : '' ?>><i class="fa-solid fa-chevron-down"></i></button>
        </form>
        <form method="post" onsubmit="return confirm('Eliminare la categoria &quot;<?= e($cat['name']) ?>&quot; e tutti i piatti al suo interno?');" title="Elimina categoria">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_category">
          <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
          <button class="icon-btn danger" type="submit"><i class="fa-solid fa-trash"></i></button>
        </form>
      </div>
    </div>

    <?php $items = $itemsByCategory[(int) $cat['id']] ?? []; ?>
    <?php if (!$items): ?>
      <div class="card" style="color:var(--text-muted);">Nessun piatto in questa categoria ancora.</div>
    <?php endif; ?>
    <?php foreach ($items as $i => $it): ?>
      <div class="link-item">
        <div>
          <strong><?= e($it['name']) ?></strong>
          <?php if ($it['price'] !== null): ?><span style="color:var(--accent);font-weight:700;"> · € <?= e(number_format((float) $it['price'], 2, ',', '.')) ?></span><?php endif; ?>
          <?php if (!$it['is_active']): ?><span style="color:#ff8a8a;font-size:12px;"> · nascosto</span><?php endif; ?>
          <?php if ($it['description']): ?><br><small style="color:var(--text-muted)"><?= e($it['description']) ?></small><?php endif; ?>
          <?php $itAllergens = parseMenuAllergens($it['allergens'] ?? null); ?>
          <?php if ($itAllergens): ?>
            <br><small style="color:var(--text-muted)">Allergeni: <?= e(implode(', ', $itAllergens)) ?></small>
          <?php endif; ?>
        </div>
        <div class="icon-btn-group">
          <form method="post" title="Sposta su">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="move_item_up">
            <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
            <button class="icon-btn" type="submit" <?= $i === 0 ? 'disabled' : '' ?>><i class="fa-solid fa-chevron-up"></i></button>
          </form>
          <form method="post" title="Sposta giù">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="move_item_down">
            <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
            <button class="icon-btn" type="submit" <?= $i === count($items) - 1 ? 'disabled' : '' ?>><i class="fa-solid fa-chevron-down"></i></button>
          </form>
          <a class="icon-btn" href="/dashboard_menu.php?edit_item=<?= (int) $it['id'] ?>" title="Modifica"><i class="fa-solid fa-pen"></i></a>
          <form method="post" title="<?= $it['is_active'] ? 'Nascondi' : 'Mostra' ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_item">
            <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
            <button class="icon-btn" type="submit"><i class="fa-solid <?= $it['is_active'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i></button>
          </form>
          <form method="post" onsubmit="return confirm('Eliminare questo piatto?');" title="Elimina">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_item">
            <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
            <button class="icon-btn danger" type="submit"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
