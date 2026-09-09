<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'services';
$pageTitle = 'Servizi';
$error = null;

// Fino a 20 foto per servizio — una galleria, non un album fotografico (che arriva a 50).
const SERVICE_MAX_PHOTOS = 20;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = $action === 'edit' ? (int) ($_POST['id'] ?? 0) : 0;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $showInFeed = $visibility === 'public' ? 1 : 0;
        $acceptsInquiries = isset($_POST['accepts_inquiries']) ? 1 : 0;
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        if ($publishAt && strtotime($publishAt) <= time()) {
            $publishAt = null;
        }

        if ($title === '') {
            $error = 'Il titolo è obbligatorio.';
        } else {
            $uploadedPhotos = handleMultiCoverUpload($profile['slug'], 'images', SERVICE_MAX_PHOTOS);
            $newCoverPath = $uploadedPhotos[0] ?? null;
            $extraPhotos = array_slice($uploadedPhotos, 1);

            if ($action === 'add') {
                if (!$newCoverPath) {
                    $error = 'Carica almeno una foto.';
                } else {
                    $stmt = getDB()->prepare('INSERT INTO services (user_id, title, description, cover_path, accepts_inquiries, show_in_feed, publish_at, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM services WHERE user_id=?) t))');
                    $stmt->execute([$profile['id'], $title, $description ?: null, $newCoverPath, $acceptsInquiries, $showInFeed, $publishAt, $profile['id']]);
                    $newId = (int) getDB()->lastInsertId();
                    if ($extraPhotos) {
                        $insPhoto = getDB()->prepare('INSERT INTO service_photos (service_id, image_path, sort_order) VALUES (?,?,?)');
                        foreach ($extraPhotos as $i => $p) {
                            $insPhoto->execute([$newId, $p, $i]);
                        }
                    }

                    if ($visibility === 'public' && !$publishAt) {
                        $serviceUrl = siteUrl('/' . $profile['slug'] . '/servizi/' . $newId);
                        notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'servizio', $title, $serviceUrl);
                    }
                }
            } else {
                if ($newCoverPath) {
                    $stmt = getDB()->prepare('SELECT cover_path FROM services WHERE id=? AND user_id=?');
                    $stmt->execute([$id, $profile['id']]);
                    if ($old = $stmt->fetch()) {
                        deleteCoverFile($old['cover_path']);
                        deleteFeedShareImage($old['cover_path']);
                    }
                    foreach (getServicePhotos($id) as $oldExtra) {
                        deleteCoverFile($oldExtra);
                    }
                    getDB()->prepare('DELETE FROM service_photos WHERE service_id=?')->execute([$id]);

                    $stmt = getDB()->prepare('UPDATE services SET title=?, description=?, cover_path=?, accepts_inquiries=?, show_in_feed=?, publish_at=? WHERE id=? AND user_id=?');
                    $stmt->execute([$title, $description ?: null, $newCoverPath, $acceptsInquiries, $showInFeed, $publishAt, $id, $profile['id']]);
                    if ($extraPhotos) {
                        $insPhoto = getDB()->prepare('INSERT INTO service_photos (service_id, image_path, sort_order) VALUES (?,?,?)');
                        foreach ($extraPhotos as $i => $p) {
                            $insPhoto->execute([$id, $p, $i]);
                        }
                    }
                } else {
                    $stmt = getDB()->prepare('UPDATE services SET title=?, description=?, accepts_inquiries=?, show_in_feed=?, publish_at=? WHERE id=? AND user_id=?');
                    $stmt->execute([$title, $description ?: null, $acceptsInquiries, $showInFeed, $publishAt, $id, $profile['id']]);
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM services WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
            deleteFeedShareImage($row['cover_path']);
            foreach (getServicePhotos($id) as $extraPath) {
                deleteCoverFile($extraPath);
            }
        }
        // service_photos e service_inquiries hanno ON DELETE CASCADE: le righe spariscono da
        // sole, qui sopra serviva solo cancellare i FILE dal disco prima che spariscano i
        // riferimenti.
        $stmt = getDB()->prepare('DELETE FROM services WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'toggle_inquiries') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE services SET accepts_inquiries = NOT accepts_inquiries WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    }
    if (!$error) {
        header('Location: /dashboard_services.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM services WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$services = $stmt->fetchAll();

$unreadInquiries = getDB()->prepare('SELECT COUNT(*) c FROM service_inquiries WHERE user_id=? AND is_read=0');
$unreadInquiries->execute([$profile['id']]);
$unreadInquiries = (int) $unreadInquiries->fetch()['c'];

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Illustra i servizi della tua attività: foto, titolo, descrizione e una galleria fotografica
      a carosello (fino a <?= SERVICE_MAX_PHOTOS ?> foto). Il pulsante "Richiedi informazioni" lato
      pubblico si può attivare o disattivare per ciascun servizio — quando è attivo, chi lo compila
      ti arriva via email e nella scheda "Richieste" qui sotto.
    </p>
  </details>

  <?php if ($unreadInquiries > 0): ?>
    <div class="alert success">
      Hai <?= $unreadInquiries ?> richiesta<?= $unreadInquiries === 1 ? '' : 'e' ?> di informazioni
      da leggere — <a href="/dashboard_service_inquiries.php">vai alle richieste</a>.
    </div>
  <?php endif; ?>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="tz_offset_minutes" value="">
    <label>Titolo servizio</label>
    <input type="text" name="title" required placeholder="es. Consulenza personalizzata">
    <label>Descrizione (opzionale)</label>
    <textarea name="description" rows="4" placeholder="Cosa comprende, come funziona..."></textarea>
    <label>Foto (fino a <?= SERVICE_MAX_PHOTOS ?>)</label>
    <input type="file" name="images[]" accept="image/*" multiple required>
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">La prima foto selezionata diventa la copertina.</p>
    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:8px 0 16px;">
      <input type="checkbox" name="accepts_inquiries" value="1" checked style="width:auto;margin-bottom:0;">
      Mostra il pulsante "Richiedi informazioni" lato pubblico
    </label>
    <label>Privacy</label>
    <div style="display:flex;gap:16px;margin-bottom:14px;">
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="visibility" value="public" checked style="width:auto;"> Pubblico
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="visibility" value="private" style="width:auto;"> Solo io
      </label>
    </div>
    <label>Programma la pubblicazione (opzionale)</label>
    <input type="datetime-local" name="publish_at">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Lascia vuoto per pubblicare subito.</p>
    <button type="submit" class="btn" style="margin-top:10px;">Aggiungi servizio</button>
  </form>

  <div class="section-title">I tuoi servizi (<?= count($services) ?>)</div>
  <?php foreach ($services as $sv): ?>
    <?php
      $isScheduled = $sv['publish_at'] && strtotime($sv['publish_at']) > time();
      $photoCount = 1 + count(getServicePhotos((int) $sv['id']));
    ?>
    <div class="event-item" style="display:flex;gap:14px;align-items:flex-start;">
      <?php if ($sv['cover_path']): ?>
        <img src="/<?= e($sv['cover_path']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <strong><?= e($sv['title']) ?></strong>
        <span style="color:var(--text-muted);font-size:12.5px;"> · <?= $photoCount ?> foto</span>
        <?php if (!(int) $sv['show_in_feed']): ?>
          <div style="color:var(--text-muted);font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-lock"></i> Solo io</div>
        <?php elseif ($isScheduled): ?>
          <div style="color:#f0ad4e;font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-clock"></i> Programmato per il <?= e(formatLocalDateTime($sv['publish_at'], $profile)) ?></div>
        <?php else: ?>
          <div style="color:#2e7d32;font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-circle-check"></i> Pubblico</div>
        <?php endif; ?>
        <?php if ((int) $sv['accepts_inquiries'] === 1): ?>
          <div style="color:var(--accent);font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-envelope-open-text"></i> Richiedi informazioni attivo</div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
          <a href="/<?= e($profile['slug']) ?>/servizi/<?= (int) $sv['id'] ?>" target="_blank" class="btn small secondary">Vedi pagina pubblica</a>
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_inquiries">
            <input type="hidden" name="id" value="<?= (int) $sv['id'] ?>">
            <button class="btn small secondary" type="submit"><?= (int) $sv['accepts_inquiries'] === 1 ? 'Disattiva richieste' : 'Attiva richieste' ?></button>
          </form>
          <form method="post" onsubmit="return confirm('Eliminare questo servizio? Foto e richieste collegate verranno cancellate.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $sv['id'] ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        </div>

        <details style="margin-top:8px;">
          <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica</summary>
          <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int) $sv['id'] ?>">
            <input type="hidden" name="tz_offset_minutes" value="">
            <label>Titolo servizio</label>
            <input type="text" name="title" value="<?= e($sv['title']) ?>" required>
            <label>Descrizione (opzionale)</label>
            <textarea name="description" rows="4"><?= e($sv['description'] ?? '') ?></textarea>
            <label>Foto (opzionale — lascia vuoto per non cambiarle, altrimenti sostituisci l'intera galleria)</label>
            <input type="file" name="images[]" accept="image/*" multiple>
            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:8px 0 16px;">
              <input type="checkbox" name="accepts_inquiries" value="1" <?= (int) $sv['accepts_inquiries'] === 1 ? 'checked' : '' ?> style="width:auto;margin-bottom:0;">
              Mostra il pulsante "Richiedi informazioni" lato pubblico
            </label>
            <label>Privacy</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="visibility" value="public" <?= (int) $sv['show_in_feed'] === 1 ? 'checked' : '' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="visibility" value="private" <?= (int) $sv['show_in_feed'] === 0 ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>
            <label>Programma la pubblicazione (opzionale)</label>
            <input type="datetime-local" name="publish_at" value="<?= $sv['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($sv['publish_at']))) : '' ?>">
            <button type="submit" class="btn small" style="margin-top:10px;">Salva modifiche</button>
          </form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
