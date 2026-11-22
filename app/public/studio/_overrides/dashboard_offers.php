<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'offers';
$pageTitle = 'Offerte speciali';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = $action === 'edit' ? (int) ($_POST['id'] ?? 0) : 0;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priceLabel = trim($_POST['price_label'] ?? '');
        // Interpretati nel fuso orario scelto dal profilo (Dashboard -> Profilo e anagrafica),
        // non in quello del server — vedi parseLocalDateTime() in functions.php.
        $validFrom = parseLocalDateTime($_POST['valid_from'] ?? '', $profile, browserTzOffsetFromRequest());
        $validUntil = parseLocalDateTime($_POST['valid_until'] ?? '', $profile, browserTzOffsetFromRequest());
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;

        if ($title === '') {
            $error = 'Il titolo è obbligatorio.';
        } elseif ($validFrom && $validUntil && $validFrom > $validUntil) {
            $error = 'La data di inizio non può essere dopo la data di fine.';
        } elseif ($action === 'add') {
            $coverPath = handleCoverUpload($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO special_offers (user_id, title, description, price_label, cover_path, valid_from, valid_until, is_active, in_feed, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM special_offers WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $title, $description ?: null, $priceLabel ?: null, $coverPath, $validFrom, $validUntil, $isActive, $inFeed, $profile['id']]);
            $newId = (int) getDB()->lastInsertId();

            $offerUrl = siteUrl('/' . $profile['slug'] . '/offerte/' . $newId);
            notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'offerta', $title, $offerUrl);
            require_once __DIR__ . '/../../src/metricool_queue.php';
            socialQueueEnqueueIfEnabled((int) $profile['id'], 'offer', [
                'content_id' => $newId,
                'title'      => $title,
                'body'       => $description,
                'image_url'  => $coverPath ? siteUrl($coverPath) : null,
                'public_url' => $offerUrl,
            ]);
        } else {
            // Copertina opzionale in modifica: un nuovo file la sostituisce (e cancella quella
            // precedente), lasciando il campo vuoto quella già caricata resta invariata.
            $newCoverPath = handleCoverUpload($profile['slug']);
            if ($newCoverPath) {
                $stmt = getDB()->prepare('SELECT cover_path FROM special_offers WHERE id=? AND user_id=?');
                $stmt->execute([$id, $profile['id']]);
                if ($old = $stmt->fetch()) {
                    deleteCoverFile($old['cover_path']);
                }
                $stmt = getDB()->prepare('UPDATE special_offers SET title=?, description=?, price_label=?, cover_path=?, valid_from=?, valid_until=?, is_active=?, in_feed=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $description ?: null, $priceLabel ?: null, $newCoverPath, $validFrom, $validUntil, $isActive, $inFeed, $id, $profile['id']]);
            } else {
                $stmt = getDB()->prepare('UPDATE special_offers SET title=?, description=?, price_label=?, valid_from=?, valid_until=?, is_active=?, in_feed=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $description ?: null, $priceLabel ?: null, $validFrom, $validUntil, $isActive, $inFeed, $id, $profile['id']]);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM special_offers WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM special_offers WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE special_offers SET is_active = NOT is_active WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    }
    if (!$error) {
        header('Location: /dashboard_offers.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM special_offers WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$offers = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
$now = date('Y-m-d H:i:s');
$offNow = 0; $offOff = 0;
foreach ($offers as $of) {
    $valid = (!$of['valid_from'] || $of['valid_from'] <= $now) && (!$of['valid_until'] || $of['valid_until'] >= $now);
    if ((int) $of['is_active'] && $valid) { $offNow++; } elseif (!(int) $of['is_active']) { $offOff++; }
}
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($offers) ?></b><span>Offerte</span></div>
      <div class="tlx-kpi"><b><?= $offNow ?></b><span>Valide adesso</span></div>
      <div class="tlx-kpi"><b><?= $offOff ?></b><span>Disattivate</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>/offerte" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>Pagina pubblica</span></a>
    </div>

    <button type="button" class="cmp-trigger" data-dlg="off-dlg"><span class="tlx-av"><i class="fa-solid fa-tags"></i></span><span class="cmp-trigger-t">Crea una nuova offerta…</span><span class="cmp-trigger-i"><i class="fa-regular fa-image"></i></span></button>

    <div class="tlx-listhead"><h2>Le tue offerte <small>(<?= count($offers) ?>)</small></h2>
      <div class="tlx-filters" data-filters="#off-list"><button type="button" class="on" data-f="all">Tutte</button><button type="button" data-f="valid">Valide</button><button type="button" data-f="expired">Fuori validità</button><button type="button" data-f="off">Disattivate</button></div>
    </div>
    <?php if (!$offers): ?>
      <div class="tlx-empty"><i class="fa-solid fa-tags"></i><b>Nessuna offerta ancora</b><span>Crea la prima: sconto di benvenuto, 2x1, promozione del mese…</span></div>
    <?php endif; ?>
    <div class="tlx-empty" data-filter-empty hidden><i class="fa-regular fa-circle-check"></i><b>Niente da mostrare con questo filtro</b></div>

    <div id="off-list">
    <?php foreach ($offers as $of):
      $isCurrentlyValid = (!$of['valid_from'] || $of['valid_from'] <= $now) && (!$of['valid_until'] || $of['valid_until'] >= $now);
      $active = (int) $of['is_active'] === 1;
      $state = !$active ? 'off' : ($isCurrentlyValid ? 'valid' : 'expired');
      $pub = siteUrl('/' . $profile['slug'] . '/offerte/' . (int) $of['id']);
      $share = json_encode(['t' => $of['title'], 'u' => $pub], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
      $fill = json_encode(['id' => (int) $of['id'], 'title' => $of['title'], 'price_label' => $of['price_label'] ?? '', 'description' => $of['description'] ?? '',
        'valid_from' => $of['valid_from'] ? date('Y-m-d\TH:i', strtotime($of['valid_from'])) : '', 'valid_until' => $of['valid_until'] ? date('Y-m-d\TH:i', strtotime($of['valid_until'])) : '',
        'is_active' => $active ? 1 : 0, 'in_feed' => (int) ($of['in_feed'] ?? 1)], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
      <article class="stc stc-list kit-item<?= $active ? '' : ' off' ?>" data-row data-on="<?= $active ? 1 : 0 ?>" data-state="<?= e($state) ?>">
        <span class="stc-thumb"><?php if ($of['cover_path']): ?><img src="/<?= e($of['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="stc-ph"><i class="fa-solid fa-tags"></i></span><?php endif; ?></span>
        <div class="stc-body">
          <div class="stc-meta1">
            <span class="stc-type stc-violet">Offerta</span>
            <?php if (!$active): ?><span class="stc-status stc-s-grey"><i class="fa-solid fa-pause"></i> Disattivata</span>
            <?php elseif (!$isCurrentlyValid): ?><span class="stc-status stc-s-danger"><i class="fa-regular fa-clock"></i> Fuori validità</span>
            <?php else: ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Visibile al pubblico</span><?php endif; ?>
            <?php if ($active && !(int) ($of['in_feed'] ?? 1)): ?><span class="tlx-tag">Fuori dal Feed</span><?php endif; ?>
            <?php if ($of['price_label']): ?><span class="kit-price"><?= e($of['price_label']) ?></span><?php endif; ?>
          </div>
          <h3 class="stc-title"><?= e($of['title']) ?></h3>
          <?php if ($of['valid_from'] || $of['valid_until']): ?>
            <div class="stc-meta2"><i class="fa-regular fa-calendar"></i> <?= $of['valid_from'] ? 'Dal ' . e(formatLocalDateTime($of['valid_from'], $profile)) : '' ?><?= $of['valid_from'] && $of['valid_until'] ? ' — ' : '' ?><?= $of['valid_until'] ? 'fino al ' . e(formatLocalDateTime($of['valid_until'], $profile)) : '' ?></div>
          <?php else: ?><div class="stc-meta2">Sempre attiva</div><?php endif; ?>
          <div class="stc-actions">
            <button type="button" class="stc-act primary" data-dlg="off-dlg" data-fill='<?= e($fill) ?>'><i class="fa-solid fa-pen"></i> <span>Modifica</span></button>
            <a class="stc-act" href="/<?= e($profile['slug']) ?>/offerte/<?= (int) $of['id'] ?>" target="_blank" title="Pagina pubblica"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Pagina pubblica</span></a>
            <button type="button" class="stc-act" data-share='<?= e($share) ?>' title="Condividi"><i class="fa-solid fa-share-nodes"></i> <span>Condividi</span></button>
            <label class="tlx-switch kit-sw" title="Attiva o disattiva"><input type="checkbox" <?= $active ? 'checked' : '' ?> data-post='<?= e(json_encode(['action' => 'toggle_active', 'id' => (int) $of['id']])) ?>' data-msg-on="Offerta attivata" data-msg-off="Offerta disattivata"><i></i></label>
            <form method="post" class="ibx-inline" data-confirm="Eliminare questa offerta?"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $of['id'] ?>"><button class="stc-act danger" type="submit" title="Elimina"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button></form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </div>

  <?php studioDlgOpen('off-dlg', ['new_title' => 'Nuova offerta', 'edit_title' => 'Modifica offerta', 'action_new' => 'add', 'action_edit' => 'edit']); ?>
    <label for="off-title">Titolo</label>
    <input type="text" name="title" id="off-title" required placeholder="es. Sconto di benvenuto">
    <label for="off-price">Prezzo o sconto <small>(opzionale)</small></label>
    <input type="text" name="price_label" id="off-price" placeholder="es. -20%, 2x1, €19,90 invece di €29,90">
    <label for="off-desc">Descrizione <small>(opzionale)</small></label>
    <textarea name="description" id="off-desc" rows="5" data-ai-target placeholder="Dettagli, condizioni, cosa include..."></textarea>
    <?php studioAiBox('es. -20% su tutti gli abiti da cerimonia fino a fine mese'); ?>
    <label>Foto <small>(opzionale: in modifica, vuoto = resta quella attuale)</small></label>
    <input type="file" name="cover" accept="image/*" data-preview="#off-prev">
    <div id="off-prev" class="kit-gallery" data-preview-box hidden></div>
    <div class="kit-row2">
      <div><label for="off-from">Valida dal</label><input type="datetime-local" name="valid_from" id="off-from"></div>
      <div><label for="off-until">Valida fino al</label><input type="datetime-local" name="valid_until" id="off-until"></div>
    </div>
    <p class="tlx-hint">Lascia vuote le date per un'offerta sempre attiva.</p>
    <?php studioSwitch('is_active', 'Attiva', true); ?>
    <?php studioSwitch('in_feed', 'Mostra nel Feed', true); ?>
  <?php studioDlgClose('Salva offerta'); ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
