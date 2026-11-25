<?php
// Tema AdminLTE e PA Italia: asset, barra, colonne laterali, schede, righe e widget.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Helper condivisi per TUTTE le pagine pubbliche a tema AdminLTE oltre alla Home (Timeline, Blog,
// Che Amo, Spotify, Podcast, Video, Menù, Offerte, Foto, Servizi, Eventi, Contatti...) — evitano di
// ripetere lo stesso ~30 righe di link CSS/breadcrumb/footer in ognuna.
function adminLteAssetLinks(): string {
    return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous" media="print" onload="this.media=\'all\'">' . "\n"
         . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">' . "\n"
         . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">' . "\n"
         . '<link rel="stylesheet" href="' . assetUrl('/assets/themes/adminlte-profile/css/adminlte.min.css') . '">' . "\n"
         // La miniatura degli articoli del blog (renderAdminLteBlogRows()) è pensata per stare
         // accanto al titolo. Sotto 576px, se il titolo è lungo va su tante righe mentre la
         // miniatura resta piccola e fissa in alto: siccome ".d-flex" allinea le due colonne alla
         // stessa altezza (default align-items:stretch) ma l'immagine ha una sua altezza fissa,
         // sotto l'immagine resta uno spazio vuoto per tutta l'altezza del testo — card sbilanciata.
         // Qui sotto 576px si passa a un impaginato impilato (immagine sopra a piena larghezza,
         // testo sotto), come nei classici elenchi di articoli mobile.
         . '<style>.adminlte-blog-thumb{width:140px;height:140px;object-fit:cover;flex-shrink:0;border-radius:.375rem 0 0 .375rem;}'
         . '@media (max-width:575.98px){.adminlte-blog-row{flex-direction:column;}.adminlte-blog-thumb{width:100%;height:180px;border-radius:.375rem .375rem 0 0;}}'
         // Stessa logica di impaginazione della miniatura del blog qui sopra, applicata alla
         // miniatura degli eventi (renderAdminLteEventiRows()): sotto 576px si passa da "immagine
         // fissa a fianco del testo" a "immagine sopra a piena larghezza, testo sotto".
         . '.adminlte-eventi-row{align-items:center;}'
         . '.adminlte-eventi-thumb{width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;}'
         . '@media (max-width:575.98px){.adminlte-eventi-row{flex-direction:column;align-items:stretch;}.adminlte-eventi-thumb{width:100%;height:180px;}}'
         // Nome del profilo nel brand della barra di navigazione (adminLteTopNav()): troncato per
         // non spingere fuori schermo le voci di menu quando il nome è lungo.
         . '.topnav-brand-name{max-width:180px;display:inline-block;}'
         // Con molte voci visibili contemporaneamente (Che Amo, Spotify, Podcast, Video, Blog,
         // Menù, Offerte, Foto, Servizi, Eventi, Contatti) la barra può non stare su una riga sola
         // alle larghezze desktop più strette: Bootstrap non manda a capo le voci di ".navbar-nav"
         // di default (le farebbe uscire dallo schermo) — qui si permette il ritorno a capo invece
         // che il taglio.
         . '@media (min-width:992px){#topNavMenu .navbar-nav{flex-wrap:wrap;row-gap:.25rem;}}'
         // Le tre colonne del profilo (sidebar, contenuto, extra) sono già alte quanto la più alta
         // delle tre (comportamento predefinito di flexbox su ".row") — ma la card della colonna
         // centrale (.adminlte-main-col) si fermava alla propria altezza naturale, lasciando lo
         // sfondo grigio della pagina sotto quando il contenuto era più corto delle colonne
         // laterali (es. il modulo di Contatti): pareva "non estendersi" come le altre, con uno
         // spazio vuoto sprecato. Qui la card si allunga fino a riempire tutta l'altezza
         // disponibile, e il suo card-body con lei (le card sono già "display:flex;flex-direction:
         // column" di loro — vedi Bootstrap — basta farle crescere). Solo da tablet in su: sotto le
         // colonne sono impilate e non c'è nessuna altezza da pareggiare.
         . '@media (min-width:768px){.adminlte-main-col{display:flex;flex-direction:column;}'
         . '.adminlte-main-col>.card{flex:1 1 auto;}'
         . '.adminlte-main-col>.card>.card-body{flex:1 1 auto;}}'
         // Niente max-width sulla colonna centrale: occupa tutto lo spazio della griglia (col-md-6),
         // come il feed di Facebook. La leggibilità è già garantita dalla griglia a 3 colonne.
         // Sotto i 768px le tre colonne (sidebar, contenuto, extra) sono impilate una sopra
         // l'altra: il padding del container Bootstrap (.75rem) sommato al gutter della riga
         // (.5rem, da "row g-3") lasciava fino a 20px di sfondo vuoto per lato attorno alle card,
         // uno spreco su schermi già stretti. Qui sotto vengono azzerati entrambi, così le card
         // toccano i bordi dello schermo (il loro padding interno, card-body, resta invariato: il
         // testo non tocca comunque i bordi).
         . '@media (max-width:767.98px){.app-content .container-fluid{padding-left:0;padding-right:0;}'
         . '.app-content .row.g-3{--bs-gutter-x:0;}}'
         // Mini-carosello generico a schede (renderAdminLteMiniCarousel()): una slide a piena
         // larghezza per volta, swipe/scroll orizzontale nativo (scroll-snap, niente libreria),
         // con due freccette per chi preferisce cliccare — usato sia per raggruppare elementi
         // omogenei dello stesso giorno sia per il carosello "Primo Piano". Le freccette sono
         // gestite da un solo script delegato in adminLteFooterBlock(), valido per tutte le
         // istanze della pagina.
         . '.admlte-carousel-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;-ms-overflow-style:none;scrollbar-width:none;}'
         . '.admlte-carousel-track::-webkit-scrollbar{display:none;}'
         . '.admlte-carousel-slide{flex:0 0 100%;scroll-snap-align:start;min-width:0;}'
         . '.admlte-carousel-nav{position:absolute;top:50%;transform:translateY(-50%);z-index:2;width:32px;height:32px;padding:0;border-radius:50%;background:rgba(0,0,0,.45);color:#fff;border:none;display:flex;align-items:center;justify-content:center;}'
         . '.admlte-carousel-nav:hover{background:rgba(0,0,0,.65);}'
         . '.admlte-carousel-nav.admlte-carousel-prev{left:8px;}'
         . '.admlte-carousel-nav.admlte-carousel-next{right:8px;}'
         // Variante "3 card per volta" dello stesso mini-carosello (renderAdminLteMiniCarousel()
         // con $perView=3), usata dal carosello "Primo Piano": sotto/uguale a 3 elementi le card si
         // dividono lo spazio in proporzione (.admlte-carousel-fit, niente scroll — non ha senso
         // "scorrere" quando ci stanno già tutte); sopra i 3 restano a larghezza fissa (un terzo
         // della riga, con un pavimento minimo) e il track scorre in orizzontale mostrandone
         // sempre 3 alla volta. Sotto i 576px .admlte-carousel-fit viene disattivata apposta: su
         // schermi stretti "dividersi lo spazio in proporzione" vuol dire schiacciare 3 card in
         // ~130px l'una, illeggibili — meglio una riga tipo "Stato" di WhatsApp, card a
         // dimensione fissa leggibile che scorre in orizzontale col dito, come sopra i 3 elementi.
         . '.admlte-carousel-wrap-3 .admlte-carousel-track{gap:.75rem;}'
         . '.admlte-carousel-wrap-3 .admlte-carousel-slide{flex:0 0 calc((100% - 1.5rem)/3);min-width:160px;}'
         . '.admlte-carousel-wrap-3.admlte-carousel-fit .admlte-carousel-slide{flex:1 1 0;min-width:0;}'
         . '@media (max-width:575.98px){.admlte-carousel-wrap-3.admlte-carousel-fit .admlte-carousel-slide{flex:0 0 150px;min-width:150px;}}'
         // Riquadro della copertina nello stile "ribbon" (In Primo Piano): altezza proporzionale
         // alla larghezza reale della card (aspect-ratio) invece di un'altezza fissa in pixel —
         // con un'altezza fissa, una card stretta su mobile lascerebbe un grande spazio bianco
         // sopra/sotto un'immagine ridotta (object-fit:contain, vedi renderAdminLteMiniCarousel())
         // proporzionalmente più piccola della larghezza disponibile.
         . '.admlte-pinned-cover{aspect-ratio:4/5;overflow:hidden;}'
         // Sfondo sfocato della stessa immagine (invece del semplice sfondo bianco) per riempire lo
         // spazio vuoto lasciato da object-fit:contain: essendo la foto stessa, ingrandita e
         // sfocata, il colore "combacia" sempre — stesso trucco di Spotify/Apple Music per le
         // copertine non quadrate. Scale(1.15) evita che la sfocatura mostri i bordi netti
         // dell'immagine originale ai margini del riquadro.
         . '.admlte-pinned-cover-bg{position:absolute;inset:0;background-size:cover;background-position:center;filter:blur(20px) brightness(.9);transform:scale(1.15);}'
         . '@media (min-width:992px){.adminlte-sidebar-col,.adminlte-extras-col{position:sticky;top:60px;align-self:flex-start;}}'
         // Desktop: SOLO il contenuto della colonna centrale è limitato a 800px, come la dashboard; resta centrato
         // nel suo spazio. Le colonne laterali non cambiano (25% + 25% come prima). Con spazio minore di 800px
         // (schermi medi) il centro occupa tutta la colonna come sempre; sotto i 992px le colonne sono impilate.
         . '@media (min-width:992px){.adminlte-main-col{align-items:center;}'
         . '.adminlte-main-col>*{width:100%;max-width:800px;}}'
         . '</style>'
         // Veste «Academic» (stesse pagine, altra grafica): stile, script e tema chiaro/scuro (vedi fn_academic_theme.php).
         . (academicSkinActive() ? "\n" . academicHeadAssets(false) : '');
}


// Helper condiviso per il tema pubblico "PA Italia" (renderPaItaliaProfileTheme()) — vendorizzato
// da bootstrap-italia (github.com/italia/bootstrap-italia, licenza BSD-3-Clause, vedi
// assets/themes/pa-italia/LICENSE-bootstrap-italia.txt), il design system open source della
// Pubblica Amministrazione italiana. Solo CSS/JS/icone sono vendorizzati qui: il font Titillium
// Web (il font di base dichiarato dal CSS) non è incluso nel pacchetto npm, va caricato da Google
// Fonts come già fatto altrove in questo file per altri font esterni.
function paItaliaAssetLinks(): string {
    return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
         . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Titillium+Web:ital,wght@0,400;0,600;0,700;1,400&display=swap">' . "\n"
         . '<link rel="stylesheet" href="' . assetUrl('/assets/themes/pa-italia/css/bootstrap-italia.min.css') . '">' . "\n"
         . '<script src="' . assetUrl('/assets/themes/pa-italia/js/bootstrap-italia.bundle.min.js') . '" defer></script>';
}


// Riga di una card Bootstrap Italia ("card-wrapper card-space" + "card-bg") per un elemento della
// Timeline — versione semplificata di renderAdminLteTimelineRows(): stessa fonte dati
// (getTimelineFeedForUsers()), stessi campi (tipo/titolo/cover/data/url), ma senza per ora il
// raggruppamento "stesso giorno" con mini-carosello, rimandato a un secondo incremento come tutte
// le altre pagine interne di questo tema (solo la Home è vestita "PA Italia" per adesso, il resto
// resta nel tema Colorful — stessa scelta fatta all'inizio per il tema AdminLTE).
function renderPaItaliaTimelineCards(array $items, array $artist): string {
    if (!$items) {
        return '<p class="text-muted">Nessun aggiornamento ancora.</p>';
    }
    $avatarUrl = adminLteAvatarUrl($artist);
    $displayName = $artist['display_name'] ?? '';
    ob_start();
    foreach ($items as $it):
        $meta = ADMINLTE_TIMELINE_TYPE_META[$it['tipo']] ?? ['icon' => 'bi-star', 'color' => 'primary', 'label' => 'Aggiornamento'];
        ?>
        <a href="<?= e($it['url']) ?>" class="card card-bg mb-3 text-decoration-none d-block">
          <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
              <img src="<?= e($avatarUrl) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
              <span class="fw-semibold text-dark small"><?= e($displayName) ?></span>
              <span class="badge bg-primary"><?= e($meta['label']) ?></span>
            </div>
            <?php if (!empty($it['cover'])): ?>
              <img src="/<?= e($it['cover']) ?>" alt="" class="img-fluid rounded mb-2" style="max-height:280px;width:100%;object-fit:cover;">
            <?php endif; ?>
            <h5 class="card-title text-dark"><?= e(textExcerpt((string) $it['titolo'], 140)) ?></h5>
            <p class="card-text mb-0"><small class="text-muted"><?= e(formatLocalDateTime($it['data'], $artist)) ?></small></p>
          </div>
        </a>
        <?php
    endforeach;
    return ob_get_clean();
}


