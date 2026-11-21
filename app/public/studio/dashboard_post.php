<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: la logica di salvataggio è quella della Timeline originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); // il profilo su cui si sta agendo (proprio, o co-gestito)
$activeTab = 'post';
$pageTitle = 'Timeline';

$feedError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        // Link personalizzato per il feed (ex dashboard_feed.php, ora vive qui): impostazione
        // del profilo, non del singolo post — si aggiorna comunque ogni volta che si pubblica,
        // indipendentemente dal fatto che il post stesso vada a buon fine, così cambiare o
        // svuotare il link non richiede per forza di scrivere qualcosa di nuovo.
        $customFeedGuid = trim($_POST['custom_feed_guid'] ?? '');
        if ($customFeedGuid !== '' && !filter_var($customFeedGuid, FILTER_VALIDATE_URL)) {
            $feedError = 'Il link personalizzato per il feed non è un URL valido.';
        } else {
            $customFeedGuid = $customFeedGuid ?: null;
            $customFeedGuidSince = $profile['custom_feed_guid_since'] ?? null;
            if ($customFeedGuid !== ($profile['custom_feed_guid'] ?? null)) {
                $customFeedGuidSince = $customFeedGuid ? date('Y-m-d H:i:s') : null;
            }
            $stmt = getDB()->prepare('UPDATE profiles SET custom_feed_guid=?, custom_feed_guid_since=? WHERE user_id=?');
            $stmt->execute([$customFeedGuid, $customFeedGuidSince, $profile['id']]);
            $user = currentUser();
            $profile = getActingProfile($user);
        }

        $testo = trim($_POST['testo'] ?? '');
        // Fino a 10 foto: la prima resta su image_path (quella sola che compare nel Feed, come
        // sempre), le eventuali altre (fino a 9) alimentano il carosello nella pagina di dettaglio.
        $uploadedPhotos = handleMultiCoverUpload($profile['slug'], 'images', 10);
        $imagePath = $uploadedPhotos[0] ?? null;
        $extraPhotos = array_slice($uploadedPhotos, 1);

        // Miniatura leggera per la lista/feed, generata nel browser (canvas) al momento della
        // selezione del file — vedi commento analogo per il ritaglio avatar in
        // dashboard_profile.php: niente elaborazione lato server, non serve GD/Imagick. La foto
        // originale caricata sopra resta intatta ed è quella mostrata aprendo il post.
        $imageThumbPath = null;
        if ($imagePath) {
            $thumbData = $_POST['image_thumb_data'] ?? '';
            if ($thumbData !== '' && preg_match('#^data:image/jpeg;base64,#', $thumbData)) {
                $raw = base64_decode(substr($thumbData, strpos($thumbData, ',') + 1), true);
                if ($raw !== false && strlen($raw) > 0 && strlen($raw) < 2 * 1024 * 1024) {
                    $fname = 'thumb_' . bin2hex(random_bytes(6)) . '.jpg';
                    $dir = __DIR__ . '/../uploads/images/' . $profile['slug'];
                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                    if (file_put_contents($dir . '/' . $fname, $raw) !== false) {
                        $imageThumbPath = 'uploads/images/' . $profile['slug'] . '/' . $fname;
                    }
                }
            }
        }

        $visibility = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $inFeed = !empty($_POST['in_feed']) ? 1 : 0;
        // Interpretato nel fuso orario scelto dal profilo (Dashboard -> Profilo e anagrafica),
        // non in quello del server — vedi parseLocalDateTime() in functions.php.
        $publishAt = parseLocalDateTime($_POST['publish_at'] ?? '', $profile, browserTzOffsetFromRequest());
        if ($publishAt && strtotime($publishAt) <= time()) {
            $publishAt = null;
        }

        if ($testo === '' && !$imagePath) {
            $error = 'Scrivi qualcosa o allega una foto.';
        } else {
            $stmt = getDB()->prepare('INSERT INTO timeline_posts (user_id, testo, image_path, image_thumb_path, visibility, in_feed, publish_at) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$profile['id'], $testo ?: null, $imagePath, $imageThumbPath, $visibility, $inFeed, $publishAt]);
            $newPostId = (int) getDB()->lastInsertId();
            setContentAuthor('timeline_posts', $newPostId, dashboardAuthorSignature($user));

            if ($extraPhotos) {
                $insPhoto = getDB()->prepare('INSERT INTO timeline_post_photos (post_id, image_path, sort_order) VALUES (?,?,?)');
                foreach ($extraPhotos as $photoIndex => $photoPath) {
                    $insPhoto->execute([$newPostId, $photoPath, $photoIndex]);
                }
            }

            logAdminAction((int) $profile['id'], (int) $user['id'], 'Nuovo aggiornamento in Timeline', $testo !== '' ? textExcerpt($testo, 60) : 'Foto pubblicata');

            if ($visibility === 'public' && !$publishAt) {
                $anteprima = $testo !== '' ? textExcerpt($testo, 80) : 'Nuova foto pubblicata';
                $timelineUrl = siteUrl('/' . $profile['slug'] . '/timeline');
                notifyFollowersNewContent((int) $profile['id'], $profile['display_name'], $profile['slug'], 'timeline', $anteprima, $timelineUrl);

                require_once __DIR__ . '/../../src/telegram.php';
                telegramAutoPublishIfEnabled((int) $profile['id'], 'post', [
                    'body' => $testo,
                    'image_url' => $imagePath,
                    'public_url' => $timelineUrl,
                ]);

                require_once __DIR__ . '/../../src/metricool_queue.php';
                socialQueueEnqueueIfEnabled((int) $profile['id'], 'timeline', [
                    'content_id' => $newPostId,
                    'body'       => $testo,
                    'image_url'  => $imagePath ? siteUrl($imagePath) : null,
                    'public_url' => $timelineUrl,
                ]);
            }
            // Pubblicazione programmata: l'uscita social si accoda subito con la data prevista (prima
            // restava fuori dalla coda e non veniva mai pubblicata sui social).
            if ($visibility === 'public' && $publishAt) {
                require_once __DIR__ . '/../../src/metricool_queue.php';
                socialQueueEnqueueIfEnabled((int) $profile['id'], 'timeline', [
                    'content_id' => $newPostId,
                    'body'       => $testo,
                    'image_url'  => $imagePath ? siteUrl($imagePath) : null,
                    'public_url' => siteUrl('/' . $profile['slug'] . '/timeline'),
                ], $publishAt);
            }
        }
    }
    // Modifica, eliminazione e gestione foto di un post già esistente vivono ora in una pagina
    // dedicata (dashboard_timeline_edit.php) — vedi il link "✏️ Modifica" nell'elenco qui sotto.
}

