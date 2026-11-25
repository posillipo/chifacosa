<?php
// Tema "Academic": veste pubblica ispirata ad Academic Pages (https://github.com/academicpages/academicpages.github.io,
// a sua volta derivato da Minimal Mistakes — licenza MIT, © 2016 Michael Rose; avviso in assets/css/academic-LICENSE.txt).
// Non usa il codice Jekyll: ne riprende l'impostazione grafica (barra in alto con testo, profilo dell'autore a sinistra,
// pagina bianca con titoli a filetto, elenco di voci, tema chiaro/scuro) con stile e script propri.
// Stesso principio "a scena" del tema PA Italia: sostituisce la Home (/slug); le altre pagine pubbliche restano nello
// scheletro condiviso finché non vengono costruite anche loro.

/** Voci della barra in alto: stesse condizioni di adminLteTopNav(), in forma piatta (etichetta => indirizzo). */
function academicNavItems(array $artist, string $slug): array {
    $uid = (int) $artist['id'];
    $isBandOrLabel = in_array($artist['account_type'] ?? 'band', ['band', 'label'], true);
    $hidden = getHiddenNavKeys($uid);
    $items = [];
    if (!in_array('cheamo', $hidden, true) && hasAnyVisibleCheAmo($uid, $hidden)) {
        $items['Che Amo'] = '/' . $slug . '/che-amo';
    }
    if ($isBandOrLabel && !empty($artist['spotify_artist_id'])) {
        $items['Spotify'] = '/' . $slug . '/spotify';
    }
    if ($isBandOrLabel && !empty($artist['spotify_show_id'])) {
        $items['Podcast'] = '/' . $slug . '/podcast';
    }
    if ($isBandOrLabel && !empty($artist['youtube_channel_id'])) {
        $items['YouTube'] = '/' . $slug . '/youtube';
    }
    if (!in_array('video', $hidden, true) && hasPublicVideos($uid)) {
        $items['Video'] = '/' . $slug . '/video';
    }
    $items['Blog'] = '/' . $slug . '/blog';
    if (!in_array('foto', $hidden, true) && hasPublicPhotoContent($uid)) {
        $items['Foto'] = '/' . $slug . '/foto';
    }
    if (!in_array('servizi', $hidden, true) && hasVisibleServices($uid)) {
        $items['Servizi'] = '/' . $slug . '/servizi';
    }
    if ($isBandOrLabel && !in_array('eventi', $hidden, true)) {
        $items['Eventi'] = '/' . $slug . '/eventi';
    }
    if (!in_array('link', $hidden, true) && profileHasActiveLinks($uid)) {
        $items['Link'] = '/' . $slug . '/link';
    }
    $items['Contatti'] = '/' . $slug . '/contatti';
    return $items;
}

/** Home del profilo nel tema Academic. */
function renderAcademicProfileTheme(array $artist, string $slug): string {
    $uid = (int) $artist['id'];
    $pageUrl = siteUrl('/' . $slug);
    $name = (string) $artist['display_name'];
    $ogDescription = !empty($artist['bio']) ? textExcerpt($artist['bio'], 160) : ($name . ' su ' . siteName());
    $avatarUrl = adminLteAvatarUrl($artist);
    $feed = getTimelineFeedForUsers([$uid], 10, 0);
    $nav = academicNavItems($artist, $slug);
    $footerPrivacyUrl = trim(getProfileTracking($artist)['privacy_policy_url'] ?? '') ?: (getSiteSetting('privacy_policy_url') ?: '');

    // Luogo e genere (città e provincia sono obbligatorie nel profilo): non stanno nei dati caricati da u.php.
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

    // Link del profilo (come nella pagina «Link»): i primi, con il nome e l'indirizzo.
    $links = [];
    try {
        $ls = getDB()->prepare('SELECT label, url, type FROM links WHERE user_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 12');
        $ls->execute([$uid]);
        foreach ($ls->fetchAll() as $l) {
            if (($l['type'] ?? 'link') === 'link' && preg_match('#^(https?://|mailto:)#i', (string) $l['url'])) {
                $links[] = $l;
            }
        }
    } catch (Throwable $e) {
    }

    // Segui / Dashboard (stessa logica semplificata della barra del tema AdminLTE).
    $viewerId = publicActingUserId();
    $isOwn = $viewerId && (int) $viewerId === $uid;
    $isFollowing = ($viewerId && !$isOwn) ? isFollowingAccount((int) $viewerId, $uid) : false;
    $showFollow = !in_array('segui', getHiddenNavKeys($uid), true);
    $cssV = @filemtime(__DIR__ . '/../public/assets/css/academic.css') ?: 1;
    $jsV = @filemtime(__DIR__ . '/../public/assets/js/academic.js') ?: 1;

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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="/assets/css/academic.css?v=<?= (int) $cssV ?>">
<script>try{var t=localStorage.getItem('acadTheme');if(t==='dark'||(!t&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.setAttribute('data-theme','dark');}}catch(e){}</script>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body>
<?= embedTrackingBodyStart($artist) ?>
<div class="masthead">
  <div class="masthead__inner-wrap">
    <nav class="masthead__menu" id="acad-nav" aria-label="Navigazione principale">
      <ul class="visible-links">
        <li class="masthead__menu-item masthead__menu-item--lg selected"><a href="/<?= e($slug) ?>"><?= e($name) ?></a></li>
        <?php foreach ($nav as $label => $url): ?>
          <li class="masthead__menu-item"><a href="<?= e($url) ?>"><?= e($label) ?></a></li>
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

<div id="main" role="main">
  <aside class="sidebar">
    <div class="author__avatar"><img src="<?= e($avatarUrl) ?>" alt="<?= e($name) ?>"></div>
    <div class="author__content">
      <h3 class="author__name"><?= e($name) ?></h3>
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
  </aside>

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

<div class="page__footer">
  <footer>
    <ul class="social-icons">
      <li><strong>Segui:</strong></li>
      <?php foreach (array_slice($links, 0, 6) as $l): ?>
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
<script src="/assets/js/academic.js?v=<?= (int) $jsV ?>" defer></script>
</body>
</html>
<?php
    return ob_get_clean();
}
