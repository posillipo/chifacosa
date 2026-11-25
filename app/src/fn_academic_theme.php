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
    if ($set !== null) {
        $on = $set;
    }
    return $on;
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
    $items = ['timeline' => ['Timeline', '/' . $slug . '/timeline']];
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
    <nav class="masthead__menu" id="acad-nav" aria-label="Navigazione principale">
      <ul class="visible-links">
        <li class="masthead__menu-item masthead__menu-item--lg<?= $activeKey === 'home' ? ' selected' : '' ?>"><a href="/<?= e($slug) ?>"><?= e($name) ?></a></li>
        <?php foreach ($nav as $key => [$label, $url]): ?>
          <li class="masthead__menu-item<?= $activeKey === $key ? ' selected' : '' ?>"><a href="<?= e($url) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <li class="masthead__menu-item masthead__menu-item--tail">
          <?php if (empty($_SESSION['user_id'])): ?><a href="/login.php">Accedi</a><?php else: ?><a href="/dashboard.php">Dashboard</a><?php endif; ?>
        </li>
        <li class="masthead__menu-item masthead__menu-item--tail"><button type="button" id="acad-theme" aria-label="Cambia tema chiaro/scuro" title="Tema chiaro/scuro"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></button></li>
      </ul>
    </nav>
    <a class="acad-brand" href="/<?= e($slug) ?>"><?= e($name) ?></a>
    <button type="button" class="acad-burger" id="acad-burger" aria-label="Apri il menu" aria-controls="acad-nav" aria-expanded="false"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
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
      <?php if (!empty($artist['bio'])): ?><p class="author__bio"><?= e(textExcerpt($artist['bio'], 140)) ?></p><?php endif; ?>
    </div>
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
    <?php if ($extraTopHtml !== ''): ?><div class="author__extra"><?= $extraTopHtml ?></div><?php endif; ?>
  </aside>
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
    <h1 class="page__title"><?= e($name) ?></h1>
    <section class="page__content">
      <h2>Chi sono</h2>
      <?php if (!empty($artist['bio'])): ?>
        <p><?= nl2br(e($artist['bio'])) ?></p>
      <?php else: ?>
        <p class="muted">Nessuna presentazione ancora.</p>
      <?php endif; ?>

      <h2>Ultimi aggiornamenti</h2>
      <?php if (!$feed): ?>
        <p class="muted">Nessun aggiornamento ancora.</p>
      <?php endif; ?>
      <?php foreach ($feed as $it):
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
      <?php endforeach; ?>
      <p><a href="/<?= e($slug) ?>/timeline">Vedi tutta la Timeline →</a></p>
    </section>
  </article>
</div>

<?= academicFooterHtml($artist, $slug) ?>
</body>
</html>
<?php
    return ob_get_clean();
}
