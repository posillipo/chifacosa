<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/geocoding.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'links';
$pageTitle = 'Link';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $isWebsite = isset($_POST['is_website_icon']) ? 1 : 0;
        if ($label !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $coverPath = handleCoverUpload($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO links (user_id, label, url, is_website_icon, cover_path, sort_order) VALUES (?,?,?,?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM links WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $label, $url, $isWebsite, $coverPath, $profile['id']]);
        } else {
            $error = 'Inserisci un\'etichetta e un URL valido.';
        }
    } elseif ($action === 'add_divider') {
        $label = trim($_POST['label'] ?? '');
        if ($label !== '') {
            $stmt = getDB()->prepare("INSERT INTO links (user_id, label, url, link_type, sort_order) VALUES (?,?,'','divider', (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM links WHERE user_id=?) t))");
            $stmt->execute([$profile['id'], $label, $profile['id']]);
        } else {
            $error = 'Inserisci un titolo per il separatore.';
        }
    } elseif ($action === 'add_map') {
        $address = trim($_POST['address'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $geo = geocodeAddress($address);
        if (!$geo) {
            header('Location: /dashboard_links.php?geocode_error=1');
            exit;
        }
        $mapLabel = $label !== '' ? $label : $geo['display_name'];
        $stmt = getDB()->prepare("INSERT INTO links (user_id, label, url, link_type, map_lat, map_lng, sort_order) VALUES (?,?,'','map',?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM links WHERE user_id=?) t))");
        $stmt->execute([$profile['id'], $mapLabel, $geo['lat'], $geo['lng'], $profile['id']]);
    } elseif ($action === 'update_link') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $isWebsite = isset($_POST['is_website_icon']) ? 1 : 0;
        if ($label !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $newCover = handleCoverUpload($profile['slug']);
            if ($newCover) {
                $stmt = getDB()->prepare('UPDATE links SET label=?, url=?, is_website_icon=?, cover_path=? WHERE id=? AND user_id=?');
                $stmt->execute([$label, $url, $isWebsite, $newCover, $id, $profile['id']]);
            } else {
                $stmt = getDB()->prepare('UPDATE links SET label=?, url=?, is_website_icon=? WHERE id=? AND user_id=?');
                $stmt->execute([$label, $url, $isWebsite, $id, $profile['id']]);
            }
        } else {
            header('Location: /dashboard_links.php?edit=' . $id . '&error=1');
            exit;
        }
    } elseif ($action === 'update_divider') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if ($label !== '') {
            $stmt = getDB()->prepare("UPDATE links SET label=? WHERE id=? AND user_id=? AND link_type='divider'");
            $stmt->execute([$label, $id, $profile['id']]);
        } else {
            header('Location: /dashboard_links.php?edit=' . $id . '&error=1');
            exit;
        }
    } elseif ($action === 'update_map') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $address = trim($_POST['address'] ?? '');
        if ($address !== '') {
            $geo = geocodeAddress($address);
            if (!$geo) {
                header('Location: /dashboard_links.php?edit=' . $id . '&geocode_error=1');
                exit;
            }
            $mapLabel = $label !== '' ? $label : $geo['display_name'];
            $stmt = getDB()->prepare("UPDATE links SET label=?, map_lat=?, map_lng=? WHERE id=? AND user_id=? AND link_type='map'");
            $stmt->execute([$mapLabel, $geo['lat'], $geo['lng'], $id, $profile['id']]);
        } elseif ($label !== '') {
            $stmt = getDB()->prepare("UPDATE links SET label=? WHERE id=? AND user_id=? AND link_type='map'");
            $stmt->execute([$label, $id, $profile['id']]);
        }
    } elseif ($action === 'toggle_website') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE links SET is_website_icon = NOT is_website_icon WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM links WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM links WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE links SET is_active = NOT is_active WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'move_up' || $action === 'move_down') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id, sort_order FROM links WHERE user_id=? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$profile['id']]);
        $all = $stmt->fetchAll();
        $idx = null;
        foreach ($all as $i => $row) {
            if ((int)$row['id'] === $id) { $idx = $i; break; }
        }
        if ($idx !== null) {
            $swapIdx = $action === 'move_up' ? $idx - 1 : $idx + 1;
            if (isset($all[$swapIdx])) {
                $a = $all[$idx];
                $b = $all[$swapIdx];
                getDB()->prepare('UPDATE links SET sort_order=? WHERE id=? AND user_id=?')->execute([$b['sort_order'], $a['id'], $profile['id']]);
                getDB()->prepare('UPDATE links SET sort_order=? WHERE id=? AND user_id=?')->execute([$a['sort_order'], $b['id'], $profile['id']]);
            }
        }
    }
    header('Location: /dashboard_links.php');
    exit;
}

