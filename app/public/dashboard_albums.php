<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'albums';
$pageTitle = 'Album';
$error = null;

// Fino a 50 foto per album — molte di più delle 10 già previste per i post Timeline/Viaggi, dato
// che qui è proprio lo scopo del modulo (un album fotografico, non un singolo aggiornamento).
const ALBUM_MAX_PHOTOS = 50;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = $action === 'edit' ? (int) ($_POST['id'] ?? 0) : 0;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $showInFeed = $visibility === 'public' ? 1 : 0;
        // Interpretato nel fuso orario reale di chi sta scrivendo in questo momento (offset del
        // browser), con il fuso del profilo solo come ripiego — vedi parseLocalDateTime().
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        if ($publishAt && strtotime($publishAt) <= time()) {
            $publishAt = null;
        }

        if ($title === '') {
            $error = 'Il titolo è obbligatorio.';
        } else {
            // Caricarne di nuove sostituisce l'intero set precedente (stessa semantica già in uso
            // per Timeline/Viaggi con più foto) — se non selezioni nuovi file in modifica, l'album
            // resta con le foto che aveva.
            $uploadedPhotos = handleMultiCoverUpload($profile['slug'], 'images', ALBUM_MAX_PHOTOS);
            $newCoverPath = $uploadedPhotos[0] ?? null;
            $extraPhotos = array_slice($uploadedPhotos, 1);

            if ($action === 'add') {
                if (!$newCoverPath) {
                    $error = 'Carica almeno una foto.';
                } else {
                    $stmt = getDB()->prepare('INSERT INTO photo_albums (user_id, title, description, cover_path, show_in_feed, publish_at, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM photo_albums WHERE user_id=?) t))');
                    $stmt->execute([$profile['id'], $title, $description ?: null, $newCoverPath, $showInFeed, $publishAt, $profile['id']]);
                    $newId = (int) getDB()->lastInsertId();
                    if ($extraPhotos) {
                        $insPhoto = getDB()->prepare('INSERT INTO photo_album_photos (album_id, image_path, sort_order) VALUES (?,?,?)');
                        foreach ($extraPhotos as $i => $p) {
                            $insPhoto->execute([$newId, $p, $i]);
                        }
                    }

                    if ($visibility === 'public' && !$publishAt) {
                        $albumUrl = siteUrl('/' . $profile['slug'] . '/album/' . $newId);
                        notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'album_foto', $title, $albumUrl);
                    }
                }
            } else {
                if ($newCoverPath) {
                    $stmt = getDB()->prepare('SELECT cover_path FROM photo_albums WHERE id=? AND user_id=?');
                    $stmt->execute([$id, $profile['id']]);
                    if ($old = $stmt->fetch()) {
                        deleteCoverFile($old['cover_path']);
                        deleteFeedShareImage($old['cover_path']);
                    }
                    foreach (getAlbumPhotos($id) as $oldExtra) {
                        deleteCoverFile($oldExtra);
                    }
                    getDB()->prepare('DELETE FROM photo_album_photos WHERE album_id=?')->execute([$id]);

                    $stmt = getDB()->prepare('UPDATE photo_albums SET title=?, description=?, cover_path=?, show_in_feed=?, publish_at=? WHERE id=? AND user_id=?');
                    $stmt->execute([$title, $description ?: null, $newCoverPath, $showInFeed, $publishAt, $id, $profile['id']]);
                    if ($extraPhotos) {
                        $insPhoto = getDB()->prepare('INSERT INTO photo_album_photos (album_id, image_path, sort_order) VALUES (?,?,?)');
                        foreach ($extraPhotos as $i => $p) {
                            $insPhoto->execute([$id, $p, $i]);
                        }
                    }
                } else {
                    $stmt = getDB()->prepare('UPDATE photo_albums SET title=?, description=?, show_in_feed=?, publish_at=? WHERE id=? AND user_id=?');
                    $stmt->execute([$title, $description ?: null, $showInFeed, $publishAt, $id, $profile['id']]);
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM photo_albums WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
            deleteFeedShareImage($row['cover_path']);
            foreach (getAlbumPhotos($id) as $extraPath) {
                deleteCoverFile($extraPath);
            }
        }
        // photo_album_photos ha ON DELETE CASCADE: le righe spariscono da sole, qui sopra
        // servivano solo per cancellare i FILE dal disco prima che spariscano i riferimenti.
        $stmt = getDB()->prepare('DELETE FROM photo_albums WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    }
    if (!$error) {
        header('Location: /dashboard_albums.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM photo_albums WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$albums = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Un album è un gruppo di foto con un titolo e una descrizione, mostrato nella sezione
      pubblica "Foto" insieme alle foto prese dai tuoi post Timeline. Puoi caricare fino a
      <?= ALBUM_MAX_PHOTOS ?> foto per album.
    </p>
    <p style="color:var(--text-muted)">
      Pubblico/Solo io e la programmazione funzionano come nel resto del sito: un album privato
      non compare nella pagina pubblica né nel Feed, uno programmato compare automaticamente alla
      data scelta.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="tz_offset_minutes" value="">
    <label>Titolo album</label>
    <input type="text" name="title" required placeholder="es. Il nostro tour 2026">
    <label>Descrizione (opzionale)</label>
    <textarea name="description" rows="4" placeholder="Racconta l'album..."></textarea>
    <label>Foto (fino a <?= ALBUM_MAX_PHOTOS ?>)</label>
    <input type="file" name="images[]" accept="image/*" multiple required>
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">La prima foto selezionata diventa la copertina dell'album.</p>
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
    <button type="submit" class="btn" style="margin-top:10px;">Crea album</button>
  </form>

  <div class="section-title">I tuoi album (<?= count($albums) ?>)</div>
  <?php foreach ($albums as $al): ?>
    <?php
      $isScheduled = $al['publish_at'] && strtotime($al['publish_at']) > time();
      $photoCount = 1 + count(getAlbumPhotos((int) $al['id']));
    ?>
    <div class="event-item" style="display:flex;gap:14px;align-items:flex-start;">
      <?php if ($al['cover_path']): ?>
        <img src="/<?= e($al['cover_path']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <strong><?= e($al['title']) ?></strong>
        <span style="color:var(--text-muted);font-size:12.5px;"> · <?= $photoCount ?> foto</span>
        <?php if (!(int) $al['show_in_feed']): ?>
          <div style="color:var(--text-muted);font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-lock"></i> Solo io</div>
        <?php elseif ($isScheduled): ?>
          <div style="color:#f0ad4e;font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-clock"></i> Programmato per il <?= e(formatLocalDateTime($al['publish_at'], $profile)) ?></div>
        <?php else: ?>
          <div style="color:#2e7d32;font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-circle-check"></i> Pubblico</div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
          <a href="/<?= e($profile['slug']) ?>/album/<?= (int) $al['id'] ?>" target="_blank" class="btn small secondary">Vedi pagina pubblica</a>
          <form method="post" onsubmit="return confirm('Eliminare questo album? Tutte le foto verranno cancellate.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $al['id'] ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        </div>

        <details style="margin-top:8px;">
          <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica</summary>
          <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int) $al['id'] ?>">
            <input type="hidden" name="tz_offset_minutes" value="">
            <label>Titolo album</label>
            <input type="text" name="title" value="<?= e($al['title']) ?>" required>
            <label>Descrizione (opzionale)</label>
            <textarea name="description" rows="4"><?= e($al['description'] ?? '') ?></textarea>
            <label>Foto (opzionale — lascia vuoto per non cambiarle, altrimenti sostituisci l'intero set)</label>
            <input type="file" name="images[]" accept="image/*" multiple>
            <label>Privacy</label>
            <div style="display:flex;gap:16px;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="visibility" value="public" <?= (int) $al['show_in_feed'] === 1 ? 'checked' : '' ?> style="width:auto;"> Pubblico
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="visibility" value="private" <?= (int) $al['show_in_feed'] === 0 ? 'checked' : '' ?> style="width:auto;"> Solo io
              </label>
            </div>
            <label>Programma la pubblicazione (opzionale)</label>
            <input type="datetime-local" name="publish_at" value="<?= $al['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($al['publish_at']))) : '' ?>">
            <button type="submit" class="btn small" style="margin-top:10px;">Salva modifiche</button>
          </form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
