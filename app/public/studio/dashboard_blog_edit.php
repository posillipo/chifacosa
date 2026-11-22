<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Modifica articolo';
$error = null;

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = getDB()->prepare('SELECT * FROM blog_posts WHERE id=? AND user_id=?');
$stmt->execute([$id, $profile['id']]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    exit('Articolo non trovato.');
}

$stmt = getDB()->prepare('SELECT * FROM blog_categories WHERE user_id=? ORDER BY name ASC');
$stmt->execute([$profile['id']]);
$categories = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT id, title FROM photo_albums WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$albums = $stmt->fetchAll();

// Categorie assegnate all'articolo: dalla richiesta appena inviata se stiamo ridisegnando il
// form dopo un errore di validazione (altrimenti si perderebbero le checkbox appena spuntate),
// altrimenti quelle già salvate nel database.
$selectedCategoryIds = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_filter(array_map('intval', $_POST['category_ids'] ?? []))
    : array_column(getBlogPostCategories($id), 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (($_POST['action'] ?? '') === 'delete') {
        require_once __DIR__ . '/../../src/videos.php';
        blogCoverDeleteAll($post);
        if (empty($post['cover_video_path'])) {
            deleteCoverFile($post['cover_path']);
        }
        getDB()->prepare('DELETE FROM blog_posts WHERE id=? AND user_id=?')->execute([$id, $profile['id']]);
        // Toglie dalla coda social le uscite non ancora elaborate di questo articolo (quelle già
        // programmate su Metricool restano, vanno tolte da lì).
        try {
            getDB()->prepare("DELETE FROM social_queue WHERE user_id=? AND content_type='blog' AND content_id=? AND status IN ('pending','processing')")->execute([$profile['id'], $id]);
        } catch (Throwable $e) {
        }
        header('Location: /dashboard_blog_posts.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'send_telegram') {
        require_once __DIR__ . '/../../src/telegram.php';
        if (empty($profile['telegram_chat_id'])) {
            $error = 'Nessun canale Telegram collegato. Vai in Integrazioni → Canale Telegram.';
        } elseif (!getTelegramBotToken()) {
            $error = 'Telegram non è ancora configurato dall\'amministratore del sito.';
        } else {
            $postUrl = siteUrl(blogPostUrl($profile['slug'], $post));
            $result = telegramPublishContent($profile, [
                'type' => 'blog',
                'title' => $post['title'],
                'body' => $post['content'],
                'image_url' => $post['cover_path'],
                'public_url' => $postUrl,
            ]);
            if ($result) {
                header('Location: /dashboard_blog_edit.php?id=' . $id . '&tg=ok');
                exit;
            }
            $error = 'Invio su Telegram fallito. Verifica che il bot sia amministratore del canale.';
        }
    }
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $albumId = (int) ($_POST['album_id'] ?? 0) ?: null;
    $tagsRaw = trim($_POST['tags'] ?? '');
    $tags = $tagsRaw !== '' ? implode(', ', array_filter(array_map('trim', explode(',', $tagsRaw)), fn ($t) => $t !== '')) : null;
    $tags = $tags !== '' ? $tags : null;
    $categoryIds = $selectedCategoryIds;
    $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
    $publishedAt = parseLocalDateTime($_POST['published_at'] ?? '', $profile, browserTzOffsetFromRequest()) ?: $post['published_at'];

    if ($title === '' || $content === '') {
        $error = 'Titolo e contenuto sono obbligatori.';
        // Ridisegna il form con quanto appena scritto invece dei vecchi valori del database.
        $post = array_merge($post, ['title' => $title, 'content' => $content, 'album_id' => $albumId, 'tags' => $tags, 'published_at' => $publishedAt, 'in_feed' => $inFeed]);
    } else {
        // Verifica che album e categorie appartengano davvero a questo utente, per evitare che un
        // ID arbitrario nel form li associ a roba di qualcun altro (stesso controllo di dashboard_blog.php).
        if ($albumId) {
            $albStmt = getDB()->prepare('SELECT id FROM photo_albums WHERE id=? AND user_id=?');
            $albStmt->execute([$albumId, $profile['id']]);
            if (!$albStmt->fetch()) {
                $albumId = null;
            }
        }
        if ($categoryIds) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $catStmt = getDB()->prepare("SELECT id FROM blog_categories WHERE user_id=? AND id IN ($placeholders)");
            $catStmt->execute(array_merge([$profile['id']], $categoryIds));
            $categoryIds = array_column($catStmt->fetchAll(), 'id');
        }

        $excerpt = textExcerpt($content, 200);
        require_once __DIR__ . '/../../src/videos.php';
        // Copertina: immagine OPPURE video. Un nuovo file sostituisce quello attuale, di qualunque tipo.
        $coverPath = $post['cover_path'];
        $coverVideoPath = $post['cover_video_path'] ?? null;
        $vid = blogCoverVideoFromUpload($profile['slug']);
        if ($vid !== null && isset($vid['error'])) {
            $error = $vid['error'];
        } elseif ($vid !== null) {
            blogCoverDeleteAll($post);
            if (empty($post['cover_video_path'])) {
                deleteCoverFile($post['cover_path']);
            }
            $coverVideoPath = $vid['video_path'];
            $coverPath = $vid['poster_path'];
        } elseif ($newImage = handleCoverUpload($profile['slug'])) {
            blogCoverDeleteAll($post);
            if (empty($post['cover_video_path'])) {
                deleteCoverFile($post['cover_path']);
            }
            $coverPath = $newImage;
            $coverVideoPath = null;
        } elseif (isset($_POST['remove_cover'])) {
            blogCoverDeleteAll($post);
            if (empty($post['cover_video_path'])) {
                deleteCoverFile($post['cover_path']);
            }
            $coverPath = null;
            $coverVideoPath = null;
        }
    }
    if (!$error) {

        // Lo slug (e quindi la parte finale del permalink) non cambia mai in modifica, anche se
        // il titolo cambia: un link già condiviso deve continuare a funzionare.
        $stmt = getDB()->prepare('UPDATE blog_posts SET title=?, excerpt=?, content=?, cover_path=?, cover_video_path=?, album_id=?, tags=?, published_at=?, in_feed=? WHERE id=? AND user_id=?');
        $stmt->execute([$title, $excerpt, $content, $coverPath, $coverVideoPath, $albumId, $tags, $publishedAt, $inFeed, $id, $profile['id']]);
        getDB()->prepare('DELETE FROM blog_post_categories WHERE post_id=?')->execute([$id]);
        if ($categoryIds) {
            $insCat = getDB()->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (?,?)');
            foreach ($categoryIds as $cid) {
                $insCat->execute([$id, $cid]);
            }
        }
        header('Location: /dashboard_blog_posts.php');
        exit;
    }
}