$stmt = getDB()->prepare('SELECT * FROM links WHERE user_id=? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$profile['id']]);
$links = $stmt->fetchAll();

// Modalità modifica: se è presente ?edit=ID, precarichiamo quel link nel form al posto di "Aggiungi"
$editingLink = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($links as $l) {
        if ((int)$l['id'] === $editId) { $editingLink = $l; break; }
    }
}

// Stessa suddivisione usata per costruire davvero la pagina pubblica (vedi splitSocialAndActionLinks
// in functions.php): così l'elenco qui in dashboard mostra esattamente quali link diventeranno
// icone social in cima alla pagina e quali restano pulsanti normali, invece di un elenco unico.
list($socialLinks, $actionLinks) = splitSocialAndActionLinks($links);
$linkIndex = [];
foreach ($links as $i => $l) {
    $linkIndex[$l['id']] = $i;
}

function renderLinkItem(array $l, int $idx, int $total): void {
    $platform = $l['platform'] ?? null;
    $type = $l['link_type'] ?? 'link';
?>
  <div class="link-item">
    <div style="display:flex;align-items:center;gap:10px;">
      <?php if ($type === 'divider'): ?>
        <span style="width:40px;height:40px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:rgba(var(--text-rgb),0.12);color:var(--text-muted);font-size:16px;"><i class="fa-solid fa-grip-lines"></i></span>
      <?php elseif ($type === 'map'): ?>
        <span style="width:40px;height:40px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:rgba(var(--text-rgb),0.12);color:var(--text-muted);font-size:16px;"><i class="fa-solid fa-location-dot"></i></span>
      <?php elseif ($platform): ?>
        <span style="width:40px;height:40px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:var(--accent);color:var(--accent-text,#fff);font-size:16px;"><i class="<?= e($platform['icon_class']) ?>"></i></span>
      <?php elseif ($l['cover_path']): ?>
        <img src="/<?= e($l['cover_path']) ?>" style="width:40px;height:40px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div>
        <strong><?= e($l['label']) ?></strong>
        <?php if ($type === 'divider'): ?><span style="color:var(--text-muted);font-size:12px;"> · separatore</span>
        <?php elseif ($type === 'map'): ?><span style="color:var(--text-muted);font-size:12px;"> · mappa</span>
        <?php elseif ($platform): ?><span style="color:var(--accent);font-size:12px;"> · icona <?= e($platform['label']) ?></span>
        <?php elseif ($type === 'film'): ?><span style="color:var(--text-muted);font-size:12px;"> · film (sincronizzato da Cinema)</span><?php endif; ?>
        <?php if (!$l['is_active']): ?><span style="color:#ff8a8a;font-size:12px;"> · nascosto</span><?php endif; ?>
        <br>
        <?php if ($type === 'divider'): ?>
          <small style="color:var(--text-muted)">titolo di sezione, non cliccabile</small>
        <?php elseif ($type === 'map'): ?>
          <small style="color:var(--text-muted)"><?= number_format((float)$l['map_lat'], 5) ?>, <?= number_format((float)$l['map_lng'], 5) ?></small>
        <?php else: ?>
          <small style="color:var(--text-muted)"><?= e($l['url']) ?> · <?= (int)$l['click_count'] ?> click</small>
        <?php endif; ?>
      </div>
    </div>
    <div class="icon-btn-group">
      <form method="post" title="Sposta su">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="move_up">
        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <button class="icon-btn" type="submit" <?= $idx === 0 ? 'disabled' : '' ?>><i class="fa-solid fa-chevron-up"></i></button>
      </form>
      <form method="post" title="Sposta giù">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="move_down">
        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <button class="icon-btn" type="submit" <?= $idx === $total - 1 ? 'disabled' : '' ?>><i class="fa-solid fa-chevron-down"></i></button>
      </form>
      <a class="icon-btn" href="/dashboard_links.php?edit=<?= (int)$l['id'] ?>" title="Modifica"><i class="fa-solid fa-pen"></i></a>
      <form method="post" title="<?= $l['is_active'] ? 'Nascondi' : 'Mostra' ?>">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <button class="icon-btn" type="submit"><i class="fa-solid <?= $l['is_active'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i></button>
      </form>
      <form method="post" onsubmit="return confirm('Eliminare questo link?');" title="Elimina">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <button class="icon-btn danger" type="submit"><i class="fa-solid fa-trash"></i></button>
      </form>
    </div>
  </div>
<?php
}

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Aggiungi qui i pulsanti del tuo Link in Bio: social, sito web, streaming, qualsiasi link tu
      voglia mostrare. Le icone dei servizi più comuni (Spotify, Instagram, YouTube, ecc.)
      vengono riconosciute automaticamente e mostrate in cima alla pagina pubblica come icone
      social invece che come pulsanti — usa le frecce per decidere l'ordine dei link rimanenti.
    </p>
  </details>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['error'])): ?><div class="alert error">Inserisci un'etichetta e un URL valido.</div><?php endif; ?>
  <?php if (isset($_GET['geocode_error'])): ?><div class="alert error">Indirizzo non trovato su OpenStreetMap. Prova a scriverlo in modo più preciso (via, numero civico, città).</div><?php endif; ?>

  <?php if ($editingLink && ($editingLink['link_type'] ?? 'link') === 'divider'): ?>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_divider">
    <input type="hidden" name="id" value="<?= (int)$editingLink['id'] ?>">
    <strong>Modifica separatore</strong>
    <label>Titolo della sezione</label>
    <input type="text" name="label" value="<?= e($editingLink['label']) ?>" required>
    <div style="display:flex;gap:8px;margin-top:10px;">
      <button type="submit" class="btn">Salva modifiche</button>
      <a href="/dashboard_links.php" class="btn secondary">Annulla</a>
    </div>
  </form>
  <?php elseif ($editingLink && ($editingLink['link_type'] ?? 'link') === 'map'): ?>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_map">
    <input type="hidden" name="id" value="<?= (int)$editingLink['id'] ?>">
    <strong>Modifica mappa</strong>
    <label>Etichetta</label>
    <input type="text" name="label" value="<?= e($editingLink['label']) ?>">
    <label>Nuovo indirizzo (lascia vuoto per non spostare la mappa)</label>
    <input type="text" name="address" placeholder="Via Roma 1, Napoli">
    <p style="color:var(--text-muted);font-size:12px;">
      Posizione attuale: <?= number_format((float)$editingLink['map_lat'], 5) ?>, <?= number_format((float)$editingLink['map_lng'], 5) ?>
    </p>
    <div style="display:flex;gap:8px;margin-top:10px;">
      <button type="submit" class="btn">Salva modifiche</button>
      <a href="/dashboard_links.php" class="btn secondary">Annulla</a>
    </div>
  </form>
  <?php elseif ($editingLink): ?>
  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_link">
    <input type="hidden" name="id" value="<?= (int)$editingLink['id'] ?>">
    <strong>Modifica link</strong>
    <label>Etichetta</label>
    <input type="text" name="label" value="<?= e($editingLink['label']) ?>" required>
    <label>URL</label>
    <input type="url" name="url" value="<?= e($editingLink['url']) ?>" required>
    <label>Copertina quadrata (opzionale, jpg/png/webp)</label>
    <?php if ($editingLink['cover_path']): ?>
      <img src="/<?= e($editingLink['cover_path']) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;margin-bottom:8px;">
    <?php endif; ?>
    <input type="file" name="cover" accept="image/*">
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;margin-top:10px;">
      <input type="checkbox" name="is_website_icon" value="1" style="width:auto;" <?= !empty($editingLink['is_website_icon']) ? 'checked' : '' ?>>
      È il tuo sito web personale? (comparirà come icona "sito web" invece di pulsante)
    </label>
    <div style="display:flex;gap:8px;">
      <button type="submit" class="btn">Salva modifiche</button>
      <a href="/dashboard_links.php" class="btn secondary">Annulla</a>
    </div>
  </form>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <label>Etichetta (es. "Ascolta su Spotify", "Instagram")</label>
    <input type="text" name="label" required>
    <label>URL</label>
    <input type="url" name="url" placeholder="https://..." required>
    <label>Copertina quadrata (opzionale, jpg/png/webp)</label>
    <input type="file" name="cover" accept="image/*">
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;margin-top:10px;">
      <input type="checkbox" name="is_website_icon" value="1" style="width:auto;">
      È il tuo sito web personale? (comparirà come icona "sito web" invece di pulsante)
    </label>
    <button type="submit" class="btn">Aggiungi link</button>
  </form>

  <details class="help-box" style="margin-top:14px;">
    <summary>➕ Aggiungi un separatore</summary>
    <p style="color:var(--text-muted);font-size:13px;">
      Solo un titolo per dividere i link in sezioni (es. "Per prenotare", "Dove siamo") — non è
      cliccabile, serve solo a organizzare la lista sulla pagina pubblica.
    </p>
    <form method="post" style="margin-top:10px;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_divider">
      <label>Titolo della sezione</label>
      <input type="text" name="label" required>
      <button type="submit" class="btn secondary">Aggiungi separatore</button>
    </form>
  </details>

  <details class="help-box" style="margin-top:10px;">
    <summary>📍 Aggiungi una mappa (gratuita)</summary>
    <p style="color:var(--text-muted);font-size:13px;">
      Cerca un indirizzo e mostra una mappa interattiva sulla tua pagina pubblica — tramite
      OpenStreetMap, un servizio completamente gratuito, senza chiave API e senza costi anche con
      molte visite (a differenza di Google Maps).
    </p>
    <form method="post" style="margin-top:10px;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_map">
      <label>Etichetta (opzionale)</label>
      <input type="text" name="label" placeholder="La nostra sede">
      <label>Indirizzo</label>
      <input type="text" name="address" placeholder="Via Roma 1, Napoli" required>
      <button type="submit" class="btn secondary">Trova e aggiungi mappa</button>
    </form>
  </details>
  <?php endif; ?>

  <div class="section-title">I tuoi link (<?= count($links) ?>)</div>
  <p style="color:var(--text-muted);font-size:13px;">
    Le icone (Spotify, Apple Music, Instagram, Facebook, TikTok, YouTube, LinkedIn, SoundCloud,
    WhatsApp, sito web) vengono riconosciute automaticamente e mostrate in cima alla pagina
    pubblica — solo la <strong>prima</strong> di ciascun tipo, seguendo l'ordine in cui i link
    compaiono qui sotto; eventuali duplicati restano tra i pulsanti. Usa le frecce per decidere
    l'ordine.
  </p>

  <?php if ($socialLinks): ?>
    <div class="section-title" style="margin-top:22px;">Icone social (<?= count($socialLinks) ?>)</div>
    <?php foreach ($socialLinks as $l): renderLinkItem($l, $linkIndex[$l['id']], count($links)); endforeach; ?>
  <?php endif; ?>

  <?php if ($actionLinks): ?>
    <div class="section-title" style="margin-top:22px;">Link e sezioni (<?= count($actionLinks) ?>)</div>
    <?php foreach ($actionLinks as $l): renderLinkItem($l, $linkIndex[$l['id']], count($links)); endforeach; ?>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
