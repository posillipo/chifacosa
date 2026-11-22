<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'blog';
$pageTitle = 'Nuovo articolo';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $albumId = (int) ($_POST['album_id'] ?? 0) ?: null;
    $tagsRaw = trim($_POST['tags'] ?? '');
    $tags = $tagsRaw !== '' ? implode(', ', array_filter(array_map('trim', explode(',', $tagsRaw)), fn ($t) => $t !== '')) : null;
    $tags = $tags !== '' ? $tags : null;
    $categoryIds = array_filter(array_map('intval', $_POST['category_ids'] ?? []));
    $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
    // Stesso pattern di dashboard_albums.php: interpretata nel fuso orario reale di chi sta
    // scrivendo in questo momento (offset del browser), campo vuoto = pubblica subito.
    $publishedAt = parseLocalDateTime($_POST['published_at'] ?? '', $profile, browserTzOffsetFromRequest()) ?: date('Y-m-d H:i:s');

    if ($title === '' || $content === '') {
        $error = 'Titolo e contenuto sono obbligatori.';
    } else {
        // Verifica che l'album e le categorie appartengano davvero a questo utente, per evitare
        // che un ID arbitrario nel form li associ a roba di qualcun altro.
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
        // Copertina: immagine OPPURE video (se arrivano entrambi vince il video).
        $coverPath = null;
        $coverVideoPath = null;
        $vid = blogCoverVideoFromUpload($profile['slug']);
        if ($vid !== null && isset($vid['error'])) {
            $error = $vid['error'];
        } elseif ($vid !== null) {
            $coverVideoPath = $vid['video_path'];
            $coverPath = $vid['poster_path'];
        } else {
            $coverPath = handleCoverUpload($profile['slug']);
        }
    }
    if (!$error) {
        $slug = generateUniquePostSlug((int) $profile['id'], $title);
        $stmt = getDB()->prepare('INSERT INTO blog_posts (user_id, title, slug, excerpt, content, cover_path, cover_video_path, album_id, tags, published_at, in_feed, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$profile['id'], $title, $slug, $excerpt, $content, $coverPath, $coverVideoPath, $albumId, $tags, $publishedAt, $inFeed, 'Dashboard · ' . mb_substr((string) ($user['display_name'] ?: $user['slug']), 0, 100)]);
        $newId = (int) getDB()->lastInsertId();
        if ($categoryIds) {
            $insCat = getDB()->prepare('INSERT INTO blog_post_categories (post_id, category_id) VALUES (?,?)');
            foreach ($categoryIds as $cid) {
                $insCat->execute([$newId, $cid]);
            }
        }
        if (strtotime($publishedAt) <= time()) {
            $postUrl = siteUrl(blogPostUrl($profile['slug'], ['published_at' => $publishedAt, 'slug' => $slug]));
            notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'blog', $title, $postUrl);

            require_once __DIR__ . '/../../src/telegram.php';
            telegramAutoPublishIfEnabled((int) $profile['id'], 'blog', [
                'title' => $title,
                'body' => $content,
                'image_url' => $coverPath,
                'public_url' => $postUrl,
            ]);

            require_once __DIR__ . '/../../src/metricool_queue.php';
            socialQueueEnqueueIfEnabled((int) $profile['id'], 'blog', [
                'content_id' => $newId,
                'title'      => $title,
                'body'       => $content,
                'image_url'  => $coverPath ? siteUrl($coverPath) : null,
                'public_url' => $postUrl,
            ]);
        } else {
            // Articolo programmato: si accoda subito con la data di pubblicazione.
            $postUrl = siteUrl(blogPostUrl($profile['slug'], ['published_at' => $publishedAt, 'slug' => $slug]));
            require_once __DIR__ . '/../../src/metricool_queue.php';
            socialQueueEnqueueIfEnabled((int) $profile['id'], 'blog', [
                'content_id' => $newId,
                'title'      => $title,
                'body'       => $content,
                'image_url'  => $coverPath ? siteUrl($coverPath) : null,
                'public_url' => $postUrl,
            ], $publishedAt);
        }
        header('Location: /dashboard_blog_posts.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM blog_categories WHERE user_id=? ORDER BY name ASC');
$stmt->execute([$profile['id']]);
$categories = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT id, title FROM photo_albums WHERE user_id=? ORDER BY sort_order DESC');
$stmt->execute([$profile['id']]);
$albums = $stmt->fetchAll();

// Ultimi articoli già inseriti (colonna destra): solo di questo profilo, i più recenti per primi.
$stmt = getDB()->prepare('SELECT id, title, published_at, cover_path, excerpt, content, slug FROM blog_posts WHERE user_id=? ORDER BY published_at DESC, id DESC LIMIT 15');
$stmt->execute([$profile['id']]);
$recentPosts = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
require_once __DIR__ . '/_studio_blog.php';
$bc = getDB()->prepare('SELECT (SELECT COUNT(*) FROM blog_posts WHERE user_id=?) p, (SELECT COUNT(*) FROM blog_categories WHERE user_id=?) c');
$bc->execute([$profile['id'], $profile['id']]);
$bcr = $bc->fetch();
$blogCounts = ['posts' => (int) $bcr['p'], 'cats' => (int) $bcr['c'], 'tags' => count(getBlogTagCounts((int) $profile['id']))];
$tagList = array_slice(array_keys(getBlogTagCounts((int) $profile['id'])), 0, 12);
studioBlogHead('posts', $blogCounts);
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php studioBlogForm('new', ['profile' => $profile, 'post' => ['title' => $_POST['title'] ?? '', 'content' => $_POST['content'] ?? '', 'tags' => $_POST['tags'] ?? ''], 'categories' => $categories, 'albums' => $albums, 'selected' => array_filter(array_map('intval', $_POST['category_ids'] ?? [])), 'tagList' => $tagList]); ?>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