include __DIR__ . '/../_dash_header.php';
require_once __DIR__ . '/_studio_blog.php';
$bc = getDB()->prepare('SELECT (SELECT COUNT(*) FROM blog_posts WHERE user_id=?) p, (SELECT COUNT(*) FROM blog_categories WHERE user_id=?) c');
$bc->execute([$profile['id'], $profile['id']]);
$bcr = $bc->fetch();
$blogCounts = ['posts' => (int) $bcr['p'], 'cats' => (int) $bcr['c'], 'tags' => count(getBlogTagCounts((int) $profile['id']))];
$tagList = array_slice(array_keys(getBlogTagCounts((int) $profile['id'])), 0, 12);
studioBlogHead('posts', $blogCounts);
?>
  <?php if (isset($_GET['tg']) && $_GET['tg'] === 'ok'): ?><div class="alert success">Inviato su Telegram!</div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <div class="tlx-listhead"><span></span><a href="<?= e(blogPostUrl($profile['slug'], $post)) ?>" target="_blank">Vedi online <i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
  <?php studioBlogForm('edit', ['profile' => $profile, 'post' => $post, 'categories' => $categories, 'albums' => $albums, 'selected' => $selectedCategoryIds, 'tagList' => $tagList]); ?>

  <div class="blx-danger">
    <div>
      <b>Elimina articolo</b>
      <p>Elimina definitivamente l'articolo e la sua copertina. Se è in coda social e non è ancora elaborato, viene tolto anche dalla coda; se è già programmato su Metricool va tolto da lì.</p>
    </div>
    <form method="post" data-confirm="Eliminare definitivamente questo articolo?">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
      <button class="stc-act danger" type="submit"><i class="fa-solid fa-trash"></i> <span>Elimina articolo</span></button>
    </form>
  </div>
  <?php if (!empty($profile['telegram_chat_id'])): ?>
  <div class="blx-danger blx-tg-box">
    <div><b>Telegram</b><p>Invia questo articolo al canale <strong><?= e($profile['telegram_chat_title'] ?: $profile['telegram_chat_id']) ?></strong>.</p></div>
    <form method="post" data-confirm="Inviare questo articolo su Telegram?">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="send_telegram"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
      <button class="stc-act" type="submit"><i class="fa-brands fa-telegram"></i> <span>Invia su Telegram</span></button>
    </form>
  </div>
  <?php endif; ?>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
