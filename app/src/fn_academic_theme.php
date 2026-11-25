<?php
// Tema "Academic": veste pubblica ispirata ad Academic Pages (https://github.com/academicpages/academicpages.github.io,
// a sua volta derivato da Minimal Mistakes — licenza MIT, © 2016 Michael Rose; avviso in assets/css/academic-LICENSE.txt).
// Non usa il codice Jekyll: ne riprende l'impostazione grafica (barra in alto con testo, profilo dell'autore a sinistra,
// pagina bianca con titoli a filetto, elenco di voci, tema chiaro/scuro) con stile e script propri.
//
// Come funziona:
//  - La HOME (/slug) ha una scena propria: renderAcademicProfileTheme().
//  - Le ALTRE pagine pubbliche (Timeline, Blog, Eventi, Foto, Video, Servizi, Offerte, Menù, Contatti, Contest, Spotify,
//    Podcast, YouTube) riusano la stessa logica delle pagine del tema AdminLTE (stessi dati, moduli e script) con una
//    veste Academic: quando il tema è «academic» le parti comuni (barra in alto, profilo a sinistra, piè di pagina,
//    risorse CSS) vengono sostituite da quelle di questo file. Le pagine «Che Amo» restano fuori e usano lo scheletro
//    condiviso.

/** Flag di richiesta: la pagina in costruzione usa la veste Academic. */
function academicSkinActive(?bool $set = null): bool {
    static $on = false;
    if ($set === true && !$on) {
        // Il filtro di fine pagina inserisce nel «segnaposto» della colonna sinistra le schede che le pagine
        // costruiscono più avanti (es. le categorie del blog): vedi academicSidebarSlot().
        ob_start('academicSlotFilter');
    }
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

/** Schede da mostrare nella colonna sinistra, sotto il profilo (riempita dalle pagine mentre si costruiscono). */
function academicSidebarSlot(?string $add = null): string {
    static $html = '';
    if ($add !== null) {
        $html .= $add;
    }
    return $html;
}

/** Scheda testuale (senza immagini) per la colonna sinistra: elenco di voci [url, titolo, data] con filetti sottili. */
function academicSideListCard(string $title, array $items, string $moreUrl = '', string $moreLabel = ''): string {
    if (!$items) {
        return '';
    }
    $h = '<div class="author__list"><h3 class="author__list-title">' . e($title) . '</h3><ul>';
    foreach ($items as $it) {
        $h .= '<li><a href="' . e($it['url']) . '"' . (!empty($it['active']) ? ' class="is-active" aria-current="page"' : '') . '>' . e(textExcerpt((string) $it['title'], 70)) . '</a>'
            . (!empty($it['date']) ? '<small>' . e($it['date']) . '</small>' : '') . '</li>';
    }
    $h .= '</ul>';
    if ($moreUrl !== '') {
        $h .= '<a class="author__list-more" href="' . e($moreUrl) . '">' . e($moreLabel) . '</a>';
    }
    return $h . '</div>';
}

/** Filtro di uscita: sostituisce il segnaposto della colonna sinistra con le schede raccolte. */
function academicSlotFilter(string $buffer): string {
    return str_replace('<!--ACAD-SLOT-->', academicSidebarSlot(), $buffer);
}

/**
 * True se il profilo usa un tema della «famiglia AdminLTE» (AdminLTE o Academic): le pagine pubbliche usano questa
 * funzione al posto del confronto con 'adminlte-profile'. Con «academic» attiva anche la veste Academic.
 */
function adminLteFamilyTheme(array $artist): bool {
    $t = $artist['page_theme'] ?? 'colorful';
    if ($t === 'academic') {
        academicSkinActive(true);
        return true;
    }
    return $t === 'adminlte-profile';
}

/** Voci della barra in alto (chiave => [etichetta, indirizzo]): stesse condizioni di adminLteTopNav(). */
function academicNavItems(array $artist, string $slug): array {
    $uid = (int) $artist['id'];
    $isBandOrLabel = in_array($artist['account_type'] ?? 'band', ['band', 'label'], true);
    $hidden = getHiddenNavKeys($uid);
    // La Home è la timeline: niente voce «Timeline» a parte.
    $items = [];
    if (!in_array('cheamo', $hidden, true) && hasAnyVisibleCheAmo($uid, $hidden)) {
        $items['cheamo'] = ['Che Amo', '/' . $slug . '/che-amo'];
    }
    if ($isBandOrLabel && !empty($artist['spotify_artist_id'])) {
        $items['spotify'] = ['Spotify', '/' . $slug . '/spotify'];
    }
    if ($isBandOrLabel && !empty($artist['spotify_show_id'])) {
        $items['podcast'] = ['Podcast', '/' . $slug . '/podcast'];
    }
    if ($isBandOrLabel && !empty($artist['youtube_channel_id'])) {
        $items['youtube'] = ['YouTube', '/' . $slug . '/youtube'];
    }
    if (!in_array('video', $hidden, true) && hasPublicVideos($uid)) {
        $items['video'] = ['Video', '/' . $slug . '/video'];
    }
    if (!in_array('contest', $hidden, true) && hasPublicContests($uid)) {
        $items['contest'] = ['Contest', '/' . $slug . '/contest'];
    }
    $items['blog'] = ['Blog', '/' . $slug . '/blog'];
    if (!in_array('menu', $hidden, true) && menuHasItems($uid)) {
        $items['menu'] = ['Menù', '/' . $slug . '/menu'];
    }
    if (!in_array('offerte', $hidden, true) && hasActiveOffers($uid)) {
        $items['offerte'] = ['Offerte', '/' . $slug . '/offerte'];
    }
    if (!in_array('foto', $hidden, true) && hasPublicPhotoContent($uid)) {
        $items['foto'] = ['Foto', '/' . $slug . '/foto'];
    }
    if (!in_array('servizi', $hidden, true) && hasVisibleServices($uid)) {
        $items['servizi'] = ['Servizi', '/' . $slug . '/servizi'];
    }
    if ($isBandOrLabel && !in_array('eventi', $hidden, true)) {
        $items['eventi'] = ['Eventi', '/' . $slug . '/eventi'];
    }
    if (!in_array('link', $hidden, true) && profileHasActiveLinks($uid)) {
        $items['link'] = ['Link', '/' . $slug . '/link'];
    }
    $items['contatti'] = ['Contatti', '/' . $slug . '/contatti'];
    return $items;
}

/** Risorse nell'<head>: stile, script, tema chiaro/scuro prima del primo disegno (niente lampo). */
function academicHeadAssets(bool $withFontAwesome = true): string {
    $cssV = @filemtime(__DIR__ . '/../public/assets/css/academic.css') ?: 1;
    $jsV = @filemtime(__DIR__ . '/../public/assets/js/academic.js') ?: 1;
    return ($withFontAwesome ? '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">' . "\n" : '')
        . '<link rel="stylesheet" href="/assets/css/academic.css?v=' . (int) $cssV . '">' . "\n"
        . '<script>(function(){var r=document.documentElement;r.classList.add("acad-skin");try{var t=localStorage.getItem("acadTheme");if(t==="dark"||(!t&&window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches)){r.setAttribute("data-theme","dark");r.setAttribute("data-bs-theme","dark");}}catch(e){}})();</script>' . "\n"
        . '<script src="/assets/js/academic.js?v=' . (int) $jsV . '" defer></script>' . "\n";
}

/** Barra in alto: nome del profilo, sezioni, accesso/dashboard, tema chiaro/scuro. $activeKey: 'home' o la chiave di una voce. */
function academicMastheadHtml(array $artist, string $slug, string $activeKey = 'home'): string {
    $name = (string) $artist['display_name'];
    $nav = academicNavItems($artist, $slug);
    ob_start();
    ?>
<div class="masthead">
  <div class="masthead__inner-wrap">
    <a class="masthead__title<?= in_array($activeKey, ['home', 'timeline'], true) ? ' selected' : '' ?>" href="/<?= e($slug) ?>"><?= e($name) ?></a>
    <nav class="masthead__menu" id="acad-nav" aria-label="Navigazione principale">
      <ul class="visible-links">
        <?php foreach ($nav as $key => [$label, $url]): ?>
          <li class="masthead__menu-item<?= $activeKey === $key ? ' selected' : '' ?>"><a href="<?= e($url) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <li class="masthead__menu-item">
          <?php if (empty($_SESSION['user_id'])): ?><a href="/login.php">Accedi</a><?php else: ?><a href="/dashboard.php">Dashboard</a><?php endif; ?>
        </li>
      </ul>
      <ul class="hidden-links" hidden></ul>
    </nav>
    <button type="button" id="acad-theme" class="acad-btn-icon" aria-label="Cambia tema chiaro/scuro" title="Tema chiaro/scuro"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></button>
    <button type="button" class="acad-burger" id="acad-burger" aria-label="Altre voci del menu" aria-controls="acad-nav" aria-expanded="false" hidden><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
  </div>
</div>
<?php
    return ob_get_clean();
}

/** Link attivi del profilo (solo collegamenti veri), i primi $limit. */
function academicProfileLinks(int $uid, int $limit = 12): array {
    $out = [];
    try {
        $ls = getDB()->prepare('SELECT label, url, type FROM links WHERE user_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT ' . (int) $limit);
        $ls->execute([$uid]);
        foreach ($ls->fetchAll() as $l) {
            if (($l['type'] ?? 'link') === 'link' && preg_match('#^(https?://|mailto:)#i', (string) $l['url'])) {
                $out[] = $l;
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Profilo dell'autore (foto, nome, bio, luogo, genere, link, Segui): il contenuto della colonna sinistra. */
function academicSidebarHtml(array $artist, string $slug, string $extraTopHtml = ''): string {
    $uid = (int) $artist['id'];
    $name = (string) $artist['display_name'];
    $avatarUrl = adminLteAvatarUrl($artist);
    $place = '';
    $genre = '';
    try {
        $st = getDB()->prepare('SELECT citta, provincia, genere FROM profiles WHERE user_id = ?');
        $st->execute([$uid]);
        if ($r = $st->fetch()) {
            $place = trim((string) $r['citta']) !== '' ? trim((string) $r['citta']) . (trim((string) $r['provincia']) !== '' ? ' (' . strtoupper(trim((string) $r['provincia'])) . ')' : '') : '';
            $genre = trim((string) ($r['genere'] ?? ''));
        }
    } catch (Throwable $e) {
    }
    $links = academicProfileLinks($uid);
    $viewerId = publicActingUserId();
    $isOwn = $viewerId && (int) $viewerId === $uid;
    $isFollowing = ($viewerId && !$isOwn) ? isFollowingAccount((int) $viewerId, $uid) : false;
    $showFollow = !in_array('segui', getHiddenNavKeys($uid), true);
    ob_start();
    ?>
  <aside class="sidebar">
    <div class="author__avatar"><a href="/<?= e($slug) ?>"><img src="<?= e($avatarUrl) ?>" alt="<?= e($name) ?>"></a></div>
    <div class="author__content">
      <h3 class="author__name"><a href="/<?= e($slug) ?>"><?= e($name) ?></a></h3>
      <?php if (!empty($artist['bio'])): ?><p class="author__bio"><?= nl2br(e($artist['bio'])) ?></p><?php endif; ?>
    </div>
    <div class="author__urls-wrapper">
    <button type="button" class="btn btn--inverse" id="acad-info" aria-expanded="false" aria-controls="acad-urls">Info</button>
    <div class="author__panel" id="acad-urls">
    <ul class="author__urls">
      <?php if ($place !== ''): ?><li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= e($place) ?></li><?php endif; ?>
      <?php if ($genre !== ''): ?><li><i class="fa-solid fa-music" aria-hidden="true"></i> <?= e($genre) ?></li><?php endif; ?>
      <?php foreach ($links as $l): ?>
        <li><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener nofollow"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> <?= e($l['label'] ?: $l['url']) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <div class="author__follow">
      <?php if ($isOwn): ?>
        <a href="/dashboard.php" class="btn btn--primary"><i class="fa-solid fa-gauge" aria-hidden="true"></i> Dashboard</a>
      <?php elseif ($showFollow && !empty($_SESSION['user_id'])): ?>
        <form method="post" action="/follow_account.php">
          <?= csrfField() ?>
          <input type="hidden" name="user_id" value="<?= $uid ?>">
          <input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>">
          <input type="hidden" name="redirect" value="/<?= e($slug) ?>">
          <button type="submit" class="btn btn--primary"><i class="fa-solid <?= $isFollowing ? 'fa-user-check' : 'fa-user-plus' ?>" aria-hidden="true"></i> <?= $isFollowing ? 'Già seguito' : 'Segui' ?></button>
        </form>
      <?php elseif ($showFollow): ?>
        <a href="/login.php" class="btn btn--primary"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Segui</a>
      <?php endif; ?>
    </div>
    </div>
    </div>
    <div class="author__slot"><!--ACAD-SLOT--></div>
    <?php if ($extraTopHtml !== ''): ?><div class="author__extra"><?= $extraTopHtml ?></div><?php endif; ?>
  </aside>
<?php
    return ob_get_clean();
}

/** Tempo di lettura stimato (200 parole al minuto), in minuti, almeno 1. */
function academicReadMinutes(string $text): int {
    $words = preg_match_all('/\p{L}[\p{L}\p{N}\'’-]*/u', $text);
    return max(1, (int) ceil(($words ?: 0) / 200));
}

/**
 * Pagina articolo come nel template (posts/…/blog-post): titolo, riga «tempo di lettura · data», testo, tag,
 * «Condividi su», «Precedente / Successivo» e «Ti potrebbe interessare» con le schede degli altri articoli.
 */
function academicBlogPostHtml(array $post, array $artist, string $slug, string $permalink, array $cats): string {
    $db = getDB();
    $uid = (int) $post['user_id'];
    $min = academicReadMinutes((string) $post['content']);
    $date = formatItalianDate($post['published_at'], $artist);
    $tags = array_filter(array_map('trim', explode(',', (string) ($post['tags'] ?? ''))));
    $q = static function (string $cmp, string $order) use ($db, $uid, $post) {
        $st = $db->prepare('SELECT id, title, slug, published_at FROM blog_posts WHERE user_id=? AND id<>? AND published_at ' . $cmp . ' ? AND published_at <= NOW() ORDER BY published_at ' . $order . ' LIMIT 1');
        $st->execute([$uid, (int) $post['id'], $post['published_at']]);
        return $st->fetch() ?: null;
    };
    $prev = $q('<=', 'DESC');
    $next = $q('>', 'ASC');
    $st = $db->prepare('SELECT id, title, slug, excerpt, content, published_at FROM blog_posts WHERE user_id=? AND id<>? AND published_at <= NOW() ORDER BY published_at DESC LIMIT 4');
    $st->execute([$uid, (int) $post['id']]);
    $related = $st->fetchAll();
    $u = rawurlencode($permalink);
    $t = rawurlencode((string) $post['title']);
    $share = [
        ['Bluesky', 'fa-brands fa-bluesky', 'https://bsky.app/intent/compose?text=' . $t . '%20' . $u],
        ['Facebook', 'fa-brands fa-facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $u],
        ['LinkedIn', 'fa-brands fa-linkedin', 'https://www.linkedin.com/shareArticle?mini=true&url=' . $u . '&title=' . $t],
        ['Mastodon', 'fa-brands fa-mastodon', 'https://mastodonshare.com/?text=' . $t . '&url=' . $u],
        ['X', 'fa-brands fa-x-twitter', 'https://twitter.com/intent/tweet?text=' . $t . '&url=' . $u],
    ];
    ob_start();
    ?>
<article class="page acad-post">
  <h1 class="page__title"><?= e($post['title']) ?></h1>
  <p class="page__meta"><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= $min === 1 ? 'meno di 1 minuto' : $min . ' minuti' ?> di lettura &nbsp;&nbsp; <i class="fa-regular fa-calendar" aria-hidden="true"></i> Pubblicato: <?= e($date) ?></p>
  <?php if (!empty($post['cover_video_path'])): ?>
    <video src="/<?= e($post['cover_video_path']) ?>" <?= $post['cover_path'] ? 'poster="/' . e($post['cover_path']) . '"' : '' ?> controls playsinline preload="metadata" class="acad-post__cover"></video>
  <?php elseif ($post['cover_path']): ?>
    <img src="/<?= e($post['cover_path']) ?>" alt="<?= e($post['title']) ?>" class="acad-post__cover">
  <?php endif; ?>
  <section class="page__content"><?= nl2br(e($post['content'])) ?></section>
  <footer class="page__meta">
    <?php if ($cats || $tags): ?>
    <p class="page__taxonomy"><strong><i class="fa-solid fa-tags" aria-hidden="true"></i> Tag:</strong>
      <?php foreach ($cats as $c): ?><a href="<?= e(blogCategoryUrl($slug, $c)) ?>" class="page__taxonomy-item"><?= e($c['name']) ?></a><?php endforeach; ?>
      <?php foreach ($tags as $tg): ?><span class="page__taxonomy-item"><?= e($tg) ?></span><?php endforeach; ?>
    </p>
    <?php endif; ?>
  </footer>
  <section class="page__share">
    <h4 class="page__share-title">Condividi su</h4>
    <?php foreach ($share as [$label, $icon, $href]): ?>
      <a href="<?= e($href) ?>" class="btn btn--inverse" target="_blank" rel="noopener nofollow" title="Condividi su <?= e($label) ?>"><i class="<?= e($icon) ?>" aria-hidden="true"></i> <?= e($label) ?></a>
    <?php endforeach; ?>
  </section>
  <nav class="pagination">
    <?php if ($prev): ?><a href="<?= e(blogPostUrl($slug, $prev)) ?>" class="pagination--pager" title="<?= e($prev['title']) ?>">Precedente</a><?php else: ?><span class="pagination--pager disabled">Precedente</span><?php endif; ?>
    <?php if ($next): ?><a href="<?= e(blogPostUrl($slug, $next)) ?>" class="pagination--pager" title="<?= e($next['title']) ?>">Successivo</a><?php else: ?><span class="pagination--pager disabled">Successivo</span><?php endif; ?>
  </nav>
</article>
<?php if ($related): ?>
<div class="page__related">
  <h2 class="page__related-title">Ti potrebbe interessare</h2>
  <div class="grid__wrapper">
    <?php foreach ($related as $r): ?>
    <div class="archive__item acad-card">
      <h3 class="archive__item-title"><a href="<?= e(blogPostUrl($slug, $r)) ?>"><?= e($r['title']) ?></a></h3>
      <p class="page__meta"><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= ($m = academicReadMinutes((string) $r['content'])) === 1 ? 'meno di 1 minuto' : $m . ' minuti' ?> di lettura</p>
      <p class="page__meta">Pubblicato: <?= e(formatItalianDate($r['published_at'], $artist)) ?></p>
      <p class="archive__item-excerpt"><?= e(textExcerpt((string) ($r['excerpt'] ?: $r['content']), 120)) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php
    return ob_get_clean();
}

/** Piè di pagina: link del profilo, contatti, copyright, privacy e credito al tema. */
function academicFooterHtml(array $artist, string $slug): string {
    $name = (string) $artist['display_name'];
    $links = academicProfileLinks((int) $artist['id'], 6);
    $footerPrivacyUrl = trim(getProfileTracking($artist)['privacy_policy_url'] ?? '') ?: (getSiteSetting('privacy_policy_url') ?: '');
    ob_start();
    ?>
<div class="page__footer">
  <footer>
    <ul class="social-icons">
      <li><strong>Segui:</strong></li>
      <?php foreach ($links as $l): ?>
        <li><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener nofollow"><i class="fa-solid fa-link" aria-hidden="true"></i> <?= e($l['label'] ?: $l['url']) ?></a></li>
      <?php endforeach; ?>
      <li><a href="/<?= e($slug) ?>/contatti"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Contatti</a></li>
    </ul>
    <div class="page__footer-copyright">
      &copy; <?= date('Y') ?> <?= e($name) ?>. <a href="/"><?= e(siteName()) ?></a><?php if ($footerPrivacyUrl): ?> · <a href="<?= e($footerPrivacyUrl) ?>" target="_blank" rel="noopener">Privacy</a><?php endif; ?>
      · Tema ispirato a <a href="https://github.com/academicpages/academicpages.github.io" target="_blank" rel="noopener">Academic Pages</a> (licenza MIT)
    </div>
  </footer>
</div>
<?php
    return ob_get_clean();
}

/** Voci della timeline come elenco Academic (usata dalla Home e da «Carica altri» in timeline_more.php). */
function academicTimelineItemsHtml(array $items, array $artist): string {
    ob_start();
    foreach ($items as $it):
        $meta = ADMINLTE_TIMELINE_TYPE_META[$it['tipo']] ?? ['label' => 'Aggiornamento'];
        ?>
        <div class="archive__item">
          <?php if (!empty($it['cover'])): ?>
            <a class="archive__item-teaser" href="<?= e($it['url']) ?>"><img src="/<?= e(ltrim((string) $it['cover'], '/')) ?>" alt="" loading="lazy"></a>
          <?php else: ?>
            <a class="archive__item-teaser archive__item-teaser--ph" href="<?= e($it['url']) ?>" tabindex="-1" aria-hidden="true"><i class="fa-regular fa-file-lines"></i></a>
          <?php endif; ?>
          <div class="archive__item-body">
            <h3 class="archive__item-title"><a href="<?= e($it['url']) ?>"><?= e(textExcerpt((string) $it['titolo'], 140)) ?></a></h3>
            <p class="page__meta"><i class="fa-regular fa-calendar" aria-hidden="true"></i> <?= e(formatLocalDateTime($it['data'], $artist)) ?> · <?= e($meta['label']) ?></p>
          </div>
        </div>
        <?php
    endforeach;
    return ob_get_clean();
}

/** Home del profilo nel tema Academic. */
function renderAcademicProfileTheme(array $artist, string $slug): string {
    $uid = (int) $artist['id'];
    $pageUrl = siteUrl('/' . $slug);
    $name = (string) $artist['display_name'];
    $ogDescription = !empty($artist['bio']) ? textExcerpt($artist['bio'], 160) : ($name . ' su ' . siteName());
    $feed = getTimelineFeedForUsers([$uid], 10, 0);
    academicSkinActive(true);

    ob_start();
    ?>
<!doctype html>
<html lang="it" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($name) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= e($name) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if (!empty($artist['avatar_path'])): ?><meta property="og:image" content="<?= e(siteUrl('/' . $artist['avatar_path'])) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= academicHeadAssets(true) ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body>
<?= embedTrackingBodyStart($artist) ?>
<?= academicMastheadHtml($artist, $slug, 'home') ?>

<div id="main" role="main">
  <?= academicSidebarHtml($artist, $slug) ?>

  <article class="page">
    <?php if (!empty($_GET['follow_msg'])): ?>
      <div class="notice<?= !empty($_GET['follow_err']) ? ' notice--danger' : '' ?>"><?= e($_GET['follow_msg']) ?></div>
    <?php endif; ?>
    <h1 class="page__title">Timeline</h1>
    <section class="page__content">
      <div id="acad-feed">
        <?php if (!$feed): ?>
          <p class="muted">Nessun aggiornamento ancora.</p>
        <?php endif; ?>
        <?= academicTimelineItemsHtml($feed, $artist) ?>
      </div>
      <?php if (count($feed) >= 10): ?>
        <p class="acad-more"><button type="button" class="btn btn--primary" id="acad-more" data-slug="<?= e($slug) ?>" data-offset="<?= count($feed) ?>">Carica altri</button></p>
      <?php endif; ?>
    </section>
  </article>
</div>

<?= academicFooterHtml($artist, $slug) ?>
</body>
</html>
<?php
    return ob_get_clean();
}
