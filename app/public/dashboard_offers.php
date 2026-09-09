<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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
        $validFrom = parseLocalDateTime($_POST['valid_from'] ?? '', $profile);
        $validUntil = parseLocalDateTime($_POST['valid_until'] ?? '', $profile);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            $error = 'Il titolo è obbligatorio.';
        } elseif ($validFrom && $validUntil && $validFrom > $validUntil) {
            $error = 'La data di inizio non può essere dopo la data di fine.';
        } elseif ($action === 'add') {
            $coverPath = handleCoverUpload($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO special_offers (user_id, title, description, price_label, cover_path, valid_from, valid_until, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM special_offers WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $title, $description ?: null, $priceLabel ?: null, $coverPath, $validFrom, $validUntil, $isActive, $profile['id']]);
            $newId = (int) getDB()->lastInsertId();

            $offerUrl = siteUrl('/' . $profile['slug'] . '/offerte/' . $newId);
            notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'offerta', $title, $offerUrl);
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
                $stmt = getDB()->prepare('UPDATE special_offers SET title=?, description=?, price_label=?, cover_path=?, valid_from=?, valid_until=?, is_active=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $description ?: null, $priceLabel ?: null, $newCoverPath, $validFrom, $validUntil, $isActive, $id, $profile['id']]);
            } else {
                $stmt = getDB()->prepare('UPDATE special_offers SET title=?, description=?, price_label=?, valid_from=?, valid_until=?, is_active=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $description ?: null, $priceLabel ?: null, $validFrom, $validUntil, $isActive, $id, $profile['id']]);
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

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Promozioni e sconti mostrati sulla tua pagina pubblica, in una sezione dedicata "Offerte"
      (compare solo se hai almeno un'offerta attiva e valida). Ognuna ha anche una pagina propria
      condivisibile sui social, con anteprima corretta (immagine, titolo, descrizione).
    </p>
    <p style="color:var(--text-muted)">
      La validità è facoltativa: lasciala vuota per un'offerta sempre attiva, oppure imposta un
      inizio e/o una fine — fuori da quell'intervallo l'offerta smette automaticamente di comparire
      lato pubblico (resta comunque qui, puoi riattivarla cambiando le date). Il pulsante
      Attiva/Disattiva sospende un'offerta manualmente, senza doverla eliminare.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <label>Titolo</label>
    <input type="text" name="title" required placeholder="es. Sconto di benvenuto">
    <label>Prezzo/sconto (opzionale)</label>
    <input type="text" name="price_label" placeholder="es. -20%, 2x1, €19,90 invece di €29,90">
    <label>Descrizione (opzionale)</label>
    <textarea name="description" rows="4" placeholder="Dettagli, condizioni, cosa include..."></textarea>
    <label>Foto (opzionale, jpg/png/webp)</label>
    <input type="file" name="cover" accept="image/*">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Comparirà così come l'hai caricata, senza ritagli.</p>
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
      <div style="flex:1;min-width:200px;">
        <label>Valida dal (opzionale)</label>
        <input type="datetime-local" name="valid_from">
      </div>
      <div style="flex:1;min-width:200px;">
        <label>Valida fino al (opzionale)</label>
        <input type="datetime-local" name="valid_until">
      </div>
    </div>
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia entrambe vuote per un'offerta sempre attiva.</p>
    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:8px 0 16px;">
      <input type="checkbox" name="is_active" value="1" checked style="width:auto;margin-bottom:0;">
      Attiva
    </label>
    <button type="submit" class="btn">Aggiungi offerta</button>
  </form>

  <div class="section-title">Le tue offerte (<?= count($offers) ?>)</div>
  <?php foreach ($offers as $of): ?>
    <?php
      $now = date('Y-m-d H:i:s');
      $isCurrentlyValid = (!$of['valid_from'] || $of['valid_from'] <= $now) && (!$of['valid_until'] || $of['valid_until'] >= $now);
    ?>
    <div class="event-item" style="display:flex;gap:14px;align-items:flex-start;">
      <?php if ($of['cover_path']): ?>
        <img src="/<?= e($of['cover_path']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <strong><?= e($of['title']) ?></strong>
        <?php if ($of['price_label']): ?><span style="color:var(--accent);font-weight:700;"> · <?= e($of['price_label']) ?></span><?php endif; ?>
        <?php if (!(int) $of['is_active']): ?>
          <div style="color:var(--text-muted);font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-pause"></i> Disattivata</div>
        <?php elseif (!$isCurrentlyValid): ?>
          <div style="color:#c0392b;font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-clock"></i> Fuori validità (non visibile lato pubblico)</div>
        <?php else: ?>
          <div style="color:#2e7d32;font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-circle-check"></i> Visibile lato pubblico</div>
        <?php endif; ?>
        <?php if ($of['valid_from'] || $of['valid_until']): ?>
          <div style="color:var(--text-muted);font-size:12.5px;margin-top:2px;">
            <?= $of['valid_from'] ? 'Dal ' . e(formatLocalDateTime($of['valid_from'], $profile)) : '' ?>
            <?= $of['valid_from'] && $of['valid_until'] ? ' — ' : '' ?>
            <?= $of['valid_until'] ? 'Fino al ' . e(formatLocalDateTime($of['valid_until'], $profile)) : '' ?>
          </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int) $of['id'] ?>">
            <button class="btn small secondary" type="submit"><?= (int) $of['is_active'] === 1 ? 'Disattiva' : 'Attiva' ?></button>
          </form>
          <form method="post" onsubmit="return confirm('Eliminare questa offerta?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $of['id'] ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        </div>

        <details style="margin-top:8px;">
          <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica</summary>
          <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int) $of['id'] ?>">
            <label>Titolo</label>
            <input type="text" name="title" value="<?= e($of['title']) ?>" required>
            <label>Prezzo/sconto (opzionale)</label>
            <input type="text" name="price_label" value="<?= e($of['price_label'] ?? '') ?>">
            <label>Descrizione (opzionale)</label>
            <textarea name="description" rows="4"><?= e($of['description'] ?? '') ?></textarea>
            <label>Foto (opzionale — lascia vuoto per non cambiarla)</label>
            <input type="file" name="cover" accept="image/*">
            <div style="display:flex;gap:16px;flex-wrap:wrap;">
              <div style="flex:1;min-width:200px;">
                <label>Valida dal (opzionale)</label>
                <input type="datetime-local" name="valid_from" value="<?= $of['valid_from'] ? e(date('Y-m-d\TH:i', strtotime($of['valid_from']))) : '' ?>">
              </div>
              <div style="flex:1;min-width:200px;">
                <label>Valida fino al (opzionale)</label>
                <input type="datetime-local" name="valid_until" value="<?= $of['valid_until'] ? e(date('Y-m-d\TH:i', strtotime($of['valid_until']))) : '' ?>">
              </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:8px 0 16px;">
              <input type="checkbox" name="is_active" value="1" <?= (int) $of['is_active'] === 1 ? 'checked' : '' ?> style="width:auto;margin-bottom:0;">
              Attiva
            </label>
            <button type="submit" class="btn small">Salva modifiche</button>
          </form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
