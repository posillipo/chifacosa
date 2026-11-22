<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
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
        $isPublic = $visibility === 'public' ? 1 : 0;
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
        $acceptsInquiries = isset($_POST['accepts_inquiries']) ? 1 : 0;
        // Niente più azzeramento se la data è già passata: equivale a NULL per la visibilità
        // pubblica, azzerarla qui cancellava la programmazione originale ad ogni salvataggio di
        // un elemento già pubblicato (stesso bug corretto in dashboard_timeline_edit.php).
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());

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
                    $stmt = getDB()->prepare('INSERT INTO services (user_id, title, description, cover_path, accepts_inquiries, is_public, in_feed, publish_at, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM services WHERE user_id=?) t))');
                    $stmt->execute([$profile['id'], $title, $description ?: null, $newCoverPath, $acceptsInquiries, $isPublic, $inFeed, $publishAt, $profile['id']]);
                    $newId = (int) getDB()->lastInsertId();
                    setContentAuthor('services', $newId, dashboardAuthorSignature($user));
                    if ($extraPhotos) {
                        $insPhoto = getDB()->prepare('INSERT INTO service_photos (service_id, image_path, sort_order) VALUES (?,?,?)');
                        foreach ($extraPhotos as $i => $p) {
                            $insPhoto->execute([$newId, $p, $i]);
                        }
                    }

                    if ($visibility === 'public' && !$publishAt) {
                        $serviceUrl = siteUrl('/' . $profile['slug'] . '/servizi/' . $newId);
                        notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'servizio', $title, $serviceUrl);
                        require_once __DIR__ . '/../../src/metricool_queue.php';
                        socialQueueEnqueueIfEnabled((int) $profile['id'], 'service', [
                            'content_id' => $newId,
                            'title'      => $title,
                            'body'       => $description,
                            'image_url'  => $newCoverPath ? siteUrl($newCoverPath) : null,
                            'public_url' => $serviceUrl,
                        ]);
                    } elseif ($visibility === 'public' && $publishAt) {
                        // Programmato: si accoda subito con la data di pubblicazione (prima restava fuori dalla coda).
                        $queueUrl = siteUrl('/' . $profile['slug'] . '/servizi/' . $newId);
                        require_once __DIR__ . '/../../src/metricool_queue.php';
                        socialQueueEnqueueIfEnabled((int) $profile['id'], 'service', [
                            'content_id' => $newId,
                            'title'      => $title,
                            'body'       => $description,
                            'image_url'  => $newCoverPath ? siteUrl($newCoverPath) : null,
                            'public_url' => $queueUrl,
                        ], $publishAt);
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

                    $stmt = getDB()->prepare('UPDATE services SET title=?, description=?, cover_path=?, accepts_inquiries=?, is_public=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
                    $stmt->execute([$title, $description ?: null, $newCoverPath, $acceptsInquiries, $isPublic, $inFeed, $publishAt, $id, $profile['id']]);
                    if ($extraPhotos) {
                        $insPhoto = getDB()->prepare('INSERT INTO service_photos (service_id, image_path, sort_order) VALUES (?,?,?)');
                        foreach ($extraPhotos as $i => $p) {
                            $insPhoto->execute([$id, $p, $i]);
                        }
                    }
                } else {
                    $stmt = getDB()->prepare('UPDATE services SET title=?, description=?, accepts_inquiries=?, is_public=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
                    $stmt->execute([$title, $description ?: null, $acceptsInquiries, $isPublic, $inFeed, $publishAt, $id, $profile['id']]);
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
    } elseif ($action === 'delete_photo') {
        $id = (int) ($_POST['id'] ?? 0);
        $photoId = (int) ($_POST['photo_id'] ?? -1);
        $result = deleteSingleGalleryPhoto('services', 'cover_path', 'service_photos', 'service_id', $id, (int) $profile['id'], $photoId);
        if (!$result['ok']) {
            $error = $result['error'];
        }
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

include __DIR__ . '/../_dash_header.php';
$svPub = count(array_filter($services, fn ($s) => (int) $s['is_public'] === 1));
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($services) ?></b><span>Servizi</span></div>
      <div class="tlx-kpi"><b><?= $svPub ?></b><span>Pubblici</span></div>
      <a class="tlx-kpi tlx-kpi-link<?= $unreadInquiries > 0 ? ' kit-alert' : '' ?>" href="/dashboard_service_inquiries.php"><b><?= $unreadInquiries ?></b><span>Richieste da leggere</span></a>
      <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>/servizi" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>Pagina pubblica</span></a>
    </div>

    <button type="button" class="cmp-trigger" data-dlg="sv-dlg"><span class="tlx-av"><i class="fa-solid fa-hand-holding-heart"></i></span><span class="cmp-trigger-t">Aggiungi un servizio…</span><span class="cmp-trigger-i"><i class="fa-regular fa-images"></i></span></button>

    <div class="tlx-listhead"><h2>I tuoi servizi <small>(<?= count($services) ?>)</small></h2>
      <div class="tlx-filters" data-filters="#sv-list"><button type="button" class="on" data-f="all">Tutti</button><button type="button" data-f="public">Pubblici</button><button type="button" data-f="scheduled">Programmati</button><button type="button" data-f="private">Solo io</button></div>
    </div>
    <?php if (!$services): ?>
      <div class="tlx-empty"><i class="fa-solid fa-hand-holding-heart"></i><b>Nessun servizio ancora</b><span>Aggiungi il primo con almeno una foto: comparirà sulla tua pagina e nel Feed.</span></div>
    <?php endif; ?>
    <div class="tlx-empty" data-filter-empty hidden><i class="fa-regular fa-circle-check"></i><b>Niente da mostrare con questo filtro</b></div>

    <div id="sv-list">
    <?php foreach ($services as $sv):
      $isScheduled = $sv['publish_at'] && strtotime($sv['publish_at']) > time();
      $extra = getDB()->prepare('SELECT id, image_path FROM service_photos WHERE service_id=? ORDER BY sort_order ASC, id ASC');
      $extra->execute([(int) $sv['id']]); $extra = $extra->fetchAll();
      $photoCount = ($sv['cover_path'] ? 1 : 0) + count($extra);
      $state = !(int) $sv['is_public'] ? 'private' : ($isScheduled ? 'scheduled' : 'public');
      $pub = siteUrl('/' . $profile['slug'] . '/servizi/' . (int) $sv['id']);
      $share = json_encode(['t' => $sv['title'], 'u' => $pub], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
      $fill = json_encode(['id' => (int) $sv['id'], 'title' => $sv['title'], 'description' => $sv['description'] ?? '', 'accepts_inquiries' => (int) $sv['accepts_inquiries'],
        'visibility' => (int) $sv['is_public'] === 1 ? 'public' : 'private', 'in_feed' => (int) ($sv['in_feed'] ?? 1), 'publish_at' => localDateTimeInputValue($sv['publish_at'], $profile)], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
      <article class="stc stc-list kit-item" data-state="<?= e($state) ?>">
        <span class="stc-thumb"><?php if ($sv['cover_path']): ?><img src="/<?= e($sv['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="stc-ph"><i class="fa-solid fa-hand-holding-heart"></i></span><?php endif; ?></span>
        <div class="stc-body">
          <div class="stc-meta1">
            <span class="stc-type stc-violet">Servizio</span>
            <?php if (!(int) $sv['is_public']): ?><span class="stc-status stc-s-grey"><i class="fa-solid fa-lock"></i> Solo io</span>
            <?php elseif ($isScheduled): ?><span class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> Programmato · <?= e(formatLocalDateTime($sv['publish_at'], $profile)) ?></span>
            <?php else: ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Pubblicato</span><?php endif; ?>
            <?php if ((int) $sv['is_public'] && !(int) ($sv['in_feed'] ?? 1)): ?><span class="tlx-tag">Fuori dal Feed</span><?php endif; ?>
            <?= authorBadgeHtml($sv['created_by'] ?? null) ?>
          </div>
          <h3 class="stc-title"><?= e($sv['title']) ?></h3>
          <div class="stc-meta2"><i class="fa-regular fa-images"></i> <?= $photoCount ?> foto<?php if ((int) $sv['accepts_inquiries'] === 1): ?> · <i class="fa-solid fa-envelope-open-text"></i> Richiesta informazioni attiva<?php endif; ?></div>
          <div class="stc-actions">
            <button type="button" class="stc-act primary" data-dlg="sv-dlg" data-fill='<?= e($fill) ?>'><i class="fa-solid fa-pen"></i> <span>Modifica</span></button>
            <a class="stc-act" href="/<?= e($profile['slug']) ?>/servizi/<?= (int) $sv['id'] ?>" target="_blank" title="Pagina pubblica"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Pagina pubblica</span></a>
            <button type="button" class="stc-act" data-share='<?= e($share) ?>' title="Condividi"><i class="fa-solid fa-share-nodes"></i> <span>Condividi</span></button>
            <label class="tlx-switch kit-sw" title="Pulsante «Richiedi informazioni»"><input type="checkbox" <?= (int) $sv['accepts_inquiries'] === 1 ? 'checked' : '' ?> data-post='<?= e(json_encode(['action' => 'toggle_inquiries', 'id' => (int) $sv['id']])) ?>' data-msg-on="Richieste attivate" data-msg-off="Richieste disattivate"><i></i><span class="kit-sw-l">Richieste</span></label>
            <form method="post" class="ibx-inline" data-confirm="Eliminare questo servizio? Foto e richieste collegate verranno cancellate."><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $sv['id'] ?>"><button class="stc-act danger" type="submit" title="Elimina"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button></form>
          </div>
          <?php if ($photoCount > 0): ?>
          <details class="vdx-edit"><summary><i class="fa-regular fa-images"></i> Gestisci le foto (<?= $photoCount ?>)</summary>
            <div class="kit-gallery">
              <?php if ($sv['cover_path']): ?>
                <div class="tlx-ph"><img src="/<?= e($sv['cover_path']) ?>" alt=""><span>Copertina</span>
                  <form method="post" data-confirm="Eliminare la copertina? La prossima foto la sostituirà."><?= csrfField() ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="id" value="<?= (int) $sv['id'] ?>"><input type="hidden" name="photo_id" value="0"><button type="submit" title="Elimina questa foto" aria-label="Elimina la copertina">&times;</button></form></div>
              <?php endif; ?>
              <?php foreach ($extra as $ph): ?>
                <div class="tlx-ph"><img src="/<?= e($ph['image_path']) ?>" alt="">
                  <form method="post" data-confirm="Eliminare questa foto?"><?= csrfField() ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="id" value="<?= (int) $sv['id'] ?>"><input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>"><button type="submit" title="Elimina questa foto" aria-label="Elimina la foto">&times;</button></form></div>
              <?php endforeach; ?>
            </div>
            <p class="tlx-hint">Tocca la × per togliere una foto senza toccare le altre.</p>
          </details>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </div>

  <?php studioDlgOpen('sv-dlg', ['new_title' => 'Nuovo servizio', 'edit_title' => 'Modifica servizio', 'action_new' => 'add', 'action_edit' => 'edit']); ?>
    <label for="sv-title">Titolo del servizio</label>
    <input type="text" name="title" id="sv-title" required placeholder="es. Consulenza personalizzata">
    <label for="sv-desc">Descrizione <small>(opzionale)</small></label>
    <textarea name="description" id="sv-desc" rows="5" data-ai-target placeholder="Cosa comprende, come funziona..."></textarea>
    <?php studioAiBox('es. servizio di trucco per matrimoni, a domicilio'); ?>
    <label>Foto <small>(fino a <?= SERVICE_MAX_PHOTOS ?>; la prima è la copertina. In modifica: vuoto = restano le attuali, altrimenti sostituisce l'intera galleria)</small></label>
    <input type="file" name="images[]" accept="image/*" multiple data-req-new data-preview="#sv-prev">
    <div id="sv-prev" class="kit-gallery" data-preview-box hidden></div>
    <?php studioSwitch('accepts_inquiries', 'Mostra il pulsante «Richiedi informazioni»', true); ?>
    <label>Chi lo vede</label>
    <?php studioSeg('visibility', ['public' => '<i class="fa-solid fa-earth-europe"></i> Pubblico', 'private' => '<i class="fa-solid fa-lock"></i> Solo io'], 'public'); ?>
    <?php studioSwitch('in_feed', 'Mostra nel Feed', true); ?>
    <label for="sv-when">Pubblica sui social dal <small>(opzionale, vuoto = subito)</small></label>
    <input type="datetime-local" name="publish_at" id="sv-when">
  <?php studioDlgClose('Salva servizio'); ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