$stmt = getDB()->prepare('SELECT * FROM timeline_posts WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$profile['id']]);
$posts = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';

// ---- Dati per la vista (solo lettura) ----
$tlTotal = count($posts); $tlSched = 0; $tlPriv = 0;
$tlExtra = [];
$tlIds = array_map(function ($p) { return (int) $p['id']; }, $posts);
if ($tlIds) {
    $ph = implode(',', array_fill(0, count($tlIds), '?'));
    $q = getDB()->prepare("SELECT post_id, COUNT(*) c FROM timeline_post_photos WHERE post_id IN ($ph) GROUP BY post_id");
    $q->execute($tlIds);
    foreach ($q->fetchAll() as $r) { $tlExtra[(int) $r['post_id']] = (int) $r['c']; }
}
$tlGroups = [];
$today = formatLocalDateTime(date('Y-m-d H:i:s'), $profile, 'd/m/Y');
$yest = formatLocalDateTime(date('Y-m-d H:i:s', time() - 86400), $profile, 'd/m/Y');
foreach ($posts as $p) {
    $sched = $p['publish_at'] && strtotime($p['publish_at']) > time();
    $priv = $p['visibility'] === 'private';
    if ($sched) { $tlSched++; }
    if ($priv) { $tlPriv++; }
    $d = formatLocalDateTime($p['created_at'], $profile, 'd/m/Y');
    $label = $d === $today ? 'Oggi' : ($d === $yest ? 'Ieri' : $d);
    $tlGroups[$label][] = $p;
}
$tlName = trim((string) ($profile['display_name'] ?? ''));
$tlFirst = $tlName !== '' ? strtok($tlName, ' ') : '';
$tlAvatar = !empty($profile['avatar_path']) ? '/' . ltrim($profile['avatar_path'], '/') : '';
$tlUrl = siteUrl('/' . $profile['slug'] . '/timeline');
?>

  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($feedError)): ?><div class="alert error"><?= e($feedError) ?></div><?php endif; ?>

  <div class="tlx" data-name="<?= e($tlName) ?>" data-avatar="<?= e($tlAvatar) ?>">
    <div class="tlx-top">
      <div class="tlx-kpis">
        <div class="tlx-kpi"><b><?= $tlTotal ?></b><span>Aggiornamenti</span></div>
        <div class="tlx-kpi"><b><?= $tlSched ?></b><span>Programmati</span></div>
        <div class="tlx-kpi"><b><?= $tlPriv ?></b><span>Solo io</span></div>
        <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>/timeline" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>La tua Timeline</span></a>
      </div>
    </div>

    <div class="tlx-grid">
      <form method="post" enctype="multipart/form-data" class="tlx-compose" id="tlx-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="tz_offset_minutes" value="">
        <input type="hidden" name="image_thumb_data" id="post-image-thumb-data">

        <div class="tlx-who">
          <span class="tlx-av"><?php if ($tlAvatar): ?><img src="<?= e($tlAvatar) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($tlName ?: 'C', 0, 1))) ?><?php endif; ?></span>
          <div><b>Cosa vuoi condividere<?= $tlFirst ? ', ' . e($tlFirst) : '' ?>?</b><small>Testo, foto o entrambi</small></div>
        </div>

        <div class="tlx-field">
          <textarea name="testo" id="ai-testo" rows="4" maxlength="2000" placeholder="Scrivilo qui..."></textarea>
          <span class="tlx-count" id="tlx-count">0</span>
        </div>

        <div id="tlx-photos" class="tlx-photos" hidden></div>
        <label class="tlx-drop" id="tlx-drop" for="post-image-input">
          <i class="fa-regular fa-images"></i>
          <span><b>Aggiungi foto</b> o trascinale qui<br><small>Fino a 10. La prima è la copertina nel Feed, le altre formano il carosello.</small></span>
          <input type="file" name="images[]" id="post-image-input" accept="image/*" multiple>
        </label>

        <div class="tlx-tools">
          <button type="button" class="tlx-chip" id="ai-caption-toggle"><i class="fa-solid fa-wand-magic-sparkles"></i> Scrivi con l'AI</button>
        </div>
        <div id="ai-caption-panel" class="tlx-ai" hidden>
          <label for="ai-caption-keywords">Parole chiave o istruzioni per l'AI</label>
          <textarea id="ai-caption-keywords" rows="2" placeholder="es. annuncio nuovo concerto sabato 14 a Milano"></textarea>
          <div class="tlx-row">
            <button type="button" class="btn small" id="ai-caption-generate">Genera testo</button>
            <button type="button" class="btn small secondary" id="ai-caption-cancel">Annulla</button>
          </div>
          <p id="ai-caption-status" class="tlx-hint"></p>
        </div>

        <div class="tlx-opts">
          <div class="tlx-opt">
            <span class="tlx-lbl">Chi lo vede</span>
            <div class="tlx-seg" role="radiogroup">
              <label><input type="radio" name="visibility" value="public" checked><span><i class="fa-solid fa-earth-europe"></i> Pubblico</span></label>
              <label><input type="radio" name="visibility" value="private"><span><i class="fa-solid fa-lock"></i> Solo io</span></label>
            </div>
          </div>
          <div class="tlx-opt">
            <span class="tlx-lbl">Quando</span>
            <div class="tlx-seg" role="radiogroup">
              <label><input type="radio" name="when" value="now" checked><span><i class="fa-solid fa-bolt"></i> Subito</span></label>
              <label><input type="radio" name="when" value="later"><span><i class="fa-regular fa-clock"></i> Programma</span></label>
            </div>
          </div>
          <label class="tlx-switch"><input type="checkbox" name="in_feed" value="1" checked><i></i><span>Mostra nel Feed</span></label>
        </div>

        <div class="tlx-when" id="tlx-when" hidden>
          <label for="tlx-publish-at">Data e ora di pubblicazione</label>
          <input type="datetime-local" name="publish_at" id="tlx-publish-at">
          <p class="tlx-hint">Se la lasci vuota, pubblica subito.</p>
        </div>

        <details class="tlx-adv">
          <summary>Opzioni avanzate</summary>
          <label for="tlx-guid">Link personalizzato per il feed</label>
          <input type="url" name="custom_feed_guid" id="tlx-guid" value="<?= e($profile['custom_feed_guid'] ?? '') ?>" placeholder="https://...">
          <p class="tlx-hint"><?php if (!empty($profile['custom_feed_guid_since'])): ?>Attivo dal <?= e(formatLocalDateTime($profile['custom_feed_guid_since'], $profile)) ?>.<?php else: ?>Lascia vuoto per usare sempre la pagina normale.<?php endif; ?></p>
        </details>

        <button type="submit" class="tlx-submit" id="tlx-submit"><i class="fa-solid fa-paper-plane"></i> <span>Pubblica</span></button>
      </form>

      <aside class="tlx-side" aria-label="Anteprima">
        <div class="tlx-lbl">Anteprima nel Feed</div>
        <article class="stc stc-card tlx-prev" id="tlx-prev">
          <div class="stc-thumb" id="tlx-prev-thumb"><span class="stc-ph">T</span></div>
          <div class="stc-body">
            <div class="stc-meta1"><span class="stc-type stc-violet">Aggiornamento</span><span class="stc-status stc-s-ok" id="tlx-prev-status"><i class="fa-solid fa-circle-check"></i> Pubblicato</span></div>
            <h3 class="stc-title" id="tlx-prev-text">Il tuo testo comparirà qui</h3>
            <div class="stc-meta2" id="tlx-prev-meta"><?= e($tlName) ?> · adesso</div>
          </div>
        </article>
        <p class="tlx-hint">Così lo vedranno i tuoi follower. L'anteprima si aggiorna mentre scrivi.</p>
        <a class="tlx-feedlink" href="/<?= e($profile['slug']) ?>/feed" target="_blank"><i class="fa-solid fa-rss"></i> Il tuo feed: <?= e(siteName()) ?>/<?= e($profile['slug']) ?>/feed</a>
      </aside>
    </div>

    <section class="tlx-list" aria-label="I tuoi aggiornamenti">
      <div class="tlx-listhead">
        <h2>I tuoi aggiornamenti</h2>
        <div class="tlx-filters" id="tlx-filters" role="tablist">
          <button type="button" class="on" data-f="all">Tutti</button>
          <button type="button" data-f="public">Pubblicati</button>
          <button type="button" data-f="scheduled">Programmati</button>
          <button type="button" data-f="private">Solo io</button>
        </div>
      </div>

      <?php if (!$posts): ?>
        <div class="tlx-empty"><i class="fa-regular fa-comment-dots"></i><b>Ancora nulla qui</b><span>Scrivi il tuo primo aggiornamento: comparirà in questa linea del tempo.</span></div>
      <?php endif; ?>

      <div class="tlx-line" id="tl-posts-list">
      <?php foreach ($tlGroups as $label => $items): ?>
        <div class="tlx-day"><span><?= e($label) ?></span></div>
        <?php foreach ($items as $p):
          $isScheduled = $p['publish_at'] && strtotime($p['publish_at']) > time();
          $isPrivate = $p['visibility'] === 'private';
          $state = $isScheduled ? 'scheduled' : ($isPrivate ? 'private' : 'public');
          $extra = $tlExtra[(int) $p['id']] ?? 0;
          $img = $p['image_thumb_path'] ?: $p['image_path'];
          $pub = $tlUrl . '/' . (int) $p['id'];
          $share = json_encode(['t' => $p['title'] ?: textExcerpt((string) $p['testo'], 60), 'u' => $pub], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
        <article class="tlx-item tlx-s-<?= $state ?>" data-tl-post="<?= (int) $p['id'] ?>" data-state="<?= $state ?>">
          <span class="tlx-dot"></span>
          <?php if ($img): ?><a class="tlx-pic" href="/dashboard_timeline_edit.php?id=<?= (int) $p['id'] ?>"><img src="/<?= e($img) ?>" alt="" loading="lazy"><?php if ($extra): ?><em>+<?= $extra ?></em><?php endif; ?></a><?php endif; ?>
          <div class="tlx-body">
            <div class="tlx-badges">
              <?php if ($isScheduled): ?><span class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> Programmato · <?= e(formatLocalDateTime($p['publish_at'], $profile)) ?></span>
              <?php elseif ($isPrivate): ?><span class="stc-status stc-s-grey"><i class="fa-solid fa-lock"></i> Solo io</span>
              <?php else: ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Pubblicato</span><?php endif; ?>
              <?php if (!$isPrivate && !(int) ($p['in_feed'] ?? 1)): ?><span class="tlx-tag">Fuori dal Feed</span><?php endif; ?>
              <?php if (($p['source'] ?? 'dashboard') === 'api'): ?><span class="tlx-tag">API</span><?php endif; ?>
              <?= authorBadgeHtml($p['created_by'] ?? null) ?>
              <time><?= e(formatLocalDateTime($p['created_at'], $profile, 'H:i')) ?></time>
            </div>
            <?php if (!empty($p['title'])): ?><p class="tlx-title"><?= e($p['title']) ?></p><?php endif; ?>
            <?php if ($p['testo']): ?><p class="tlx-text"><?= nl2br(e($p['testo'])) ?></p><?php endif; ?>
            <?php if (!empty($p['hashtags'])): ?><p class="tlx-tags"><?= e($p['hashtags']) ?></p><?php endif; ?>
            <?php if (!empty($p['call_to_action'])): ?><p class="tlx-cta"><?= e($p['call_to_action']) ?></p><?php endif; ?>
            <?php if (!empty($p['redirect_link'])): ?><p class="tlx-hint"><i class="fa-solid fa-link"></i> Reindirizza a: <?= e($p['redirect_link']) ?></p><?php endif; ?>
            <div class="stc-actions">
              <a class="stc-act primary" href="/dashboard_timeline_edit.php?id=<?= (int) $p['id'] ?>"><i class="fa-solid fa-pen"></i> <span>Modifica</span></a>
              <?php if (!$isPrivate): ?>
                <a class="stc-act" href="/<?= e($profile['slug']) ?>/timeline/<?= (int) $p['id'] ?>" target="_blank" title="Pagina pubblica"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Pagina pubblica</span></a>
                <button type="button" class="stc-act" data-share='<?= e($share) ?>' title="Condividi"><i class="fa-solid fa-share-nodes"></i> <span>Condividi</span></button>
              <?php endif; ?>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </div>
    </section>
  </div>

  <script src="/studio/assets/studio-timeline.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-timeline.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
