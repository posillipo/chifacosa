<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'post';
$pageTitle = 'Modifica aggiornamento';
$error = null;

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = getDB()->prepare('SELECT * FROM timeline_posts WHERE id=? AND user_id=?');
$stmt->execute([$id, $profile['id']]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    exit('Aggiornamento non trovato.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'send_telegram') {
        require_once __DIR__ . '/../../src/telegram.php';
        if (empty($profile['telegram_chat_id'])) {
            $error = 'Nessun canale Telegram collegato. Vai in Integrazioni → Canale Telegram per collegarne uno.';
        } elseif (!getTelegramBotToken()) {
            $error = 'Telegram non è ancora configurato dall\'amministratore del sito.';
        } else {
            $timelineUrl = siteUrl('/' . $profile['slug'] . '/timeline');
            $result = telegramPublishContent($profile, [
                'type' => 'post',
                'title' => $post['title'] ?? null,
                'body' => $post['testo'] ?? null,
                'image_url' => $post['image_path'],
                'public_url' => $timelineUrl,
            ]);
            if ($result) {
                header('Location: /dashboard_timeline_edit.php?id=' . $id . '&tg=ok');
                exit;
            }
            $error = 'Invio su Telegram fallito. Verifica che il bot sia ancora amministratore del canale.';
        }
    } elseif ($action === 'delete') {
        deleteCoverFile($post['image_path']);
        deleteFeedShareImage($post['image_path']);
        deleteCoverFile($post['image_thumb_path']);
        foreach (getTimelinePostPhotos($id) as $extraPath) {
            deleteCoverFile($extraPath);
        }
        // timeline_post_photos ha ON DELETE CASCADE: le righe spariscono da sole, qui sopra
        // servivano solo per cancellare i FILE dal disco prima che spariscano i riferimenti.
        getDB()->prepare('DELETE FROM timeline_posts WHERE id=? AND user_id=?')->execute([$id, $profile['id']]);
        logAdminAction((int) $profile['id'], (int) $user['id'], 'Aggiornamento eliminato dalla Timeline');
        header('Location: /dashboard_post.php');
        exit;
    } elseif ($action === 'delete_photo') {
        $photoId = (int) ($_POST['photo_id'] ?? -1);
        $result = deleteSingleGalleryPhoto('timeline_posts', 'image_path', 'timeline_post_photos', 'post_id', $id, (int) $profile['id'], $photoId, 'image_thumb_path');
        if (!$result['ok']) {
            $error = $result['error'];
        }
        if (!$error) {
            header('Location: /dashboard_timeline_edit.php?id=' . $id);
            exit;
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $testo = trim($_POST['testo'] ?? '');
        $hashtags = trim($_POST['hashtags'] ?? '');
        $callToAction = trim($_POST['call_to_action'] ?? '');
        // A differenza del "Link personalizzato per il feed" (impostazione del profilo, con
        // soglia temporale — vedi dashboard_post.php), questo è fisso e vale solo per QUESTO
        // post, per sempre — vedi emitPostRedirectLink() in functions.php.
        $redirectLink = trim($_POST['redirect_link'] ?? '');
        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
        // Niente più azzeramento automatico se la data è nel passato: per la visibilità pubblica
        // una publish_at passata equivale già a NULL (vedi "publish_at IS NULL OR publish_at <=
        // NOW()" usato ovunque), quindi non serve svuotarla — e farlo qui cancellava la data
        // originale di programmazione ad OGNI salvataggio di un post già pubblicato (quasi tutti,
        // una volta passato l'orario), anche senza che l'utente toccasse quel campo.
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());

        if ($redirectLink !== '' && !filter_var($redirectLink, FILTER_VALIDATE_URL)) {
            $error = 'Il link di reindirizzamento per questo post non è un URL valido.';
        } elseif ($title === '' && $testo === '') {
            $error = 'Scrivi almeno un titolo o un testo.';
        } else {
            $stmt = getDB()->prepare('UPDATE timeline_posts SET title=?, testo=?, hashtags=?, call_to_action=?, redirect_link=?, visibility=?, in_feed=?, publish_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$title !== '' ? $title : null, $testo !== '' ? $testo : null, $hashtags !== '' ? $hashtags : null, $callToAction !== '' ? $callToAction : null, $redirectLink !== '' ? $redirectLink : null, $visibility, $inFeed, $publishAt, $id, $profile['id']]);
            logAdminAction((int) $profile['id'], (int) $user['id'], 'Aggiornamento Timeline modificato');
            header('Location: /dashboard_post.php');
            exit;
        }

        // Ridisegna il form con quanto appena scritto invece dei vecchi valori del database.
        $post = array_merge($post, ['title' => $title, 'testo' => $testo, 'hashtags' => $hashtags, 'call_to_action' => $callToAction, 'redirect_link' => $redirectLink, 'visibility' => $visibility, 'in_feed' => $inFeed, 'publish_at' => $publishAt]);
    }
}

$extraPhotosStmt = getDB()->prepare('SELECT id, image_path FROM timeline_post_photos WHERE post_id=? ORDER BY sort_order ASC, id ASC');
$extraPhotosStmt->execute([$id]);
$extraPhotos = $extraPhotosStmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
$isPrivate = ($post['visibility'] ?? 'public') === 'private';
$photoCount = ($post['image_path'] ? 1 : 0) + count($extraPhotos);
?>
  <?php if (isset($_GET['tg']) && $_GET['tg'] === 'ok'): ?><div class="alert success">Inviato su Telegram!</div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <div class="tlx-listhead"><a href="/dashboard_post.php"><i class="fa-solid fa-arrow-left"></i> Torna alla Timeline</a>
    <?php if (!$isPrivate): ?><a href="/<?= e($profile['slug']) ?>/timeline/<?= (int) $post['id'] ?>" target="_blank">Vedi pagina pubblica <i class="fa-solid fa-arrow-up-right-from-square"></i></a><?php endif; ?></div>

  <form method="post" class="blx-form" id="blx-form">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
    <input type="hidden" name="tz_offset_minutes" value="">
    <div class="blx-main">
      <input type="text" name="title" class="blx-title" placeholder="Titolo (opzionale)" value="<?= e($post['title'] ?? '') ?>" maxlength="200">
      <div class="blx-editor">
        <textarea name="testo" id="tl-edit-testo" rows="10" data-ai-target placeholder="Scrivi qui..."><?= e($post['testo'] ?? '') ?></textarea>
      </div>
      <?php studioAiBox('es. annuncio nuovo concerto sabato 14 a Milano'); ?>
      <label class="tlx-lbl" for="tl-hash">Hashtag</label>
      <input type="text" name="hashtags" id="tl-hash" class="kit-inp" value="<?= e($post['hashtags'] ?? '') ?>" placeholder="#musica #live">
      <label class="tlx-lbl" for="tl-cta">Invito all'azione (call to action)</label>
      <input type="text" name="call_to_action" id="tl-cta" class="kit-inp" value="<?= e($post['call_to_action'] ?? '') ?>" placeholder="es. Prenota ora">
    </div>

    <aside class="blx-side">
      <section class="blx-box">
        <h3>Pubblicazione</h3>
        <span class="tlx-lbl">Chi lo vede</span>
        <?php studioSeg('visibility', ['public' => '<i class="fa-solid fa-earth-europe"></i> Pubblico', 'private' => '<i class="fa-solid fa-lock"></i> Solo io'], $isPrivate ? 'private' : 'public'); ?>
        <?php studioSwitch('in_feed', 'Mostra nel Feed', (bool) ($post['in_feed'] ?? 1)); ?>
        <label for="tl-when">Pubblica sui social dal</label>
        <input type="datetime-local" name="publish_at" id="tl-when" value="<?= e(localDateTimeInputValue($post['publish_at'], $profile)) ?>">
        <p class="tlx-hint">Lascia vuoto per pubblicarlo subito (se Pubblico).</p>
        <button type="submit" form="tl-del-post" class="stc-act danger tlx-del"><i class="fa-solid fa-trash"></i> <span>Elimina aggiornamento</span></button>
      </section>

      <?php if ($photoCount > 0): ?>
      <section class="blx-box">
        <h3>Foto (<?= $photoCount ?>)</h3>
        <div class="kit-gallery">
          <?php if ($post['image_path']): ?><div class="tlx-ph"><img src="/<?= e($post['image_path']) ?>" alt="" data-lightbox="/<?= e($post['image_path']) ?>"><span>Copertina</span>
            <button type="submit" form="tl-del-0" title="Elimina questa foto" aria-label="Elimina la copertina">&times;</button></div><?php endif; ?>
          <?php foreach ($extraPhotos as $ph): ?><div class="tlx-ph"><img src="/<?= e($ph['image_path']) ?>" alt="" data-lightbox="/<?= e($ph['image_path']) ?>">
            <button type="submit" form="tl-del-<?= (int) $ph['id'] ?>" title="Elimina questa foto" aria-label="Elimina la foto">&times;</button></div><?php endforeach; ?>
        </div>
        <p class="tlx-hint">Tocca una foto per vederla a schermo intero, la × per toglierla.</p>
      </section>
      <?php endif; ?>

      <section class="blx-box">
        <h3>Reindirizzamento</h3>
        <label for="tl-redir">Link per questo post</label>
        <input type="url" name="redirect_link" id="tl-redir" value="<?= e($post['redirect_link'] ?? '') ?>" placeholder="https://...">
        <p class="tlx-hint">Vale solo per <b>questo post</b>, per sempre: chi apre la pagina viene portato subito lì. Diverso dal «Link personalizzato per il feed» del profilo. Vuoto = pagina normale.</p>
      </section>
    </aside>

    <div class="blx-bar">
      <a href="/dashboard_post.php" class="btn secondary">Annulla</a>
      <button type="submit" class="tlx-submit blx-submit"><i class="fa-solid fa-check"></i> <span>Salva modifiche</span></button>
    </div>
  </form>

  <?php if ($post['image_path']): ?>
    <form method="post" id="tl-del-0" data-confirm="Eliminare la copertina? La prossima foto la sostituirà."><?= csrfField() ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"><input type="hidden" name="photo_id" value="0"></form>
  <?php endif; ?>
  <?php foreach ($extraPhotos as $ph): ?>
    <form method="post" id="tl-del-<?= (int) $ph['id'] ?>" data-confirm="Eliminare questa foto?"><?= csrfField() ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"><input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>"></form>
  <?php endforeach; ?>

  <?php if (!empty($profile['telegram_chat_id']) && !$isPrivate): ?>
    <div class="blx-danger blx-tg-box"><div><b>Telegram</b><p>Invia questo post al canale <strong><?= e($profile['telegram_chat_title'] ?: $profile['telegram_chat_id']) ?></strong>.</p></div>
      <form method="post" data-confirm="Inviare questo post su Telegram?"><?= csrfField() ?><input type="hidden" name="action" value="send_telegram"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"><button class="stc-act" type="submit"><i class="fa-brands fa-telegram"></i> <span>Invia su Telegram</span></button></form></div>
  <?php endif; ?>
  <form method="post" id="tl-del-post" data-confirm="Eliminare definitivamente questo aggiornamento? L'operazione non si può annullare."><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"></form>

  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
