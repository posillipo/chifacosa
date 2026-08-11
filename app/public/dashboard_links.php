<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
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
            $coverPath = handleCoverUpload($user['slug']);
            $stmt = getDB()->prepare('INSERT INTO links (user_id, label, url, is_website_icon, cover_path, sort_order) VALUES (?,?,?,?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM links WHERE user_id=?) t))');
            $stmt->execute([$user['id'], $label, $url, $isWebsite, $coverPath, $user['id']]);
        } else {
            $error = 'Inserisci un\'etichetta e un URL valido.';
        }
    } elseif ($action === 'update_link') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $isWebsite = isset($_POST['is_website_icon']) ? 1 : 0;
        if ($label !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $newCover = handleCoverUpload($user['slug']);
            if ($newCover) {
                $stmt = getDB()->prepare('UPDATE links SET label=?, url=?, is_website_icon=?, cover_path=? WHERE id=? AND user_id=?');
                $stmt->execute([$label, $url, $isWebsite, $newCover, $id, $user['id']]);
            } else {
                $stmt = getDB()->prepare('UPDATE links SET label=?, url=?, is_website_icon=? WHERE id=? AND user_id=?');
                $stmt->execute([$label, $url, $isWebsite, $id, $user['id']]);
            }
        } else {
            header('Location: /dashboard_links.php?edit=' . $id . '&error=1');
            exit;
        }
    } elseif ($action === 'toggle_website') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE links SET is_website_icon = NOT is_website_icon WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM links WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM links WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE links SET is_active = NOT is_active WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
    } elseif ($action === 'move_up' || $action === 'move_down') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id, sort_order FROM links WHERE user_id=? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$user['id']]);
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
                getDB()->prepare('UPDATE links SET sort_order=? WHERE id=? AND user_id=?')->execute([$b['sort_order'], $a['id'], $user['id']]);
                getDB()->prepare('UPDATE links SET sort_order=? WHERE id=? AND user_id=?')->execute([$a['sort_order'], $b['id'], $user['id']]);
            }
        }
    }
    header('Location: /dashboard_links.php');
    exit;
}

$stmt = getDB()->prepare('SELECT * FROM links WHERE user_id=? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$user['id']]);
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
?>
  <div class="link-item">
    <div style="display:flex;align-items:center;gap:10px;">
      <?php if ($platform): ?>
        <span style="width:40px;height:40px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:var(--accent);color:var(--accent-text,#fff);font-size:16px;"><i class="<?= e($platform['icon_class']) ?>"></i></span>
      <?php elseif ($l['cover_path']): ?>
        <img src="/<?= e($l['cover_path']) ?>" style="width:40px;height:40px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div>
        <strong><?= e($l['label']) ?></strong>
        <?php if ($platform): ?><span style="color:var(--accent);font-size:12px;"> · icona <?= e($platform['label']) ?></span><?php endif; ?>
        <?php if (!$l['is_active']): ?><span style="color:#ff8a8a;font-size:12px;"> · nascosto</span><?php endif; ?>
        <br>
        <small style="color:var(--text-muted)"><?= e($l['url']) ?> · <?= (int)$l['click_count'] ?> click</small>
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

  <?php if ($editingLink): ?>
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
    <div class="section-title" style="margin-top:22px;">Link normali (<?= count($actionLinks) ?>)</div>
    <?php foreach ($actionLinks as $l): renderLinkItem($l, $linkIndex[$l['id']], count($links)); endforeach; ?>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