// Home a tema "PA Italia" — stesso principio "a scena" della Home AdminLTE
// (renderAdminLteProfileTheme()): sostituisce solo /slug, tutte le altre pagine pubbliche restano
// nel tema Colorful finché non vengono costruite anche loro (vedi commento in
// renderPaItaliaTimelineCards()).
function renderPaItaliaProfileTheme(array $artist, string $slug): string {
    $uid = (int) $artist['id'];
    $pageUrl = siteUrl('/' . $slug);
    $ogDescription = !empty($artist['bio']) ? textExcerpt($artist['bio'], 160) : ($artist['display_name'] . ' su ' . siteName());
    $avatarUrl = adminLteAvatarUrl($artist);
    $feed = getTimelineFeedForUsers([$uid], 10, 0);
    $footerPrivacyUrl = trim(getProfileTracking($artist)['privacy_policy_url'] ?? '') ?: (getSiteSetting('privacy_policy_url') ?: '');

    // Stessa selezione voci/condizioni di adminLteTopNav(), semplificata: qui basta l'elenco già
    // filtrato per costruire il menu, non serve replicare l'intera struttura di quella funzione.
    $hiddenKeys = getHiddenNavKeys($uid);
    $isBandOrLabel = in_array($artist['account_type'] ?? 'band', ['band', 'label'], true);
    $navItems = ['timeline' => ['label' => 'Home', 'url' => '/' . $slug]];
    if (!in_array('cheamo', $hiddenKeys, true) && hasAnyVisibleCheAmo($uid, $hiddenKeys)) {
        $navItems['cheamo'] = ['label' => 'Che Amo', 'url' => '/' . $slug . '/che-amo'];
    }
    $navItems['blog'] = ['label' => 'Blog', 'url' => '/' . $slug . '/blog'];
    if ($isBandOrLabel && !in_array('eventi', $hiddenKeys, true)) {
        $navItems['eventi'] = ['label' => 'Eventi', 'url' => '/' . $slug . '/eventi'];
    }
    $navItems['contatti'] = ['label' => 'Contatti', 'url' => '/' . $slug . '/contatti'];

    ob_start();
    ?>
<!doctype html>
<html lang="it" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if (!empty($artist['avatar_path'])): ?><meta property="og:image" content="<?= e(siteUrl('/' . $artist['avatar_path'])) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= paItaliaAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
<style>
  body{font-family:"Titillium Web",Geneva,Tahoma,sans-serif;}
  /* Il CSS di bootstrap-italia applica lo sfondo blu della testata solo in combinazione con
     classi di stato gestite dal proprio JS (l'header "sticky" completo, con più varianti che
     qui non servono) — per una testata sempre semplice e sempre blu, indipendente da quello
     stato, il colore è forzato qui invece di dipendere da quelle classi. */
  .it-header-wrapper,.it-header-center-wrapper,.it-header-navbar-wrapper{background:#0059b3!important;}
  .it-header-navbar-wrapper .nav-link{color:#fff!important;}
  .it-header-navbar-wrapper .nav-link:hover{color:#cfe4ff!important;}
  .it-brand-title,.it-brand-tagline{color:#fff;}
</style>
</head>
<body>
<?= embedTrackingBodyStart($artist) ?>
<div class="it-header-wrapper">
  <div class="it-header-center-wrapper">
    <div class="container">
      <div class="it-header-center-content-wrapper">
        <div class="it-brand-wrapper">
          <a href="/<?= e($slug) ?>">
            <img src="<?= e($avatarUrl) ?>" alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">
            <div class="it-brand-text">
              <div class="it-brand-title"><?= e($artist['display_name']) ?></div>
              <?php if (!empty($artist['bio'])): ?>
                <div class="it-brand-tagline"><?= e(textExcerpt($artist['bio'], 80)) ?></div>
              <?php endif; ?>
            </div>
          </a>
        </div>
      </div>
    </div>
  </div>
  <div class="it-header-navbar-wrapper">
    <div class="container">
      <nav class="navbar navbar-expand-lg" aria-label="Navigazione principale">
        <button class="custom-navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#pa-italia-nav" aria-controls="pa-italia-nav" aria-expanded="false" aria-label="Apri il menu" style="width:32px;height:24px;position:relative;">
          <span style="position:absolute;left:0;right:0;top:0;height:3px;background:#fff;border-radius:2px;"></span>
          <span style="position:absolute;left:0;right:0;top:10px;height:3px;background:#fff;border-radius:2px;"></span>
          <span style="position:absolute;left:0;right:0;top:20px;height:3px;background:#fff;border-radius:2px;"></span>
        </button>
        <div class="navbar-collapse collapse" id="pa-italia-nav">
          <div class="menu-wrapper">
            <ul class="navbar-nav">
              <?php foreach ($navItems as $key => $n): ?>
              <li class="nav-item">
                <a class="nav-link" href="<?= e($n['url']) ?>"><?= e($n['label']) ?></a>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </nav>
    </div>
  </div>
</div>
<main class="container my-4">
  <div class="row">
    <div class="col-lg-8">
      <?php if (!empty($artist['bio'])): ?>
      <div class="card card-bg mb-4">
        <div class="card-body">
          <h5 class="card-title text-dark">Chi sono</h5>
          <p class="card-text"><?= nl2br(e($artist['bio'])) ?></p>
        </div>
      </div>
      <?php endif; ?>
      <h3 class="mb-3">Timeline</h3>
      <?= renderPaItaliaTimelineCards($feed, $artist) ?>
      <p class="text-center"><a href="/<?= e($slug) ?>/timeline">Vedi tutta la Timeline →</a></p>
    </div>
    <div class="col-lg-4">
      <div class="card card-bg mb-4">
        <div class="card-body">
          <h5 class="card-title text-dark">Segui <?= e($artist['display_name']) ?></h5>
          <a href="/<?= e($slug) ?>#segui-widget" class="btn btn-primary btn-full-width">Segui</a>
        </div>
      </div>
    </div>
  </div>
</main>
<footer class="it-footer">
  <div class="it-footer-main">
    <div class="container">
      <div class="row">
        <div class="col-12">
          <div class="it-brand-wrapper">
            <a href="/">
              <span class="it-brand-text">
                <span class="it-brand-title"><?= e(siteName()) ?></span>
              </span>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="it-footer-small-prints clearfix">
    <div class="container">
      <ul class="it-footer-small-prints-list list-inline mb-0 d-flex flex-column flex-md-row">
        <li class="list-inline-item"><a class="text-white" href="/"><?= e(siteName()) ?></a></li>
        <li class="list-inline-item"><a class="text-white" href="<?= $footerPrivacyUrl !== '' ? e($footerPrivacyUrl) : '/' ?>"<?= $footerPrivacyUrl !== '' ? ' target="_blank" rel="noopener"' : '' ?>>Privacy</a></li>
        <li class="list-inline-item"><a class="text-white" href="/credits.php">Crediti</a></li>
      </ul>
    </div>
  </div>
</footer>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Blocco breadcrumb + titolo H1 dell'app-content-header. $trail sono le tappe intermedie tra
// l'artista e la pagina corrente, come coppie [etichetta => url] (es. ['Che Amo' => '/slug/che-amo']
// per la pagina di un singolo elemento) — vuoto per le pagine di primo livello.
function adminLteBreadcrumbHeader(string $slug, string $displayName, string $current, array $trail = []): string {
    // Sulla Home dell'artista $current è lo stesso $displayName (il titolo H1 è il suo nome):
    // niente link di metà percorso in quel caso, altrimenti il nome comparirebbe due volte di
    // fila nel breadcrumb ("Chi Fa Cosa / Nome / Nome").
    $isOwnHome = ($current === $displayName) && !$trail;
    ob_start();
    ?>
    <div class="app-content-header">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12 d-none d-sm-block">
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="/"><?= e(siteName()) ?></a></li>
                <?php if (!$isOwnHome): ?>
                  <li class="breadcrumb-item"><a href="/<?= e($slug) ?>"><?= e($displayName) ?></a></li>
                <?php endif; ?>
                <?php foreach ($trail as $label => $url): ?>
                  <li class="breadcrumb-item"><a href="<?= e($url) ?>"><?= e($label) ?></a></li>
                <?php endforeach; ?>
                <li class="breadcrumb-item active" aria-current="page"><?= e($current) ?></li>
              </ol>
            </nav>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}


function adminLteFooterBlock(array $artist): string {
    if (academicSkinActive()) {
        return academicFooterHtml($artist, (string) ($artist['slug'] ?? ($_GET['slug'] ?? '')));
    }
    $footerPrivacyUrl = trim(getProfileTracking($artist)['privacy_policy_url'] ?? '') ?: (getSiteSetting('privacy_policy_url') ?: '');
    ob_start();
    ?>
  <footer class="app-footer">
    <div class="float-end d-none d-sm-inline">
      <a href="#" class="cky-banner-element text-decoration-none">Preferenze Cookie</a>
      · <a href="<?= $footerPrivacyUrl !== '' ? e($footerPrivacyUrl) : '/' ?>" class="text-decoration-none"<?= $footerPrivacyUrl !== '' ? ' target="_blank" rel="noopener"' : '' ?>>Privacy</a>
      · <a href="/credits.php" class="text-decoration-none">Crediti</a>
    </div>
    <strong><?= e($artist['display_name']) ?></strong> su <a href="/" class="text-decoration-none"><?= e(siteName()) ?></a>
  </footer>
  <button type="button" id="adminlte-back-to-top" class="btn btn-primary rounded-circle shadow d-none" style="position:fixed;right:20px;bottom:20px;width:46px;height:46px;z-index:1030;" aria-label="Torna su">
    <i class="bi bi-arrow-up" aria-hidden="true"></i>
  </button>
  <script>
  (function () {
    var btn = document.getElementById('adminlte-back-to-top');
    if (!btn) return;
    window.addEventListener('scroll', function () {
      btn.classList.toggle('d-none', window.scrollY <= 400);
    });
    btn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  })();
  // Freccette del mini-carosello generico (renderAdminLteMiniCarousel()): un solo listener
  // delegato sul documento, valido per tutte le istanze già presenti nella pagina e per quelle
  // aggiunte poi dallo scroll infinito della Timeline — niente da (ri)collegare a ogni caricamento.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.admlte-carousel-nav');
    if (!btn) return;
    var track = btn.closest('.admlte-carousel-wrap').querySelector('.admlte-carousel-track');
    if (!track) return;
    var dir = btn.classList.contains('admlte-carousel-next') ? 1 : -1;
    track.scrollBy({ left: track.clientWidth * dir, behavior: 'smooth' });
  });
  </script>
    <?php
    return ob_get_clean();
}


// Barra di navigazione in cima a OGNI pagina pubblica a tema AdminLTE — layout "Top Nav" di
// AdminLTE 4 (https://adminlte.io/themes/v4/layout/top-nav.html): niente <aside>, il menu vive
// in un <nav class="app-header navbar navbar-expand-lg"> con toggler su mobile, sempre visibile
// (la pagina chiamante aggiunge "fixed-header" al <body>, che lo rende "sticky" in cima durante
// lo scroll — vedi .fixed-header .app-header nel CSS di AdminLTE).
//
// Prima queste voci (Che Amo, Spotify, Podcast, Video, Blog...) vivevano in fondo alla colonna
// del profilo, raggiungibili solo dopo aver scrollato oltre la card utente e le statistiche: da
// qui sono raggiungibili subito, da qualunque pagina, senza scroll. "Che Amo" diventa un menu a
// tendina con tutti i moduli (Band/Attori/Film/Libri/Playlist/Album/Viaggi/Brani che amo) invece
// di portare prima alla vetrina e poi a un secondo click — un passaggio in meno per arrivare al
// contenuto specifico. "Segui" e Accedi/Registrati, prima sotto la card profilo, sono spostati
// qui per lo stesso motivo: azioni, non contenuto, restano più utili sempre a portata di mano.
function adminLteTopNav(array $artist, string $slug, string $activeKey): string {
    if (academicSkinActive()) {
        return academicMastheadHtml($artist, $slug, $activeKey);
    }
    $uid = (int) $artist['id'];
    $isBandOrLabel = in_array($artist['account_type'] ?? 'band', ['band', 'label'], true);
    $hiddenKeys = getHiddenNavKeys($uid);

    // Stessa selezione di che_amo.php (la vetrina): un modulo compare nel menu a tendina solo se
    // non nascosto da "Menu di Navigazione" e ha davvero del contenuto pubblico. "cheamo" tra le
    // chiavi nascoste spegne l'intero menu a tendina, non solo i singoli moduli.
    $cheAmoModules = [];
    if (!in_array('cheamo', $hiddenKeys, true)) {
        foreach (CHE_AMO_MODULES as $key => $m) {
            if (in_array($key, $hiddenKeys, true)) {
                continue;
            }
            if ($m['check'] === null || $m['check']($uid)) {
                $cheAmoModules[$key] = $m;
            }
        }
    }

    $navItems = [];
    if ($isBandOrLabel && !empty($artist['spotify_artist_id'])) {
        $navItems['spotify'] = ['label' => 'Spotify', 'url' => '/' . $slug . '/spotify'];
    }
    if ($isBandOrLabel && !empty($artist['spotify_show_id'])) {
        $navItems['podcast'] = ['label' => 'Podcast', 'url' => '/' . $slug . '/podcast'];
    }
    if ($isBandOrLabel && !empty($artist['youtube_channel_id'])) {
        $navItems['youtube'] = ['label' => 'YouTube', 'url' => '/' . $slug . '/youtube'];
    }
    if (!in_array('video', $hiddenKeys, true) && hasPublicVideos($uid)) {
        $navItems['video'] = ['label' => 'Video', 'url' => '/' . $slug . '/video'];
    }
    if (!in_array('contest', $hiddenKeys, true) && hasPublicContests($uid)) {
        $navItems['contest'] = ['label' => 'Contest', 'url' => '/' . $slug . '/contest'];
    }
    $navItems['blog'] = ['label' => 'Blog', 'url' => '/' . $slug . '/blog'];
    if (!in_array('menu', $hiddenKeys, true) && menuHasItems($uid)) {
        $navItems['menu'] = ['label' => 'Menù', 'url' => '/' . $slug . '/menu'];
    }
    if (!in_array('offerte', $hiddenKeys, true) && hasActiveOffers($uid)) {
        $navItems['offerte'] = ['label' => 'Offerte', 'url' => '/' . $slug . '/offerte'];
    }
    if (!in_array('foto', $hiddenKeys, true) && hasPublicPhotoContent($uid)) {
        $navItems['foto'] = ['label' => 'Foto', 'url' => '/' . $slug . '/foto'];
    }
    if (!in_array('servizi', $hiddenKeys, true) && hasVisibleServices($uid)) {
        $navItems['servizi'] = ['label' => 'Servizi', 'url' => '/' . $slug . '/servizi'];
    }
    if ($isBandOrLabel && !in_array('eventi', $hiddenKeys, true)) {
        $navItems['eventi'] = ['label' => 'Eventi', 'url' => '/' . $slug . '/eventi'];
    }
    if (!in_array('link', $hiddenKeys, true) && profileHasActiveLinks($uid)) {
        $navItems['link'] = ['label' => 'Link', 'url' => '/' . $slug . '/link'];
    }
    $navItems['contatti'] = ['label' => 'Contatti', 'url' => '/' . $slug . '/contatti'];

    $avatarUrl = adminLteAvatarUrl($artist);

    // Solo sul proprio profilo (lo stesso account con cui si è loggati, non un profilo diverso
    // gestito come co-admin — quelli restano seguibili come qualunque altro) il pulsante "Segui"
    // non ha senso: diventa un accesso rapido alla dashboard di gestione. Altrimenti si comporta
    // come "Segui" negli altri temi grafici (Colorful) — riflette se lo si sta già seguendo
    // (isFollowingAccount()), stesso link all'ancora #segui-widget della Home.
    $viewerId = publicActingUserId();
    $isOwnProfile = $viewerId && (int) $viewerId === $uid;
    $isFollowing = ($viewerId && !$isOwnProfile) ? isFollowingAccount((int) $viewerId, $uid) : false;
    // Se il profilo ha nascosto "Segui" dal menu non esiste nemmeno il modulo a cui il pulsante
    // punta: meglio non mostrare un pulsante che non porta da nessuna parte.
    $showSegui = !in_array('segui', getHiddenNavKeys($uid), true);

    ob_start();
    ?>
      <nav class="app-header navbar navbar-expand-lg bg-body">
        <div class="container-fluid">
          <a href="/<?= e($slug) ?>" class="navbar-brand d-flex align-items-center">
            <img src="<?= e($avatarUrl) ?>" alt="" width="30" height="30" class="rounded-circle shadow-sm me-2">
            <span class="fw-semibold text-truncate topnav-brand-name"><?= e($artist['display_name']) ?></span>
          </a>
          <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNavMenu" aria-controls="topNavMenu" aria-expanded="false" aria-label="Menu di navigazione">
            <span class="navbar-toggler-icon"></span>
          </button>
          <div class="collapse navbar-collapse" id="topNavMenu">
            <ul class="navbar-nav mx-lg-auto">
              <li class="nav-item">
                <!-- Porta comunque a /timeline (la pagina resta la stessa, con lo stesso feed): solo
                     l'etichetta nel menu diventa "Home", più intuitiva come primo/punto di ingresso
                     rispetto a "Timeline" per chi non conosce già il sito. -->
                <a href="/<?= e($slug) ?>/timeline" class="nav-link<?= $activeKey === 'timeline' ? ' active' : '' ?>">Home</a>
              </li>
              <?php if ($cheAmoModules): ?>
              <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle<?= $activeKey === 'cheamo' ? ' active' : '' ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false">Che Amo</a>
                <ul class="dropdown-menu">
                  <li><a class="dropdown-item" href="/<?= e($slug) ?>/che-amo">Tutti</a></li>
                  <li><hr class="dropdown-divider"></li>
                  <?php foreach ($cheAmoModules as $m): ?>
                  <li><a class="dropdown-item" href="/<?= e($slug) ?>/<?= e($m['segment']) ?>"><i class="<?= e($m['icon']) ?> me-2" aria-hidden="true"></i><?= e($m['label']) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </li>
              <?php endif; ?>
              <?php foreach (['spotify', 'podcast', 'youtube', 'video', 'contest', 'blog', 'menu', 'offerte', 'foto', 'servizi', 'eventi', 'link', 'contatti'] as $key): ?>
                <?php if (isset($navItems[$key])): ?>
              <li class="nav-item">
                <a href="<?= e($navItems[$key]['url']) ?>" class="nav-link<?= $activeKey === $key ? ' active' : '' ?>"><?= e($navItems[$key]['label']) ?></a>
              </li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ul>
            <ul class="navbar-nav align-items-lg-center">
              <?php if (empty($_SESSION['user_id'])): ?>
              <li class="nav-item"><a class="nav-link" href="/login.php"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Accedi</a></li>
              <li class="nav-item"><a class="nav-link" href="/register.php"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Registrati</a></li>
              <?php endif; ?>
              <li class="nav-item">
                <?php if ($isOwnProfile): ?>
                <a href="/dashboard.php" class="btn btn-primary btn-sm my-2 my-lg-0">
                  <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Dashboard
                </a>
                <?php elseif ($isFollowing): ?>
                <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm my-2 my-lg-0 me-1">
                  <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Dashboard
                </a>
                <?php if ($showSegui): ?>
                <?php // Già seguito: il pulsante smette di seguire (con conferma). Prima era solo un link alla Home e non faceva nulla. ?>
                <form method="post" action="/follow_account.php" class="d-inline" onsubmit="return confirm('Vuoi smettere di seguire <?= e(addslashes((string) $artist['display_name'])) ?>?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="user_id" value="<?= $uid ?>">
                  <input type="hidden" name="action" value="unfollow">
                  <input type="hidden" name="redirect" value="/<?= e($slug) ?>">
                  <button type="submit" class="btn btn-outline-primary btn-sm my-2 my-lg-0" title="Smetti di seguire">
                    <i class="bi bi-person-check-fill me-1" aria-hidden="true"></i>Segui già
                  </button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <?php if (!empty($_SESSION['user_id'])): ?>
                <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm my-2 my-lg-0 me-1">
                  <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Dashboard
                </a>
                <?php endif; ?>
                <?php if ($showSegui && !empty($_SESSION['user_id'])): ?>
                <?php // Già loggato: un solo clic per seguire (prima serviva andare in fondo alla Home e cliccare di nuovo). ?>
                <form method="post" action="/follow_account.php" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="user_id" value="<?= $uid ?>">
                  <input type="hidden" name="action" value="follow">
                  <input type="hidden" name="redirect" value="/<?= e($slug) ?>">
                  <button type="submit" class="btn btn-primary btn-sm my-2 my-lg-0">
                    <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Segui
                  </button>
                </form>
                <?php elseif ($showSegui): ?>
                <a href="/<?= e($slug) ?>#segui-widget" class="btn btn-primary btn-sm my-2 my-lg-0 js-segui-link">
                  <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Segui
                </a>
                <?php endif; ?>
                <?php endif; ?>
              </li>
            </ul>
          </div>
        </div>
      </nav>
    <?php
    return ob_get_clean();
}


// Colonna sinistra del profilo (avatar/follower/recensioni) — era solo nella Home, ora condivisa
// da OGNI pagina pubblica a tema AdminLTE (Timeline, Che Amo, Spotify...), così chi naviga dentro
// il profilo la vede sempre, non solo in Home. Self-contained: calcola da sola i dati che le
// servono a partire da $artist/$slug, così ogni pagina chiamante non deve ripetere le stesse
// query. Il menu di navigazione, "Segui" e Accedi/Registrati vivevano prima qui sotto le
// statistiche: sono passati alla barra in cima alla pagina (adminLteTopNav()) — qui resta solo
// l'identità.
function renderAdminLteProfileSidebar(array $artist, string $slug, bool $showFollowWidget = false, string $extraTopHtml = ''): string {
    if (academicSkinActive()) {
        return '<div class="col-lg-3 order-2 order-lg-1 adminlte-sidebar-col">' . academicSidebarHtml($artist, $slug, $extraTopHtml) . '</div>';
    }
    $uid = (int) $artist['id'];
    $db = getDB();

    $followerCount = getAccountFollowerCount($uid);
    $reviewStats = getBandRatingStats($uid);
    $contentCount = 0;
    $stmt = $db->prepare("SELECT COUNT(*) c FROM timeline_posts WHERE user_id=? AND visibility='public'");
    $stmt->execute([$uid]);
    $contentCount += (int) $stmt->fetch()['c'];
    $stmt = $db->prepare('SELECT COUNT(*) c FROM blog_posts WHERE user_id=? AND published_at <= NOW()');
    $stmt->execute([$uid]);
    $contentCount += (int) $stmt->fetch()['c'];

    $avatarUrl = adminLteAvatarUrl($artist);

    // "I miei link" e "Chi sono" vivevano nella colonna extra (renderAdminLteProfileExtras, a
    // destra insieme a "Che Amo"): spostati qui per bilanciare le due colonne laterali — a
    // sinistra tutto ciò che riguarda il profilo (identità, link, informazioni), a destra solo
    // "Che Amo".
    $stmt = $db->prepare('SELECT citta, provincia FROM profiles WHERE user_id=?');
    $stmt->execute([$uid]);
    $extra = $stmt->fetch() ?: [];
    $citta = trim($extra['citta'] ?? '');
    $provincia = trim($extra['provincia'] ?? '');
    $hasSpotify = !empty($artist['spotify_artist_id']);
    $hasYoutube = !empty($artist['youtube_channel_id']);
    $hasPodcast = !empty($artist['spotify_show_id']);

    $stmt = $db->prepare("SELECT id, label, url, cover_path, is_website_icon FROM links WHERE user_id=? AND is_active=1 AND link_type='link' ORDER BY sort_order ASC, id ASC LIMIT 6");
    $stmt->execute([$uid]);
    $links = $stmt->fetchAll();

    ob_start();
    ?>
          <div class="col-lg-3 order-2 order-lg-1 adminlte-sidebar-col">
            <?= $extraTopHtml ?>
            <div class="card widget-user-2 mb-0">
              <div class="widget-user-header text-bg-warning">
                <div class="widget-user-image">
                  <img class="rounded-circle shadow" src="<?= e($avatarUrl) ?>" alt="<?= e($artist['display_name']) ?>">
                </div>
                <div>
                  <h3 class="widget-user-username"><?= e($artist['display_name']) ?></h3>
                  <h5 class="widget-user-desc">@<?= e($slug) ?></h5>
                </div>
              </div>
              <div class="card-footer p-0">
                <ul class="nav flex-column">
                  <li class="nav-item">
                    <span class="nav-link link-body-emphasis">
                      Follower
                      <span class="float-end badge text-bg-primary"><?= (int) $followerCount ?></span>
                    </span>
                  </li>
                  <li class="nav-item">
                    <span class="nav-link link-body-emphasis">
                      Contenuti pubblicati
                      <span class="float-end badge text-bg-info"><?= (int) $contentCount ?></span>
                    </span>
                  </li>
                  <li class="nav-item">
                    <span class="nav-link link-body-emphasis">
                      Recensioni
                      <span class="float-end badge text-bg-success"><?= $reviewStats['count'] ? e((string) $reviewStats['avg']) . ' ★' : '–' ?></span>
                    </span>
                  </li>
                </ul>
              </div>
            </div>

            <?php if ($links): ?>
            <div class="card mt-3">
              <div class="card-header"><h3 class="card-title">I miei link</h3></div>
              <div class="list-group list-group-flush">
                <?php foreach ($links as $lk): ?>
                <?php
                    // Stessa icona per piattaforma già usata dal tema Colorful (detectPlatform()):
                    // Instagram, Spotify, YouTube ecc. riconosciuti dal dominio dell'URL. Un link
                    // segnato come "sito web personale" mostra sempre il globo, una copertina
                    // personalizzata (se caricata) sostituisce l'icona con una miniatura.
                    $platform = !empty($lk['is_website_icon'])
                        ? ['icon_class' => 'fa-solid fa-globe', 'label' => 'Sito web']
                        : (detectPlatform($lk['url']) ?? ['icon_class' => 'bi-link-45deg', 'label' => 'Link']);
                ?>
                <a href="/link.php?id=<?= (int) $lk['id'] ?>" target="_blank" rel="noopener" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                  <?php if (!empty($lk['cover_path'])): ?>
                    <img src="/<?= e($lk['cover_path']) ?>" alt="" class="rounded-circle" style="width:28px;height:28px;object-fit:cover;flex-shrink:0;">
                  <?php else: ?>
                    <span class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:28px;height:28px;">
                      <i class="<?= e($platform['icon_class']) ?> text-primary" style="font-size:.85rem;" aria-hidden="true" title="<?= e($platform['label']) ?>"></i>
                    </span>
                  <?php endif; ?>
                  <span class="flex-grow-1 text-truncate"><?= e($lk['label']) ?></span>
                  <i class="bi bi-chevron-right text-secondary small flex-shrink-0" aria-hidden="true"></i>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($artist['bio']) || $citta || !empty($artist['genere']) || $hasSpotify || $hasYoutube || $hasPodcast): ?>
            <div class="card mt-3">
              <div class="card-header"><h3 class="card-title">Chi sono</h3></div>
              <div class="card-body small">
                <?php if (!empty($artist['bio'])): ?>
                  <p class="fw-semibold mb-1"><i class="bi bi-person-lines-fill me-1 text-secondary" aria-hidden="true"></i>Bio</p>
                  <p class="text-secondary mb-3"><?= nl2br(e($artist['bio'])) ?></p>
                <?php endif; ?>
                <?php if ($citta): ?>
                  <p class="fw-semibold mb-1"><i class="bi bi-geo-alt me-1 text-secondary" aria-hidden="true"></i>Località</p>
                  <p class="text-secondary mb-3"><?= e($citta) ?><?= $provincia ? ', ' . e($provincia) : '' ?></p>
                <?php endif; ?>
                <?php if (!empty($artist['genere'])): ?>
                  <p class="fw-semibold mb-1"><i class="bi bi-tags me-1 text-secondary" aria-hidden="true"></i>Genere</p>
                  <p class="mb-3"><span class="badge text-bg-secondary"><?= e($artist['genere']) ?></span></p>
                <?php endif; ?>
                <?php if ($hasSpotify || $hasYoutube || $hasPodcast): ?>
                  <p class="fw-semibold mb-1"><i class="bi bi-link-45deg me-1 text-secondary" aria-hidden="true"></i>Presente su</p>
                  <p class="mb-0">
                    <?php if ($hasSpotify): ?><span class="badge text-bg-success me-1"><i class="bi bi-spotify me-1"></i>Spotify</span><?php endif; ?>
                    <?php if ($hasYoutube): ?><span class="badge text-bg-danger me-1"><i class="bi bi-youtube me-1"></i>YouTube</span><?php endif; ?>
                    <?php if ($hasPodcast): ?><span class="badge text-bg-warning"><i class="bi bi-mic-fill me-1"></i>Podcast</span><?php endif; ?>
                  </p>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($showFollowWidget && !in_array('segui', getHiddenNavKeys($uid), true)): ?>
            <div class="card mt-3" id="segui-widget">
              <div class="card-body text-center">
                <?php if (publicActingUserId() && publicActingUserId() !== $uid): ?>
                  <?php $alreadyFollowing = isFollowingAccount(publicActingUserId(), $uid); ?>
                  <form method="post" action="/follow_account.php" class="d-inline"<?= $alreadyFollowing ? ' onsubmit="return confirm(\'Vuoi smettere di seguire questo profilo?\');"' : '' ?>>
                    <?= csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <input type="hidden" name="action" value="<?= $alreadyFollowing ? 'unfollow' : 'follow' ?>">
                    <input type="hidden" name="redirect" value="/<?= e($slug) ?>">
                    <button type="submit" class="btn <?= $alreadyFollowing ? 'btn-outline-primary' : 'btn-primary' ?>">
                      <i class="bi <?= $alreadyFollowing ? 'bi-person-check-fill' : 'bi-person-plus' ?> me-1" aria-hidden="true"></i><?= $alreadyFollowing ? 'Segui già' : 'Segui' ?>
                    </button>
                  </form>
                  <div class="text-secondary small mt-2"><?= (int) $followerCount ?> ti seguono su <?= e(siteName()) ?></div>
                <?php else: ?>
                  <?php $followTermsContent = trim(getSiteSetting('follow_terms_content') ?: ''); ?>
                  <details id="segui-follow-details">
                    <summary class="btn btn-primary" style="list-style:none;cursor:pointer;">
                      <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Segui
                    </summary>
                    <div class="mt-3 text-start">
                      <p class="small text-secondary">Ti mandiamo un link di conferma via email: dopo averlo aperto ricevi un avviso ogni volta che <?= e($artist['display_name']) ?> pubblica qualcosa di nuovo.</p>
                      <form method="post" action="/follow.php" class="d-flex flex-wrap gap-2 align-items-start">
                        <?= csrfField() ?>
                        <input type="hidden" name="slug" value="<?= e($slug) ?>">
                        <input type="email" name="email" class="form-control form-control-sm flex-grow-1" placeholder="La tua email" required style="min-width:160px;">
                        <?php if ($followTermsContent !== ''): ?>
                        <label class="form-check d-flex align-items-start gap-2 w-100 small">
                          <input type="checkbox" name="accept_terms" value="1" class="form-check-input mt-1" required>
                          <span>Accetto i <a href="/termini_segui.php" target="_blank" rel="noopener">Termini di Utilizzo</a></span>
                        </label>
                        <?php endif; ?>
                        <?= renderTurnstileWidget() ?>
                        <button type="submit" class="btn btn-primary btn-sm">Conferma</button>
                      </form>
                    </div>
                  </details>
                  <div class="text-secondary small mt-2">
                    <?= $followerCount > 0 ? $followerCount . ($followerCount === 1 ? ' persona segue' : ' persone seguono') : 'ricevi una notifica quando pubblica' ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <script>
            (function () {
              // Sia il "+" del profilo sia "Segui" nella barra di navigazione puntano qui
              // (/slug#segui-widget), anche da un'altra pagina: se il modulo email esiste
              // (visitatore non loggato), lo apre subito e ci mette il focus, invece di lasciare
              // un secondo click per aprirlo dopo lo scroll.
              function openFollowWidget(force) {
                if (force !== true && window.location.hash !== '#segui-widget') return;
                var details = document.getElementById('segui-follow-details');
                if (details && !details.open) {
                  details.open = true;
                  var emailInput = details.querySelector('input[name="email"]');
                  if (emailInput) { setTimeout(function () { emailInput.focus(); }, 50); }
                }
                var widget = document.getElementById('segui-widget');
                if (widget) { widget.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
              }
              document.addEventListener('DOMContentLoaded', function () { openFollowWidget(); });
              window.addEventListener('hashchange', function () { openFollowWidget(); });
              // Ad ogni clic su un link "Segui" (barra in alto, "+" del profilo): se si è già in
              // questa pagina l'ancora c'è già e il browser non genera più hashchange, quindi il
              // modulo non si riapriva più dopo il primo clic.
              document.querySelectorAll('a[href$="#segui-widget"]').forEach(function (a) {
                a.addEventListener('click', function (ev) {
                  if (a.pathname.replace(/\/$/, '') !== window.location.pathname.replace(/\/$/, '')) return;
                  ev.preventDefault();
                  if (window.location.hash !== '#segui-widget') { history.replaceState(null, '', '#segui-widget'); }
                  openFollowWidget(true);
                });
              });
            })();
            </script>
            <?php endif; ?>
          </div>
    <?php
    return ob_get_clean();
}


// Terza colonna condivisa da TUTTE le pagine pubbliche a tema AdminLTE: solo "Che Amo" (accesso
// rapido ai moduli con contenuto, stessa vetrina dietro al tab omonimo nella barra di
// navigazione). "I miei link" e "Chi sono" sono passati alla colonna del profilo
// (renderAdminLteProfileSidebar) per bilanciare le due colonne laterali. Stesso principio
// "self-contained" delle altre funzioni condivise di questo tema: calcola da sé i propri dati a
// partire da $artist['id']. L'ordine visivo (colonna profilo, poi contenuto, poi questa) è
// fissato via CSS (order-*), non dalla posizione nel markup: così può essere richiamata subito
// dopo la sidebar in ogni pagina, invece di dover trovare il punto esatto di chiusura della
// colonna centrale in ciascuna delle funzioni che la usano.
function renderAdminLteProfileExtras(array $artist, string $slug, string $extraCardsHtml = '', bool $extraCardsFirst = false, ?string $activeProvincia = null): string {
    // Veste Academic: niente colonna destra (Primo Piano, Eventi per provincia, Che Amo). Le sole schede specifiche della
    // pagina (categorie del blog, ultimi articoli, copertina...) restano, sotto il contenuto.
    if (academicSkinActive()) {
        return $extraCardsHtml === '' ? '' : '<div class="col-12 order-3 adminlte-extras-col">' . $extraCardsHtml . '</div>';
    }
    $uid = (int) $artist['id'];
    $db = getDB();

    // Due card sempre calcolate qui (non passate da chi chiama, a differenza di $extraCardsHtml):
    // vanno mostrate su OGNI pagina AdminLTE, non solo su alcune — vedi le rispettive funzioni per
    // i dettagli. Sono vuote ('') e quindi invisibili quando non c'è nulla da mostrare.
    $provinciaCardHtml = renderAdminLteEventiByProvinciaCard($uid, $slug, $activeProvincia);
    $pinnedCardHtml = renderAdminLtePinnedSidebarCard(getPinnedItemsForUser($uid));

    // Stessa regola di visibilità di che_amo.php (moduli non nascosti da "Menu di Navigazione"
    // e con contenuto effettivo): il widget qui sotto elenca esattamente le stesse voci che
    // compaiono sulla vetrina "Che Amo" dietro al tab omonimo, mai svuotato dalla barra
    // orizzontale (resta lì, questo è solo un accesso rapido in più dalla colonna laterale).
    // Ogni voce visibile porta anche il conteggio dei suoi elementi (es. "Attori che amo").
    $hiddenKeys = getHiddenNavKeys($uid);
    $cheAmoItems = [];
    if (!in_array('cheamo', $hiddenKeys, true) && !academicSkinActive()) {
        foreach (CHE_AMO_MODULES as $key => $m) {
            if (in_array($key, $hiddenKeys, true)) {
                continue;
            }
            if ($m['check'] === null || $m['check']($uid)) {
                $stmt = $db->prepare("SELECT COUNT(*) c FROM {$m['table']} WHERE user_id=? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
                $stmt->execute([$uid]);
                $m['count'] = (int) $stmt->fetch()['c'];
                $cheAmoItems[$key] = $m;
            }
        }
    }

    if (!$cheAmoItems && $extraCardsHtml === '' && $provinciaCardHtml === '' && $pinnedCardHtml === '') {
        return '';
    }

    // "Che Amo" costruita a parte perché ha un markup più elaborato (loop con icone/colori), le
    // altre tre arrivano già come HTML di una .card completa — tutte e quattro finiscono in questo
    // stesso array ordinato, così il margine tra una e l'altra si applica in un unico posto invece
    // che ripetuto in ognuna (mai un buco vuoto in cima né un doppio margine in mezzo).
    $cheAmoHtml = '';
    if ($cheAmoItems) {
        ob_start();
        ?>
            <div class="card">
              <div class="card-header"><h3 class="card-title">Che Amo</h3></div>
              <div class="card-body">
                <?php $cheAmoColors = ['primary', 'success', 'warning', 'danger', 'info', 'secondary']; $i = 0; ?>
                <?php foreach ($cheAmoItems as $key => $m): ?>
                <a href="/<?= e($slug) ?>/<?= e($m['segment']) ?>" class="info-box text-decoration-none text-body<?= $i > 0 ? ' mt-2' : '' ?>">
                  <span class="info-box-icon text-bg-<?= $cheAmoColors[$i % count($cheAmoColors)] ?> shadow-sm">
                    <i class="bi <?= e(ADMINLTE_CHE_AMO_ICONS[$key] ?? 'bi-heart') ?>" aria-hidden="true"></i>
                  </span>
                  <div class="info-box-content">
                    <span class="info-box-text"><?= e($m['label']) ?></span>
                    <span class="info-box-number"><?= (int) $m['count'] ?></span>
                  </div>
                </a>
                <?php $i++; endforeach; ?>
              </div>
            </div>
        <?php
        $cheAmoHtml = ob_get_clean();
    }

    // Normalmente "Eventi per provincia" e "In Primo Piano" restano sempre in cima, su ogni
    // pagina AdminLTE (era il punto esplicito della richiesta che le ha introdotte) — $extraCardsFirst
    // inverte l'ordine per chi ne ha bisogno (la pagina di un articolo del blog: lì l'utente vuole
    // prima il contesto dell'articolo — Ultimi articoli/copertina/categoria — e solo dopo i widget
    // generici del profilo).
    $blocks = $extraCardsFirst
        ? array_values(array_filter([$extraCardsHtml, $provinciaCardHtml, $pinnedCardHtml, $cheAmoHtml], fn ($h) => $h !== ''))
        : array_values(array_filter([$provinciaCardHtml, $pinnedCardHtml, $extraCardsHtml, $cheAmoHtml], fn ($h) => $h !== ''));

    ob_start();
    ?>
          <div class="col-lg-3 order-3 order-lg-3 adminlte-extras-col">
            <?php foreach ($blocks as $i => $block): ?>
            <div class="<?= $i > 0 ? 'mt-3' : '' ?>"><?= $block ?></div>
            <?php endforeach; ?>
          </div>
    <?php
    return ob_get_clean();
}


// Card "Eventi per provincia" (colonna destra, sempre in cima) — prossimi eventi del profilo
// raggruppati per provincia (colonna events.provincia, opzionale in dashboard_events.php),
// ordinati alfabeticamente, con il conteggio sulla stessa riga. Il click porta alla pagina
// Eventi filtrata per quella provincia (eventi.php?provincia=...). Nessuna card se il profilo non
// ha eventi con provincia compilata (né se non ha proprio eventi). $activeProvincia evidenzia la
// provincia dell'evento corrente quando la card compare sulla pagina di un evento (dettaglio),
// invece di lasciarla indistinguibile dalle altre.
function renderAdminLteEventiByProvinciaCard(int $userId, string $slug, ?string $activeProvincia = null): string {
    $stmt = getDB()->prepare("SELECT provincia, COUNT(*) c FROM events
        WHERE user_id=? AND provincia IS NOT NULL AND provincia <> '' AND (event_date >= NOW() OR is_perpetual = 1) AND (publish_at IS NULL OR publish_at <= NOW())
        GROUP BY provincia ORDER BY provincia ASC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return '';
    }
    ob_start();
    ?>
            <div class="card">
              <div class="card-header"><h3 class="card-title">Eventi per provincia</h3></div>
              <div class="list-group list-group-flush">
                <?php foreach ($rows as $r): ?>
                <?php $isActive = $activeProvincia !== null && $activeProvincia === $r['provincia']; ?>
                <a href="/<?= e($slug) ?>/eventi?provincia=<?= urlencode($r['provincia']) ?>" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between<?= $isActive ? ' active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                  <span><i class="bi bi-geo-alt<?= $isActive ? '' : ' text-secondary' ?> me-1" aria-hidden="true"></i><?= e($r['provincia']) ?></span>
                  <span class="badge <?= $isActive ? 'text-bg-light' : 'text-bg-primary' ?> rounded-pill"><?= (int) $r['c'] ?></span>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
    <?php
    return ob_get_clean();
}


// Card "In Primo Piano" permanente (colonna destra, su OGNI pagina AdminLTE) — stessi elementi di
// renderAdminLtePinnedCarousel() (Dashboard -> Primo Piano), ma come semplice elenco invece che
// carosello: funziona anche con un solo elemento fissato (il carosello invece richiede almeno 2
// slide per avere senso, vedi lì). Nessuna card se il profilo non ha ancora fissato nulla.
function renderAdminLtePinnedSidebarCard(array $pinnedItems): string {
    if (!$pinnedItems) {
        return '';
    }
    ob_start();
    ?>
            <div class="card">
              <div class="card-header"><h3 class="card-title"><i class="bi bi-pin-angle-fill me-1"></i>In Primo Piano</h3></div>
              <div class="list-group list-group-flush">
                <?php foreach ($pinnedItems as $it): ?>
                <a href="<?= e($it['url']) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                  <?php if ($it['cover']): ?>
                    <img src="/<?= e($it['cover']) ?>" style="width:36px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;" alt="">
                  <?php else: ?>
                    <span class="d-flex align-items-center justify-content-center bg-body-secondary rounded flex-shrink-0" style="width:36px;height:36px;">
                      <i class="bi <?= e(ADMINLTE_TIMELINE_TYPE_META[$it['tipo']]['icon'] ?? 'bi-star') ?> text-secondary" aria-hidden="true"></i>
                    </span>
                  <?php endif; ?>
                  <span class="text-truncate small"><?= e(textExcerpt((string) $it['titolo'], 60)) ?></span>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
    <?php
    return ob_get_clean();
}


// Filtro di ricerca del Blog pubblico (tema AdminLTE): parola chiave (minimo 3 caratteri, cerca in
// titolo, riassunto e testo) + una o più categorie. Le categorie accettate sono SOLO quelle
// dell'artista (gli id arrivati dall'URL vengono incrociati con blog_categories del suo
// user_id), la parola chiave finisce in una query preparata con i caratteri jolly neutralizzati.
function blogFilterFromRequest(int $userId, array $src): array {
    $q = trim((string) ($src['q'] ?? ''));
    if (mb_strlen($q) < 3) {
        $q = '';
    }
    $q = mb_substr($q, 0, 100);
    $cats = [];
    $raw = $src['cat'] ?? [];
    if (is_array($raw) && $raw) {
        $stmt = getDB()->prepare('SELECT id FROM blog_categories WHERE user_id=?');
        $stmt->execute([$userId]);
        $valid = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        $cats = array_values(array_intersect(array_unique(array_map('intval', $raw)), $valid));
    }
    return ['q' => $q, 'cats' => $cats];
}


// Condizioni SQL (da aggiungere a "WHERE user_id=? AND published_at <= NOW()") e parametri del filtro.
function blogFilterSql(array $filter): array {
    $sql = '';
    $params = [];
    if (($filter['q'] ?? '') !== '') {
        $like = '%' . addcslashes($filter['q'], '\\%_') . '%';
        $sql .= ' AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (!empty($filter['cats'])) {
        $ph = implode(',', array_fill(0, count($filter['cats']), '?'));
        $sql .= " AND EXISTS (SELECT 1 FROM blog_post_categories bpc WHERE bpc.post_id = blog_posts.id AND bpc.category_id IN ($ph))";
        foreach ($filter['cats'] as $cid) {
            $params[] = (int) $cid;
        }
    }
    return [$sql, $params];
}


// Parte di query string che riporta il filtro allo scroll infinito (adminlte_list_more.php).
function blogFilterQueryString(array $filter): string {
    $out = '';
    if (($filter['q'] ?? '') !== '') {
        $out .= '&q=' . rawurlencode($filter['q']);
    }
    foreach ($filter['cats'] ?? [] as $cid) {
        $out .= '&cat[]=' . (int) $cid;
    }
    return $out;
}


// Card "Filtra" in cima alla colonna sinistra del Blog: ricerca per parola chiave e tipologie
// (= categorie del blog dell'artista, quindi sempre aggiornate). Form GET: funziona anche senza
// JavaScript e il risultato è un URL condivisibile. Senza categorie resta solo la ricerca.
function renderAdminLteBlogFilterCard(int $userId, string $slug, array $filter, ?int $total = null): string {
    $stmt = getDB()->prepare('SELECT id, name FROM blog_categories WHERE user_id=? ORDER BY name ASC');
    $stmt->execute([$userId]);
    $categories = $stmt->fetchAll();
    $active = ($filter['q'] ?? '') !== '' || !empty($filter['cats']);
    ob_start();
    ?>
            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title text-uppercase small fw-bold mb-0">Filtra</h3></div>
              <form method="get" action="<?= e('/' . $slug . '/blog') ?>" class="card-body">
                <label for="blog-filter-q" class="form-label fw-semibold">Cerca per parola chiave</label>
                <div class="input-group mb-3">
                  <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search" aria-hidden="true"></i></span>
                  <input type="search" id="blog-filter-q" name="q" class="form-control border-start-0" minlength="3" maxlength="100"
                         placeholder="Minimo 3 caratteri" value="<?= e($filter['q'] ?? '') ?>">
                </div>
                <?php if ($categories): ?>
                <fieldset class="mb-3">
                  <legend class="form-label fw-semibold fs-6">Tipologia notizia</legend>
                  <div class="form-text mb-2">Puoi selezionare più tipologie.</div>
                  <div class="border rounded p-2" style="max-height:170px;overflow-y:auto;">
                    <?php foreach ($categories as $cat): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="cat[]" value="<?= (int) $cat['id'] ?>" id="blog-filter-cat-<?= (int) $cat['id'] ?>"<?= in_array((int) $cat['id'], $filter['cats'] ?? [], true) ? ' checked' : '' ?>>
                      <label class="form-check-label" for="blog-filter-cat-<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></label>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </fieldset>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary w-100">Cerca</button>
                <?php if ($active): ?>
                <a href="<?= e('/' . $slug . '/blog') ?>" class="btn btn-outline-secondary w-100 mt-2">Azzera i filtri</a>
                <?php endif; ?>
                <?php if ($total !== null && $active): ?>
                <div class="text-secondary small mt-3"><?= (int) $total ?> articol<?= $total === 1 ? 'o trovato' : 'i trovati' ?></div>
                <?php endif; ?>
              </form>
            </div>
    <?php
    return ob_get_clean();
}


// Card "Categorie del blog" (colonna destra) — elenco alfabetico delle categorie dell'artista,
// ognuna verso la sua pagina pubblica filtrata (blogCategoryUrl()). Passata come $extraCardsHtml a
// renderAdminLteProfileExtras() (che ora la mostra per prima, con "Che Amo" subito sotto) su
// tutte e tre le pagine pubbliche del blog: indice, categoria e articolo. Nessuna card se
// l'artista non ha ancora categorie. $activeCategoryId evidenzia la categoria corrente quando la
// card compare sulla sua stessa pagina, invece di lasciarla indistinguibile dalle altre.
function renderAdminLteBlogCategoriesNavCard(int $userId, string $slug, ?int $activeCategoryId = null): string {
    $html = renderAdminLteBlogCategoriesNavCardRaw($userId, $slug, $activeCategoryId);
    // Veste Academic: le categorie stanno nella colonna sinistra, sotto il profilo, non sotto il contenuto.
    if (academicSkinActive()) {
        academicSidebarSlot($html);
        return '';
    }
    return $html;
}

function renderAdminLteBlogCategoriesNavCardRaw(int $userId, string $slug, ?int $activeCategoryId = null): string {
    $stmt = getDB()->prepare('SELECT id, name, slug FROM blog_categories WHERE user_id=? ORDER BY name ASC');
    $stmt->execute([$userId]);
    $categories = $stmt->fetchAll();
    if (!$categories) {
        return '';
    }
    ob_start();
    ?>
            <div class="card">
              <div class="card-header"><h3 class="card-title">Categorie del blog</h3></div>
              <div class="list-group list-group-flush">
                <?php foreach ($categories as $cat): ?>
                <?php $isActive = $activeCategoryId !== null && (int) $cat['id'] === $activeCategoryId; ?>
                <a href="<?= e(blogCategoryUrl($slug, $cat)) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2<?= $isActive ? ' active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                  <i class="bi bi-folder2<?= $isActive ? '' : ' text-secondary' ?>" aria-hidden="true"></i>
                  <span class="flex-grow-1"><?= e($cat['name']) ?></span>
                  <i class="bi bi-chevron-right<?= $isActive ? '' : ' text-secondary' ?> small" aria-hidden="true"></i>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
    <?php
    return ob_get_clean();
}


// Card "Ultimi articoli" per la colonna laterale destra della pagina di un singolo articolo
// (renderAdminLteBlogPostPage()) — stesso stile di renderAdminLtePinnedSidebarCard(), esclude
// l'articolo che si sta già leggendo.
function renderAdminLteBlogLatestPostsCard(int $userId, string $slug, int $excludePostId, int $limit = 3): string {
    $stmt = getDB()->prepare('SELECT id, title, slug, cover_path, published_at FROM blog_posts WHERE user_id=? AND id<>? AND published_at <= NOW() ORDER BY published_at DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $excludePostId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
    if (!$posts) {
        return '';
    }
    ob_start();
    ?>
            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title">Ultimi articoli</h3></div>
              <div class="list-group list-group-flush">
                <?php foreach ($posts as $p): ?>
                <a href="<?= e(blogPostUrl($slug, $p)) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                  <?php if ($p['cover_path']): ?>
                    <img src="/<?= e($p['cover_path']) ?>" style="width:36px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;" alt="">
                  <?php else: ?>
                    <span class="d-flex align-items-center justify-content-center bg-body-secondary rounded flex-shrink-0" style="width:36px;height:36px;">
                      <i class="bi bi-newspaper text-secondary" aria-hidden="true"></i>
                    </span>
                  <?php endif; ?>
                  <span class="text-truncate small"><?= e(textExcerpt((string) $p['title'], 60)) ?></span>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
    <?php
    return ob_get_clean();
}


function renderAdminLteGridCarousel(string $title, string $icon, string $footerUrl, string $footerLabel, array $tiles, string $imageShape = 'circle'): string {
    if (!$tiles) {
        return '';
    }
    $perPage = 8;
    $totalPages = (int) ceil(count($tiles) / $perPage);
    $cid = 'grid-' . substr(md5($title), 0, 8);
    $imgStyle = $imageShape === 'circle'
        ? 'width:64px;height:64px;border-radius:50%;object-fit:cover;'
        : 'width:80px;height:80px;border-radius:10px;object-fit:cover;';
    $phStyle = $imageShape === 'circle'
        ? 'width:64px;height:64px;border-radius:50%;'
        : 'width:80px;height:80px;border-radius:10px;';
    ob_start();
    ?>
                <div class="card mb-3">
                  <div class="card-header d-flex align-items-center">
                    <h3 class="card-title flex-grow-1"><i class="bi <?= e($icon) ?> me-2"></i><?= e($title) ?></h3>
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex gap-1">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-grid-nav="prev" data-target="<?= $cid ?>" disabled><i class="bi bi-chevron-left"></i></button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-grid-nav="next" data-target="<?= $cid ?>"><i class="bi bi-chevron-right"></i></button>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div class="card-body">
                    <?php for ($page = 0; $page < $totalPages; $page++):
                      $pageTiles = array_slice($tiles, $page * $perPage, $perPage);
                    ?>
                    <div class="row g-2 <?= $cid ?>-page" <?php if ($page > 0): ?>style="display:none;"<?php endif; ?> data-page="<?= $page ?>">
                      <?php foreach ($pageTiles as $t): ?>
                      <div class="col-6 col-sm-4 col-lg-3">
                        <a href="<?= e($t['url']) ?>" class="card text-decoration-none text-body p-3 h-100 text-center">
                          <?php if (!empty($t['image'])): ?>
                            <img src="<?= e($t['image']) ?>" alt="" loading="lazy" class="mx-auto mb-2 d-block" style="<?= $imgStyle ?>">
                          <?php else: ?>
                            <span class="mx-auto mb-2 d-flex align-items-center justify-content-center bg-body-secondary" style="<?= $phStyle ?>"><i class="bi <?= e($t['placeholder_icon'] ?? $icon) ?> fs-4 text-secondary"></i></span>
                          <?php endif; ?>
                          <div class="fw-semibold small text-truncate"><?= e($t['title']) ?></div>
                          <?php if (!empty($t['subtitle'])): ?><div class="text-secondary text-truncate" style="font-size:11.5px;"><?= e($t['subtitle']) ?></div><?php endif; ?>
                          <?php if (!empty($t['date'])): ?><small class="text-secondary" style="font-size:11px;"><?= e($t['date']) ?></small><?php endif; ?>
                        </a>
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <?php endfor; ?>
                  </div>
                  <?php if ($footerUrl): ?>
                  <div class="card-footer text-center">
                    <a href="<?= e($footerUrl) ?>" class="text-decoration-none small"><?= e($footerLabel) ?></a>
                  </div>
                  <?php endif; ?>
                </div>
                <?php if ($totalPages > 1): ?>
                <script>
                (function(){
                  var id='<?= $cid ?>';
                  var cur=0, max=<?= $totalPages - 1 ?>;
                  document.addEventListener('click',function(e){
                    var b=e.target.closest('[data-grid-nav]');
                    if(!b||b.dataset.target!==id)return;
                    var pages=document.querySelectorAll('.'+id+'-page');
                    pages[cur].style.display='none';
                    cur+=b.dataset.gridNav==='next'?1:-1;
                    cur=Math.max(0,Math.min(max,cur));
                    pages[cur].style.display='';
                    b.closest('.d-flex').querySelector('[data-grid-nav="prev"]').disabled=cur===0;
                    b.closest('.d-flex').querySelector('[data-grid-nav="next"]').disabled=cur===max;
                  });
                })();
                </script>
                <?php endif; ?>
    <?php
    return ob_get_clean();
}


function renderAdminLteBlogInterstitial(int $userId, string $slug, ?array $profile = null): string {
    $stmt = getDB()->prepare('SELECT id, title, slug, cover_path, published_at FROM blog_posts WHERE user_id=? AND published_at <= NOW() ORDER BY published_at DESC LIMIT 8');
    $stmt->execute([$userId]);
    $posts = $stmt->fetchAll();
    if (!$posts) {
        return '';
    }
    $tiles = [];
    foreach ($posts as $p) {
        $tiles[] = [
            'url' => blogPostUrl($slug, $p),
            'image' => $p['cover_path'] ? '/' . $p['cover_path'] : '',
            'placeholder_icon' => 'bi-newspaper',
            'title' => $p['title'],
            'date' => formatItalianDate($p['published_at'], $profile),
        ];
    }
    return renderAdminLteGridCarousel('Ultimi articoli', 'bi-newspaper', '/' . $slug . '/blog', 'Leggi il Blog', $tiles, 'square');
}


function renderAdminLteYoutubeInterstitial(array $artist, string $slug): string {
    if (empty($artist['youtube_channel_id'])) {
        return '';
    }
    require_once __DIR__ . '/youtube.php';
    $uploadsPlaylistId = 'UU' . substr($artist['youtube_channel_id'], 2);
    $videos = youtubeGetChannelVideos($uploadsPlaylistId, 8);
    if (!$videos) {
        return '';
    }
    $tiles = [];
    foreach ($videos as $v) {
        $tiles[] = [
            'url' => '/' . $slug . '/youtube',
            'image' => $v['thumbnail'] ?? '',
            'placeholder_icon' => 'bi-play-btn',
            'title' => $v['title'],
            'date' => $v['published_at'] ? date('d/m/Y', strtotime($v['published_at'])) : '',
        ];
    }
    return renderAdminLteGridCarousel('Ultimi video su YouTube', 'bi-youtube', '/' . $slug . '/youtube', 'Tutti i video YouTube', $tiles, 'square');
}


function renderAdminLteCheAmoInterstitial(int $userId, string $slug): string {
    $db = getDB();
    $tiles = [];
    foreach (ADMINLTE_FAN_FAVORITE_KINDS as $kind => $cfg) {
        try {
        $nameCol = $cfg['name_col'];
        $imgCol = $cfg['image_col'];
        $tbl = $cfg['table'];
        $stmt = $db->prepare("SELECT id, {$nameCol} AS name, image_thumb_path, image_path, created_at FROM {$tbl} WHERE user_id=? AND is_public=1 AND (publish_at IS NULL OR publish_at <= NOW()) ORDER BY created_at DESC LIMIT 4");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $r) {
            $img = $r['image_thumb_path'] ?: ($r['image_path'] ?: '');
            $tiles[] = [
                'url' => '/' . $slug . '/' . $cfg['list_url_segment'] . '/' . $r['id'],
                'image' => $img,
                'placeholder_icon' => 'bi-heart',
                'title' => $r['name'],
                'subtitle' => $cfg['label'],
                'sort' => $r['created_at'],
            ];
        }
        } catch (\Throwable $e) {}
    }
    if (!$tiles) {
        return '';
    }
    usort($tiles, fn ($a, $b) => strtotime($b['sort'] ?? '0') <=> strtotime($a['sort'] ?? '0'));
    $tiles = array_slice($tiles, 0, 16);
    foreach ($tiles as &$t) { unset($t['sort']); }
    return renderAdminLteGridCarousel('Che Amo', 'bi-heart', '/' . $slug . '/che-amo', 'Scopri tutto', $tiles, 'circle');
}


// Solo gli eventi di questo mese che devono ancora cominciare (stessa regola delle pagine Eventi: da
// adesso in poi), più quelli permanenti: quelli già passati non compaiono.
function renderAdminLteMonthlyEventsGrid(int $userId, string $slug, ?array $profile = null): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, title, venue, city, cover_path, event_date, is_perpetual
        FROM events
        WHERE user_id = ? AND (
            (event_date >= NOW() AND event_date < DATE_FORMAT(NOW() + INTERVAL 1 MONTH, '%Y-%m-01'))
            OR is_perpetual = 1
        ) AND (publish_at IS NULL OR publish_at <= NOW())
        ORDER BY is_perpetual ASC, event_date ASC
        LIMIT 50");
    $stmt->execute([$userId]);
    $events = $stmt->fetchAll();
    if (!$events) {
        return '';
    }
    $perPage = 8;
    $totalPages = (int) ceil(count($events) / $perPage);
    $carouselId = 'evt-month-' . $userId;
    ob_start();
    ?>
                <div class="card mb-3">
                  <div class="card-header d-flex align-items-center">
                    <h3 class="card-title flex-grow-1"><i class="bi bi-calendar-event me-2"></i>Gli eventi del mese</h3>
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex gap-1">
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-evt-nav="prev" data-target="<?= $carouselId ?>" disabled><i class="bi bi-chevron-left"></i></button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-evt-nav="next" data-target="<?= $carouselId ?>"><i class="bi bi-chevron-right"></i></button>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div class="card-body">
                    <?php for ($page = 0; $page < $totalPages; $page++):
                      $pageEvents = array_slice($events, $page * $perPage, $perPage);
                    ?>
                    <div class="row g-2 <?= $carouselId ?>-page" <?php if ($page > 0): ?>style="display:none;"<?php endif; ?> data-page="<?= $page ?>">
                      <?php foreach ($pageEvents as $ev):
                        $coverUrl = $ev['cover_path'] ? '/' . $ev['cover_path'] : '';
                        $evDate = '';
                        if ($ev['is_perpetual']) {
                            $evDate = 'Permanente';
                        } else {
                            $evDate = formatItalianDate($ev['event_date'], $profile);
                        }
                        $location = trim(($ev['venue'] ?? '') . ($ev['city'] ? ', ' . $ev['city'] : ''));
                      ?>
                      <div class="col-6 col-sm-4 col-lg-3">
                        <a href="/<?= e($slug) ?>/eventi/<?= (int) $ev['id'] ?>" class="card text-decoration-none text-body p-3 h-100 text-center">
                          <?php if ($coverUrl): ?>
                            <img src="<?= e($coverUrl) ?>" alt="" loading="lazy" class="mx-auto mb-2 d-block" style="width:64px;height:64px;border-radius:50%;object-fit:cover;">
                          <?php else: ?>
                            <span class="mx-auto mb-2 d-flex align-items-center justify-content-center bg-body-secondary" style="width:64px;height:64px;border-radius:50%;"><i class="bi bi-calendar-event fs-4 text-secondary"></i></span>
                          <?php endif; ?>
                          <div class="fw-semibold small text-truncate"><?= e($ev['title']) ?></div>
                          <?php if ($location): ?><div class="text-secondary" style="font-size:11.5px;"><?= e($location) ?></div><?php endif; ?>
                          <small class="text-secondary" style="font-size:11px;"><?= e($evDate) ?></small>
                        </a>
                      </div>
                      <?php endforeach; ?>
                    </div>
                    <?php endfor; ?>
                  </div>
                  <div class="card-footer text-center">
                    <a href="/<?= e($slug) ?>/eventi" class="text-decoration-none small"><i class="bi bi-calendar3 me-1"></i>Tutti gli eventi</a>
                  </div>
                </div>
                <?php if ($totalPages > 1): ?>
                <script>
                (function(){
                  var id='<?= $carouselId ?>';
                  var cur=0, max=<?= $totalPages - 1 ?>;
                  document.addEventListener('click',function(e){
                    var b=e.target.closest('[data-evt-nav]');
                    if(!b||b.dataset.target!==id)return;
                    var pages=document.querySelectorAll('.'+id+'-page');
                    pages[cur].style.display='none';
                    cur+=b.dataset.evtNav==='next'?1:-1;
                    cur=Math.max(0,Math.min(max,cur));
                    pages[cur].style.display='';
                    b.closest('.d-flex').querySelector('[data-evt-nav="prev"]').disabled=cur===0;
                    b.closest('.d-flex').querySelector('[data-evt-nav="next"]').disabled=cur===max;
                  });
                })();
                </script>
                <?php endif; ?>
    <?php
    return ob_get_clean();
}


// Righe della Timeline in stile "social" AdminLTE: ogni elemento è una .card a sé (componente
// nativo .card/.user-block, mescolato con la griglia foto del pattern .post) con intestazione
// avatar+nome+badge tipo+data/ora, foto a piena larghezza o griglia, link "Apri" nel card-footer:
// usata sia per il primo carico che, tramite timeline_more.php, per le pagine successive dello
// scroll infinito della pagina Timeline standalone. Tutti gli item appartengono allo stesso
// profilo, quindi avatar/nome vengono presi una sola volta da $artist invece che dal singolo item.
function renderAdminLteTimelineRows(array $items, array $artist): string {
    // Foto aggiuntive dei post "pensiero" con più foto (carosello) presenti in questa pagina di
    // risultati: un'unica query per tutte invece di una per post, stesso principio del conteggio
    // già fatto in getTimelineFeedForUsers().
    $extraPhotosByPost = [];
    $multiPhotoIds = [];
    foreach ($items as $it) {
        if (($it['tipo'] ?? '') === 'pensiero' && !empty($it['has_multi_photo']) && !empty($it['id'])) {
            $multiPhotoIds[] = (int) $it['id'];
        }
    }
    if ($multiPhotoIds) {
        $ph = implode(',', array_fill(0, count($multiPhotoIds), '?'));
        $stmt = getDB()->prepare("SELECT post_id, image_path FROM timeline_post_photos WHERE post_id IN ($ph) ORDER BY sort_order ASC, id ASC");
        $stmt->execute($multiPhotoIds);
        foreach ($stmt->fetchAll() as $r) {
            $extraPhotosByPost[(int) $r['post_id']][] = $r['image_path'];
        }
    }

    $avatarUrl = adminLteAvatarUrl($artist);
    $displayName = $artist['display_name'] ?? '';

    // Elementi omogenei (stesso 'tipo') pubblicati nella stessa giornata locale del proprietario
    // vengono raggruppati in un'unica card con un mini-carosello interno, invece di N card
    // separate identiche a colpo d'occhio (stesso avatar/badge, cambia solo immagine/titolo) —
    // es. 3 "Calciatori che amo" aggiunti lo stesso giorno diventano una sola card con 3 slide.
    // Il raggruppamento guarda tutti gli elementi di questa pagina, non solo quelli consecutivi
    // (tra due Calciatori potrebbe essersi inserito un Blog pubblicato nel mezzo): può quindi
    // capitare che un gruppo finisca diviso in due se il confine tra una pagina di scroll
    // infinito e la successiva cade a metà — compromesso accettato, questi moduli non sono mai
    // molto frequenti.
    // In standby su richiesta esplicita (29/09/2026): $groupingEnabled a false disattiva il
    // raggruppamento senza toccare il resto della logica sotto — per riattivarla basta rimetterlo
    // a true.
    $groupingEnabled = false;
    $groupKeys = [];
    $groupCounts = [];
    foreach ($items as $it) {
        $dayKey = formatLocalDateTime($it['data'], ['dashboard_theme' => $it['owner_tz'] ?? null], 'd/m/Y');
        $groupKey = $dayKey . '|' . $it['tipo'];
        $groupKeys[] = $groupKey;
        $groupCounts[$groupKey] = ($groupCounts[$groupKey] ?? 0) + 1;
    }

    $eventGroupCounts = [];
    foreach ($items as $it) {
        if (($it['tipo'] ?? '') === 'evento') {
            $eKey = 'evt|' . mb_strtolower(trim($it['titolo']));
            $eventGroupCounts[$eKey] = ($eventGroupCounts[$eKey] ?? 0) + 1;
        }
    }

    ob_start();
    $renderedGroups = [];
    $renderedEventGroups = [];
    foreach ($items as $i => $it):
        $groupKey = $groupKeys[$i];
        if (isset($renderedGroups[$groupKey])) {
            continue;
        }

        if (($it['tipo'] ?? '') === 'evento') {
            $eKey = 'evt|' . mb_strtolower(trim($it['titolo']));
            if ($eventGroupCounts[$eKey] > 1) {
                if (isset($renderedEventGroups[$eKey])) {
                    continue;
                }
                $renderedEventGroups[$eKey] = true;
                $evtGroup = [];
                foreach ($items as $other) {
                    if (($other['tipo'] ?? '') === 'evento' && mb_strtolower(trim($other['titolo'])) === mb_strtolower(trim($it['titolo']))) {
                        $evtGroup[] = $other;
                    }
                }
                usort($evtGroup, fn ($a, $b) => strtotime($a['evento_quando']) <=> strtotime($b['evento_quando']));
                $meta = ADMINLTE_TIMELINE_TYPE_META['evento'] ?? ['icon' => 'bi-star', 'color' => 'primary', 'label' => 'Evento'];
                $coverUrl = '';
                if (!empty($it['cover'])) {
                    $coverUrl = str_starts_with($it['cover'], 'http') ? $it['cover'] : '/' . $it['cover'];
                }
                ?>
        <div class="card mb-3">
          <div class="card-header">
            <div class="user-block">
              <img src="<?= e($avatarUrl) ?>" alt="<?= e($displayName) ?>" class="rounded-circle">
              <span class="username"><?= e($displayName) ?></span>
              <span class="description">
                <span class="badge text-bg-<?= $meta['color'] ?>"><i class="bi <?= e($meta['icon']) ?> me-1"></i><?= e($meta['label']) ?> (<?= count($evtGroup) ?> date)</span>
              </span>
            </div>
            <div class="card-tools">
              <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
              </button>
            </div>
          </div>
          <div class="card-body">
            <?php if ($coverUrl): ?>
            <img src="<?= e($coverUrl) ?>" alt="" loading="lazy" class="img-fluid rounded mb-3">
            <?php endif; ?>
            <p class="fw-semibold mb-3"><?= e($it['titolo']) ?></p>
            <div class="list-group list-group-flush">
              <?php foreach ($evtGroup as $ev):
                $evDate = formatLocalDateTime($ev['evento_quando'], ['dashboard_theme' => $ev['owner_tz'] ?? null], 'd/m/Y · H:i');
                $evLocation = trim(($ev['evento_venue'] ?? '') . ($ev['evento_city'] ? ', ' . $ev['evento_city'] : ''));
              ?>
              <a href="<?= e($ev['url']) ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                <div>
                  <i class="bi bi-calendar-event me-1 text-secondary"></i><?= e($evDate) ?>
                  <?php if ($evLocation): ?><br><small class="text-secondary"><i class="bi bi-geo-alt me-1"></i><?= e($evLocation) ?></small><?php endif; ?>
                </div>
                <i class="bi bi-chevron-right text-secondary"></i>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
                <?php
                continue;
            }
        }

        $meta = ADMINLTE_TIMELINE_TYPE_META[$it['tipo']] ?? ['icon' => 'bi-star', 'color' => 'primary', 'label' => 'Aggiornamento'];

        if ($groupingEnabled && $groupCounts[$groupKey] > 1):
            $renderedGroups[$groupKey] = true;
            $groupItems = [];
            foreach ($items as $j => $other) {
                if ($groupKeys[$j] === $groupKey) {
                    $groupItems[] = $other;
                }
            }
            $dayLabel = formatLocalDateTime($it['data'], ['dashboard_theme' => $it['owner_tz'] ?? null], 'd/m/Y');
            $slides = array_map(fn ($g) => ['url' => $g['url'], 'titolo' => $g['titolo'], 'cover' => $g['cover'] ?? null], $groupItems);
            ?>
        <div class="card mb-3">
          <div class="card-header">
            <div class="user-block">
              <img src="<?= e($avatarUrl) ?>" alt="<?= e($displayName) ?>" class="rounded-circle">
              <span class="username"><?= e($displayName) ?></span>
              <span class="description">
                <span class="badge text-bg-<?= $meta['color'] ?>"><i class="bi <?= e($meta['icon']) ?> me-1"></i><?= e($meta['label']) ?> (<?= count($groupItems) ?>)</span>
                <?= e($dayLabel) ?>
              </span>
            </div>
          </div>
          <div class="card-body">
            <?= renderAdminLteMiniCarousel($slides) ?>
          </div>
        </div>
        <?php else: ?>
        <div class="card mb-3">
          <div class="card-header">
            <div class="user-block">
              <img src="<?= e($avatarUrl) ?>" alt="<?= e($displayName) ?>" class="rounded-circle">
              <span class="username"><a href="<?= e($it['url']) ?>"><?= e($displayName) ?></a></span>
              <span class="description">
                <span class="badge text-bg-<?= $meta['color'] ?>"><i class="bi <?= e($meta['icon']) ?> me-1"></i><?= e($meta['label']) ?></span>
                <?= e(formatLocalDateTime($it['data'], ['dashboard_theme' => $it['owner_tz'] ?? null], 'd/m/Y · H:i')) ?>
              </span>
            </div>
            <div class="card-tools">
              <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Comprimi/espandi">
                <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
              </button>
            </div>
          </div>
          <div class="card-body">
            <?php
              $photos = [];
              if (!empty($it['cover'])) {
                  $photos[] = $it['cover'];
                  foreach ($extraPhotosByPost[(int) ($it['id'] ?? 0)] ?? [] as $extra) {
                      $photos[] = $extra;
                  }
              }
            ?>
            <?php if (count($photos) === 1):
              $soloUrl = str_starts_with($photos[0], 'http') ? $photos[0] : '/' . $photos[0];
            ?>
            <a href="<?= e($it['url']) ?>"><img src="<?= e($soloUrl) ?>" alt="" loading="lazy" class="img-fluid rounded mb-3"></a>
            <?php elseif (count($photos) > 1):
              $shown = array_slice($photos, 0, 4);
              $colClass = count($photos) === 2 ? 'col-6' : 'col-6 col-sm-4';
            ?>
            <div class="row g-2 mb-3">
              <?php foreach ($shown as $k => $ph):
                $phUrl = str_starts_with($ph, 'http') ? $ph : '/' . $ph;
                $isLastTile = $k === 3 && count($photos) > 4;
              ?>
              <div class="<?= $colClass ?>">
                <a href="<?= e($it['url']) ?>" class="position-relative d-block">
                  <img src="<?= e($phUrl) ?>" alt="" loading="lazy" class="img-fluid rounded w-100" style="aspect-ratio:1/1;object-fit:cover;">
                  <?php if ($isLastTile): ?>
                  <span class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center rounded text-white fw-semibold" style="background:rgba(0,0,0,.5);">+<?= count($photos) - 4 ?></span>
                  <?php endif; ?>
                </a>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p class="mb-2"><a href="<?= e($it['url']) ?>" class="link-body-emphasis fw-semibold text-decoration-none"><?= e($it['titolo']) ?></a></p>
            <a href="<?= e($it['url']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right me-1"></i>Apri</a>
          </div>
        </div>
        <?php endif;
    endforeach;
    return ob_get_clean();
}


// Elenco completo (senza scroll infinito) della Timeline in stile "social": attualmente non
// richiamata da nessuna pagina (le due pagine che mostrano la Timeline usano entrambe lo scroll
// infinito reale via renderAdminLteTimelineFeedBlock()), tenuta per un eventuale widget a elenco
// fisso.
function renderAdminLteTimelineWidget(array $items, array $artist): string {
    if (!$items) {
        return '<p class="text-secondary">Nessun aggiornamento ancora.</p>';
    }
    return renderAdminLteTimelineRows($items, $artist);
}


// Contenuto della card Timeline con scroll infinito reale — condiviso fra la Home (che ora mostra
// la Timeline per intero, non più un'anteprima di 5 elementi in un tab) e la pagina Timeline
// standalone: stesso identico blocco, cambia solo il resto della pagina attorno (head/breadcrumb).
// Self-contained: fa da sola la prima query, come sidebar/nav.
function renderAdminLteTimelineFeedBlock(array $artist, string $slug): string {
    $uid = (int) $artist['id'];
    $pageSize = 20;
    $feed = getTimelineFeedForUsers([$uid], $pageSize, 0);
    $finished = count($feed) < $pageSize;
    $pinnedHtml = renderAdminLtePinnedCarousel(getPinnedItemsForUser($uid));
    $monthlyEventsHtml = renderAdminLteMonthlyEventsGrid($uid, $slug, $artist);

    $interstitials = [];
    foreach ([
        fn () => renderAdminLteBlogInterstitial($uid, $slug, $artist),
        fn () => renderAdminLteYoutubeInterstitial($artist, $slug),
        fn () => renderAdminLteCheAmoInterstitial($uid, $slug),
    ] as $fn) {
        try {
            $h = $fn();
            if ($h !== '') { $interstitials[] = $h; }
        } catch (\Throwable $e) {}
    }

    $chunkSize = 3;
    $feedHtml = '';
    if ($feed) {
        $chunks = array_chunk($feed, $chunkSize);
        $intIdx = 0;
        foreach ($chunks as $ci => $chunk) {
            $feedHtml .= renderAdminLteTimelineRows($chunk, $artist);
            if ($intIdx < count($interstitials)) {
                $feedHtml .= $interstitials[$intIdx];
                $intIdx++;
            }
        }
    }

    ob_start();
    ?>
                <?= $pinnedHtml ?>
                <?= $monthlyEventsHtml ?>
                <h3 class="mb-3">Timeline</h3>
                <?php if (!$feed): ?>
                  <p class="text-secondary">Nessun aggiornamento ancora.</p>
                <?php else: ?>
                  <div id="timeline-feed"><?= $feedHtml ?></div>
                <?php endif; ?>
                <p id="timeline-loading" class="text-secondary text-center small" style="display:none;">Caricamento...</p>
                <p id="timeline-end" class="text-secondary text-center small" style="display:<?= ($finished && $feed) ? 'block' : 'none' ?>;">Hai visto tutto.</p>
                <div id="timeline-sentinel" style="height:1px;"></div>
                <script>
                (function () {
                  var slug = <?= json_encode($slug) ?>;
                  var offset = <?= (int) count($feed) ?>;
                  var pageSize = <?= (int) $pageSize ?>;
                  var loading = false;
                  var finished = <?= $finished ? 'true' : 'false' ?>;
                  var feedEl = document.getElementById('timeline-feed');
                  var loadingEl = document.getElementById('timeline-loading');
                  var endEl = document.getElementById('timeline-end');
                  var sentinel = document.getElementById('timeline-sentinel');

                  function loadMore() {
                    if (loading || finished || !feedEl) return;
                    loading = true;
                    loadingEl.style.display = 'block';
                    fetch('/timeline_more.php?slug=' + encodeURIComponent(slug) + '&offset=' + offset)
                      .then(function (r) { return r.json(); })
                      .then(function (data) {
                        loadingEl.style.display = 'none';
                        if (data.html) { feedEl.insertAdjacentHTML('beforeend', data.html); }
                        offset += data.count;
                        if (data.count < pageSize) {
                          finished = true;
                          endEl.style.display = 'block';
                        }
                        loading = false;
                      })
                      .catch(function () {
                        loading = false;
                        loadingEl.style.display = 'none';
                      });
                  }

                  if (!finished && sentinel && 'IntersectionObserver' in window) {
                    var observer = new IntersectionObserver(function (entries) {
                      if (entries[0].isIntersecting) loadMore();
                    });
                    observer.observe(sentinel);
                  }
                })();
                </script>
    <?php
    return ob_get_clean();
}


// Script di scroll infinito generico, condiviso da tutte le pagine a elenco a tema AdminLTE
// (Blog, i 6 moduli Che Amo, Brani, Offerte, Servizi, Eventi) — si appoggia
// all'endpoint unico adminlte_list_more.php?type=$type, che sa come interrogare e disegnare
// ciascun tipo di elenco. Gli elementi con questi ID devono già esistere nella pagina chiamante:
// #adminlte-list-feed (contenitore in cui appendere), #adminlte-list-sentinel,
// #adminlte-list-loading, #adminlte-list-end. Viaggi (raggruppato per mese) e Foto (griglia con
// lightbox che indicizza tutte le immagini insieme) restano elenchi completi, non paginati: la
// paginazione ne romperebbe rispettivamente il raggruppamento e l'indice della lightbox.
function adminLteInfiniteScrollScript(string $type, string $slug, int $initialCount, int $pageSize, bool $finished, string $extraQuery = ''): string {
    ob_start();
    ?>
                <script>
                (function () {
                  var type = <?= json_encode($type) ?>;
                  var slug = <?= json_encode($slug) ?>;
                  var offset = <?= (int) $initialCount ?>;
                  var pageSize = <?= (int) $pageSize ?>;
                  var extraQuery = <?= json_encode($extraQuery) ?>;
                  var loading = false;
                  var finished = <?= $finished ? 'true' : 'false' ?>;
                  var feedEl = document.getElementById('adminlte-list-feed');
                  var loadingEl = document.getElementById('adminlte-list-loading');
                  var endEl = document.getElementById('adminlte-list-end');
                  var sentinel = document.getElementById('adminlte-list-sentinel');

                  function loadMore() {
                    if (loading || finished || !feedEl) return;
                    loading = true;
                    if (loadingEl) loadingEl.style.display = 'block';
                    fetch('/adminlte_list_more.php?type=' + encodeURIComponent(type) + '&slug=' + encodeURIComponent(slug) + '&offset=' + offset + extraQuery)
                      .then(function (r) { return r.json(); })
                      .then(function (data) {
                        if (loadingEl) loadingEl.style.display = 'none';
                        if (data.html) { feedEl.insertAdjacentHTML('beforeend', data.html); }
                        offset += data.count;
                        if (data.count < pageSize) {
                          finished = true;
                          if (endEl) endEl.style.display = 'block';
                        }
                        loading = false;
                      })
                      .catch(function () {
                        loading = false;
                        if (loadingEl) loadingEl.style.display = 'none';
                      });
                  }

                  if (!finished && sentinel && 'IntersectionObserver' in window) {
                    var observer = new IntersectionObserver(function (entries) {
                      if (entries[0].isIntersecting) loadMore();
                    });
                    observer.observe(sentinel);
                  }
                })();
                </script>
    <?php
    return ob_get_clean();
}


// Righe/card di un elenco paginato a tema AdminLTE — funzioni "pure" (solo item -> HTML, senza
// contenitore) riusate sia dal primo caricamento di ciascuna pagina sia da
// adminlte_list_more.php per le pagine successive dello scroll infinito.

function renderAdminLteBlogRows(array $posts, string $slug, array $artist): string {
    $html = '';
    foreach ($posts as $p) {
        ob_start();
        ?>
              <a href="<?= e(blogPostUrl($slug, $p)) ?>" class="card mb-3 text-decoration-none text-body">
                <div class="d-flex adminlte-blog-row">
                  <?php if ($p['cover_path']): ?>
                    <img src="/<?= e($p['cover_path']) ?>" alt="" loading="lazy" class="adminlte-blog-thumb">
                  <?php else: ?>
                    <div class="bg-body-tertiary d-flex align-items-center justify-content-center adminlte-blog-thumb">
                      <i class="bi bi-file-earmark-text fs-1 text-secondary" aria-hidden="true"></i>
                    </div>
                  <?php endif; ?>
                  <div class="card-body">
                    <h3 class="h5 mb-1"><?= e($p['title']) ?></h3>
                    <p class="text-secondary small mb-2"><?= e(formatLocalDateTime($p['published_at'], $artist)) ?></p>
                    <?php if ($p['excerpt']): ?><p class="mb-0 text-secondary"><?= e(textExcerpt($p['excerpt'], 160)) ?></p><?php endif; ?>
                  </div>
                </div>
              </a>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}


function renderAdminLteFanFavoriteRows(array $favorites, string $slug, string $kind, array $artist): string {
    $cfg = ADMINLTE_FAN_FAVORITE_KINDS[$kind];
    $shapeStyle = ['circle' => 'width:64px;height:64px;border-radius:50%;', 'book' => 'width:64px;height:88px;border-radius:6px;', 'square' => 'width:80px;height:80px;border-radius:10px;'][$cfg['image_shape']];
    $html = '';
    foreach ($favorites as $f) {
        $img = $f[$cfg['image_col']] ?? null;
        ob_start();
        ?>
                    <div class="col-6 col-sm-4 col-lg-3">
                      <a href="/<?= e($slug) ?>/<?= e($cfg['list_url_segment']) ?>/<?= (int) $f['id'] ?>" class="card text-decoration-none text-body p-3 h-100">
                        <?php if ($img): ?>
                          <img src="<?= e($img) ?>" alt="" loading="lazy" class="mx-auto mb-2 d-block" style="<?= $shapeStyle ?>object-fit:cover;">
                        <?php endif; ?>
                        <div class="fw-semibold small"><?= e($f[$cfg['name_col']]) ?></div>
                        <?php if ($kind === 'album' && !empty($f['album_artist_name'])): ?><div class="text-secondary" style="font-size:11.5px;"><?= e($f['album_artist_name']) ?></div><?php endif; ?>
                        <small class="text-secondary" style="font-size:11px;"><?= e(publishedAtLabel($f['publish_at'], $f['created_at'], $artist)) ?></small>
                      </a>
                    </div>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}


function renderAdminLteBraniRows(array $tracks, string $slug, array $artist): string {
    $html = '';
    foreach ($tracks as $t) {
        $trackStats = getTrackRatingStats((int) $t['id']);
        ob_start();
        ?>
              <div class="card mb-3">
                <div class="card-body d-flex align-items-center gap-3">
                  <a href="/<?= e($slug) ?>/brani/<?= (int) $t['id'] ?>/scheda" class="d-flex align-items-center gap-3 text-decoration-none text-body flex-grow-1" style="min-width:0;">
                    <?php if ($t['track_image']): ?>
                      <img src="<?= e($t['track_image']) ?>" alt="" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                    <?php else: ?>
                      <div class="bg-body-tertiary rounded" style="width:64px;height:64px;flex-shrink:0;"></div>
                    <?php endif; ?>
                    <div style="min-width:0;">
                      <strong class="d-block text-truncate"><?= e($t['track_name']) ?></strong>
                      <small class="text-secondary"><?= e($t['artist_name']) ?></small><br>
                      <small><?= renderCromeRating($trackStats['avg']) ?><?php if ($trackStats['count'] > 0): ?> <span class="text-secondary">(<?= $trackStats['count'] ?>)</span><?php endif; ?></small><br>
                      <small class="text-secondary"><?= e(publishedAtLabel($t['publish_at'], $t['created_at'], $artist)) ?></small>
                    </div>
                  </a>
                  <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <?php if (!empty($t['lyrics'])): ?><a href="/<?= e($slug) ?>/brani/<?= (int) $t['id'] ?>/testo" title="Testo e ascolto" class="text-decoration-none"><i class="bi bi-file-text fs-5"></i></a><?php endif; ?>
                    <a href="/<?= e($slug) ?>/brani/<?= (int) $t['id'] ?>/votazioni" title="Vota questo brano" class="text-decoration-none text-warning"><i class="bi bi-star-fill fs-5"></i></a>
                  </div>
                </div>
              </div>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}


function renderAdminLteOfferteRows(array $offers, string $slug, array $artist): string {
    $html = '';
    foreach ($offers as $of) {
        ob_start();
        ?>
              <a href="/<?= e($slug) ?>/offerte/<?= (int) $of['id'] ?>" class="card mb-3 text-decoration-none text-body">
                <div class="card-body d-flex align-items-center gap-3">
                  <?php if ($of['cover_path']): ?><img src="/<?= e($of['cover_path']) ?>" alt="" style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;"><?php endif; ?>
                  <div class="flex-grow-1" style="min-width:0;">
                    <strong><?= e($of['title']) ?></strong>
                    <?php if ($of['price_label']): ?><div class="text-primary fw-bold"><?= e($of['price_label']) ?></div><?php endif; ?>
                    <?php if ($of['valid_until']): ?><small class="text-secondary d-block mt-1">Valida fino al <?= e(formatLocalDateTime($of['valid_until'], $artist, 'd/m/Y')) ?></small><?php endif; ?>
                  </div>
                </div>
              </a>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}


function renderAdminLteServiziRows(array $services, string $slug): string {
    $html = '';
    foreach ($services as $sv) {
        ob_start();
        ?>
              <a href="/<?= e($slug) ?>/servizi/<?= (int) $sv['id'] ?>" class="card mb-3 text-decoration-none text-body">
                <div class="card-body d-flex align-items-center gap-3">
                  <?php if ($sv['cover_path']): ?><img src="/<?= e($sv['cover_path']) ?>" alt="" style="width:72px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0;"><?php endif; ?>
                  <div class="flex-grow-1" style="min-width:0;">
                    <strong><?= e($sv['title']) ?></strong>
                    <?php if ($sv['description']): ?><div class="text-secondary small mt-1"><?= e(textExcerpt($sv['description'], 90)) ?></div><?php endif; ?>
                    <?php if ((int) $sv['accepts_inquiries'] === 1): ?><small class="text-primary fw-semibold d-block mt-1"><i class="bi bi-envelope me-1"></i>Richiedi informazioni</small><?php endif; ?>
                  </div>
                </div>
              </a>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}


function renderAdminLteEventiRows(array $events, string $slug, array $artist): string {
    $html = '';
    foreach ($events as $ev) {
        $scheduleLabel = eventScheduleLabel($ev['recurrence'] ?? 'none', (bool) ($ev['is_perpetual'] ?? false));
        ob_start();
        ?>
              <a href="/<?= e($slug) ?>/eventi/<?= (int) $ev['id'] ?>" class="card mb-3 text-decoration-none text-body" data-event-id="<?= (int) $ev['id'] ?>">
                <div class="card-body d-flex gap-3 adminlte-eventi-row">
                  <?php if ($ev['cover_path']): ?><img src="/<?= e($ev['cover_path']) ?>" alt="" class="adminlte-eventi-thumb"><?php endif; ?>
                  <div class="flex-grow-1" style="min-width:0;">
                    <small class="text-secondary d-block"><?= e(formatLocalDateTime($ev['event_date'], $artist)) ?></small>
                    <strong><?= e($ev['title']) ?></strong>
                    <?php if ($ev['venue'] || $ev['city']): ?><small class="text-secondary d-block"><?= e($ev['venue']) ?><?= $ev['venue'] && $ev['city'] ? ', ' : '' ?><?= e($ev['city']) ?></small><?php endif; ?>
                    <?php if ($scheduleLabel): ?><small class="fw-semibold d-block"><i class="bi bi-arrow-repeat me-1"></i><?= e($scheduleLabel) ?></small><?php endif; ?>
                  </div>
                </div>
              </a>
        <?php
        $html .= ob_get_clean();
    }
    return $html;
}


// Avatar per le pagine pubbliche a tema AdminLTE: foto reale se presente, altrimenti iniziali su
// cerchio colorato (data URI, nessun file richiesto) — condiviso fra la Home e la pagina Timeline
// dello stesso tema.
function adminLteAvatarUrl(array $artist): string {
    if (!empty($artist['avatar_path'])) {
        return '/' . e($artist['avatar_path']);
    }
    $words = preg_split('/\s+/', trim($artist['display_name'] ?? ''));
    $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><circle cx="48" cy="48" r="48" fill="#6c5ce7"/><text x="48" y="61" font-family="Arial,Helvetica,sans-serif" font-size="32" font-weight="700" fill="white" text-anchor="middle">' . $initials . '</text></svg>');
}


// Tema grafico pubblico basato su AdminLTE 4 (CSS/JS reali forniti dall'utente) — sostituisce
// l'intera Home pubblica con una propria pagina completa, stesso principio isolato dei
// precedenti temi "a scena" (Giardino Anomalo, Scorrimento Infinito, ora rimossi): non tocca in
// alcun modo lo scheletro HTML condiviso dagli altri ~30 temi. Mostra la Timeline per intero (con
// scroll infinito, come la pagina standalone — vedi renderAdminLteTimelineFeedBlock()): le altre
// sezioni (Che Amo, Spotify, Blog...) non sono più anteprime qui dentro, sono voci del menu di
// navigazione nella colonna laterale (renderAdminLteProfileSidebar()) che portano alla pagina
// vera di ciascuna — click reale, niente più tab Bootstrap in-pagina (vedi cronologia di questa
// funzione per la versione precedente, con le anteprime).
function renderAdminLteProfileTheme(array $artist, string $slug): string {
    $pageUrl = siteUrl('/' . $slug);
    $ogDescription = !empty($artist['bio']) ? textExcerpt($artist['bio'], 160) : ($artist['display_name'] . ' su ' . siteName());
    ob_start();
    ?>
<!doctype html>
<!-- Tema pubblico: palette fissa scelta dall'artista, non deve seguire il dark mode del
     visitatore (vedi stesso ragionamento fatto per il prototipo). -->
<html lang="it" data-lte-color-mode="off" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e($ogDescription) ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e($ogDescription) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if (!empty($artist['avatar_path'])): ?><meta property="og:image" content="<?= e(siteUrl('/' . $artist['avatar_path'])) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($pageUrl) ?>">
<?= adminLteAssetLinks() ?>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="fixed-header bg-body-tertiary">
<?= embedTrackingBodyStart($artist) ?>
<div class="app-wrapper">
      <?= adminLteTopNav($artist, $slug, 'timeline') ?>
  <main class="app-main">
    <?= adminLteBreadcrumbHeader($slug, $artist['display_name'], $artist['display_name']) ?>
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3">
          <?= renderAdminLteProfileSidebar($artist, $slug, true) ?>
          <?= renderAdminLteProfileExtras($artist, $slug) ?>
          <div class="col-lg-6 order-1 order-lg-2 adminlte-main-col">
            <?php if (!empty($_GET['follow_msg'])): ?>
            <div class="alert <?= !empty($_GET['follow_err']) ? 'alert-danger' : 'alert-success' ?> mb-3"><?= e($_GET['follow_msg']) ?></div>
            <?php endif; ?>
            <?= renderAdminLteTimelineFeedBlock($artist, $slug) ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <?= adminLteFooterBlock($artist) ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= assetUrl('/assets/themes/adminlte-profile/js/adminlte.min.js') ?>"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}


// Elenco pubblico "Eventi" a tema AdminLTE — eventi.php. $provinciaFilter (opzionale, da
// eventi.php?provincia=...) è lo stesso filtro cliccabile dal widget "Eventi per provincia" della
// colonna destra (renderAdminLteEventiByProvinciaCard()) — qui mostra un'intestazione con quale
// provincia è filtrata e un link per toglierla, e lo propaga allo scroll infinito. $expiredEvents
// (solo quando $provinciaFilter è impostato) sono gli eventi già passati della stessa provincia:
// spariscono dall'elenco principale (e dal conteggio del widget laterale) appena scaduti, ma qui
// restano visibili sotto un separatore così chi consulta la pagina di una provincia può comunque
// trovarli.
// Mappa degli eventi (pagina Eventi, tema AdminLTE): OpenStreetMap con Leaflet, un punto per ogni
// evento con posizione. Passando il mouse su un evento dell'elenco il punto si evidenzia (e viceversa:
// cliccando un punto si apre la scheda con il link). Funziona anche per le righe caricate dallo scroll
// infinito (delegazione degli eventi). Senza punti la scheda non compare.
function renderAdminLteEventiMapCard(array $points, string $slug, int $listedCount): string {
    if (!$points) {
        return '';
    }
    $json = json_encode($points, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ob_start();
    ?>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
            <style>
              #eventi-map-card { position: sticky; top: 70px; }
              #eventi-map { height: calc(100vh - 150px); min-height: 380px; border-radius: 0 0 .375rem .375rem; z-index: 0; }
              @media (max-width: 991.98px) { #eventi-map-card { position: static; } #eventi-map { height: 380px; } }
              [data-event-id].eventi-hl { box-shadow: 0 0 0 2px var(--bs-primary, #0d6efd); }
            </style>
            <div class="card" id="eventi-map-card">
              <div class="card-header"><h3 class="card-title"><i class="bi bi-geo-alt me-1"></i>Mappa degli eventi</h3></div>
              <div id="eventi-map" aria-label="Mappa degli eventi"></div>
            </div>
            <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
            <script>
            (function () {
              if (!window.L) return;
              var points = <?= $json ?>;
              var slug = <?= json_encode($slug) ?>;
              var map = L.map('eventi-map', { scrollWheelZoom: false });
              L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
              }).addTo(map);
              map.on('click', function () { map.scrollWheelZoom.enable(); });
              var base = { radius: 9, color: '#fff', weight: 2, fillColor: '#0d6efd', fillOpacity: 0.9 };
              var hot = { radius: 13, color: '#fff', weight: 3, fillColor: '#dc3545', fillOpacity: 1 };
              var markers = {}, bounds = [];
              points.forEach(function (p) {
                var m = L.circleMarker([p.lat, p.lng], base).addTo(map);
                var box = document.createElement('div');
                var t = document.createElement('strong'); t.textContent = p.title; box.appendChild(t);
                if (p.date) { var d = document.createElement('div'); d.textContent = p.date; box.appendChild(d); }
                if (p.where) { var w = document.createElement('div'); w.textContent = p.where; box.appendChild(w); }
                var a = document.createElement('a'); a.href = '/' + slug + '/eventi/' + p.id; a.textContent = 'Vai all\'evento'; box.appendChild(a);
                m.bindPopup(box);
                m.on('click', function () {
                  var row = document.querySelector('[data-event-id="' + p.id + '"]');
                  if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
                markers[p.id] = m; bounds.push([p.lat, p.lng]);
              });
              if (bounds.length === 1) map.setView(bounds[0], 14); else map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });

              var current = null;
              function highlight(id) {
                if (current && markers[current]) markers[current].setStyle(base);
                current = id;
                var m = id ? markers[id] : null;
                if (m) { m.setStyle(hot); m.bringToFront(); if (!map.getBounds().contains(m.getLatLng())) map.panTo(m.getLatLng()); }
              }
              var feed = document.getElementById('adminlte-list-feed') || document;
              feed.addEventListener('mouseover', function (e) {
                var row = e.target.closest ? e.target.closest('[data-event-id]') : null;
                if (!row) return;
                row.classList.add('eventi-hl');
                highlight(row.getAttribute('data-event-id') | 0);
              });
              feed.addEventListener('mouseout', function (e) {
                var row = e.target.closest ? e.target.closest('[data-event-id]') : null;
                if (!row) return;
                row.classList.remove('eventi-hl');
                highlight(null);
              });
            })();
            </script>
    <?php
    return ob_get_clean();
}


// Filtro "Provincia" e "Città" in cima alla colonna centrale della pagina Eventi (tema AdminLTE):
// due menu con i valori degli eventi visibili del profilo. Scegliendo una provincia l'elenco delle
// città si restringe a quelle della provincia; il modulo si invia da solo al cambio (senza JavaScript
// resta il pulsante "Filtra"). Nessuna card se non ci sono né province né città da scegliere.
function renderAdminLteEventiFilterCard(string $slug, string $provincia, string $citta, array $options): string {
    $province = $options['province'] ?? [];
    $cities = $options['citta'] ?? [];
    if (!$province && !$cities) {
        return '';
    }
    ob_start();
    ?>
            <form method="get" action="<?= e('/' . $slug . '/eventi') ?>" class="card mb-3" id="eventi-filter">
              <div class="card-body">
                <div class="row g-2 align-items-end">
                  <?php if ($province): ?>
                  <div class="col-sm-6">
                    <label for="eventi-f-prov" class="form-label small fw-semibold mb-1">Provincia</label>
                    <select name="provincia" id="eventi-f-prov" class="form-select">
                      <option value="">Tutte le province</option>
                      <?php foreach ($province as [$name, $n]): ?>
                      <option value="<?= e($name) ?>"<?= $name === $provincia ? ' selected' : '' ?>><?= e($name) ?> (<?= (int) $n ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php endif; ?>
                  <?php if ($cities): ?>
                  <div class="col-sm-6">
                    <label for="eventi-f-citta" class="form-label small fw-semibold mb-1">Città</label>
                    <select name="citta" id="eventi-f-citta" class="form-select">
                      <option value="">Tutte le città</option>
                      <?php foreach ($cities as [$name, $prov, $n]): ?>
                      <option value="<?= e($name) ?>" data-prov="<?= e($prov) ?>"<?= $name === $citta ? ' selected' : '' ?>><?= e($name) ?> (<?= (int) $n ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="d-flex gap-2 mt-3">
                  <button type="submit" class="btn btn-primary btn-sm">Filtra</button>
                  <?php if ($provincia !== '' || $citta !== ''): ?>
                  <a href="<?= e('/' . $slug . '/eventi') ?>" class="btn btn-outline-secondary btn-sm">Azzera i filtri</a>
                  <?php endif; ?>
                </div>
              </div>
            </form>
            <script>
            (function () {
              var f = document.getElementById('eventi-filter');
              var prov = document.getElementById('eventi-f-prov');
              var city = document.getElementById('eventi-f-citta');
              function narrow() {
                if (!city) return;
                var p = prov ? prov.value : '';
                Array.prototype.forEach.call(city.options, function (o) {
                  if (!o.value) return;
                  var show = !p || o.getAttribute('data-prov') === p;
                  o.hidden = !show; o.disabled = !show;
                });
                if (city.selectedOptions[0] && city.selectedOptions[0].disabled) city.value = '';
              }
              narrow();
              if (prov) prov.addEventListener('change', function () { narrow(); if (city) city.value = ''; f.submit(); });
              if (city) city.addEventListener('change', function () { f.submit(); });
            })();
            </script>
    <?php
    return ob_get_clean();
}


function getPageThemeClass(?string $theme): string {
    return PAGE_THEMES[$theme]['body_class'] ?? PAGE_THEMES['colorful']['body_class'];
}


// Mini-carosello generico a schede: una slide a piena larghezza per volta (foto+titolo, cliccabile
// per intero), swipe/scroll orizzontale nativo — vedi il CSS in adminLteAssetLinks() e il JS
// delegato in adminLteFooterBlock(). Usato sia per raggruppare elementi omogenei dello stesso
// giorno (renderAdminLteTimelineRows(), $perView=1 implicito, badge a pillola + titolo in testo)
// sia per il carosello "Primo Piano" (renderAdminLtePinnedCarousel(), $perView=3, $ribbonStyle=
// true: badge come ribbon sovrapposto all'immagine, niente titolo in testo — le copertine usate
// lì sono già grafiche autoesplicative con il titolo incorporato nell'immagine stessa, ripeterlo
// sotto sarebbe ridondante). Ogni slide: ['url','titolo','cover'] + opzionale
// ['badge_label','badge_color','badge_icon']. Con $perView>1: se gli slide sono meno o uguali a
// $perView si dividono lo spazio in proporzione (niente scroll, niente freccette, vedi
// .admlte-carousel-fit nel CSS) — altrimenti restano a larghezza fissa e se ne vedono sempre al
// massimo $perView per volta, scorrendo in orizzontale per il resto.
function renderAdminLteMiniCarousel(array $slides, int $perView = 1, bool $ribbonStyle = false): string {
    if (!$slides) {
        return '';
    }
    $wrapClass = 'admlte-carousel-wrap position-relative';
    if ($perView > 1) {
        $wrapClass .= ' admlte-carousel-wrap-' . $perView;
        if (count($slides) <= $perView) {
            $wrapClass .= ' admlte-carousel-fit';
        }
    }
    ob_start();
    ?>
    <div class="<?= e($wrapClass) ?>">
      <div class="admlte-carousel-track">
        <?php foreach ($slides as $s): ?>
        <div class="admlte-carousel-slide">
          <a href="<?= e($s['url']) ?>" class="text-decoration-none text-body d-block">
            <?php if ($ribbonStyle): ?>
              <!-- object-fit:contain, non cover: queste copertine sono spesso grafiche verticali
                   con testo/CTA sia in alto che in fondo (locandine social pronte all'uso) — un
                   ritaglio a riempimento le taglierebbe. Qui si vede sempre l'immagine per
                   intero, con eventuale spazio vuoto ai lati riempito dallo sfondo bianco del
                   riquadro. Sfondo bianco con bordo (non bg-body-tertiary): qui sopra non c'è più
                   una card bianca a fare da sfondo — vedi renderAdminLtePinnedCarousel(), senza
                   contenitore — quindi un grigio chiaro su grigio chiaro sparirebbe. -->
              <div class="position-relative bg-white border rounded admlte-pinned-cover">
                <?php if (!empty($s['cover'])):
                  $coverUrl = str_starts_with($s['cover'], 'http') ? $s['cover'] : '/' . $s['cover'];
                ?>
                <div class="admlte-pinned-cover-bg" style="background-image:url('<?= e($coverUrl) ?>');"></div>
                <img src="<?= e($coverUrl) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:contain;position:relative;">
                <?php else: ?>
                <!-- Niente copertina (es. articolo del blog senza immagine): il titolo, tolto
                     altrove in questo stile, resta l'unico modo per capire di cosa si tratta. -->
                <div class="d-flex align-items-center justify-content-center text-center p-3 h-100">
                  <span class="fw-semibold"><?= e($s['titolo']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($s['badge_label'])): ?>
                <div class="ribbon-wrapper">
                  <div class="ribbon text-bg-<?= e($s['badge_color'] ?? 'secondary') ?>"><?php if (!empty($s['badge_icon'])): ?><i class="bi <?= e($s['badge_icon']) ?> me-1"></i><?php endif; ?><?= e($s['badge_label']) ?></div>
                </div>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <?php if (!empty($s['badge_label'])): ?>
              <span class="badge text-bg-<?= e($s['badge_color'] ?? 'secondary') ?> mb-2"><?php if (!empty($s['badge_icon'])): ?><i class="bi <?= e($s['badge_icon']) ?> me-1"></i><?php endif; ?><?= e($s['badge_label']) ?></span>
              <?php endif; ?>
              <p class="fw-semibold mb-2"><?= e($s['titolo']) ?></p>
              <?php if (!empty($s['cover'])):
                $coverUrl = str_starts_with($s['cover'], 'http') ? $s['cover'] : '/' . $s['cover'];
              ?>
              <img src="<?= e($coverUrl) ?>" alt="" loading="lazy" class="img-fluid rounded" style="max-height:320px;width:100%;object-fit:cover;">
              <?php endif; ?>
            <?php endif; ?>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($slides) > $perView): ?>
      <button type="button" class="admlte-carousel-nav admlte-carousel-prev" aria-label="Precedente"><i class="bi bi-chevron-left"></i></button>
      <button type="button" class="admlte-carousel-nav admlte-carousel-next" aria-label="Successivo"><i class="bi bi-chevron-right"></i></button>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// Card "Primo Piano" prima della Timeline, mostrata appena c'è almeno un pin attivo e visibile —
// vedi renderAdminLteTimelineFeedBlock(), che decide quando chiamare questa funzione. Fino a 3
// elementi si vedono tutti insieme, senza scorrimento; da 4 in su diventa un vero carosello che ne
// mostra comunque sempre 3 alla volta (renderAdminLteMiniCarousel() con $perView=3). Nessun
// contenitore/card attorno (a differenza delle altre sezioni della Timeline): solo un titolo
// semplice, come "Timeline" subito sotto — la natura di ogni elemento è già indicata dal ribbon
// sovrapposto alla copertina ($ribbonStyle=true), niente più titolo in testo (le copertine sono
// già grafiche autoesplicative, vedi renderAdminLteMiniCarousel()). Gli elementi fissati restano
// comunque anche nella loro posizione cronologica normale nel feed sotto, non ne vengono rimossi.
function renderAdminLtePinnedCarousel(array $pinnedItems): string {
    if (!$pinnedItems) {
        return '';
    }
    $slides = [];
    foreach ($pinnedItems as $it) {
        $meta = ADMINLTE_TIMELINE_TYPE_META[$it['tipo']] ?? ['icon' => 'bi-star', 'color' => 'primary', 'label' => 'Aggiornamento'];
        $slides[] = [
            'url' => $it['url'], 'titolo' => $it['titolo'], 'cover' => $it['cover'],
            'badge_label' => $meta['label'], 'badge_color' => $meta['color'], 'badge_icon' => $meta['icon'],
        ];
    }
    ob_start();
    ?>
    <div class="mb-3">
      <h3 class="mb-3"><i class="bi bi-pin-angle-fill me-1"></i>In Primo Piano</h3>
      <?= renderAdminLteMiniCarousel($slides, 3, true) ?>
    </div>
    <?php
    return ob_get_clean();
}
