<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
checkInstallation();

$user = currentUser();
$site = siteName();

// Elenco delle funzioni reali della piattaforma, mostrato sulla Home — tenerlo qui invece che
// sparso nell'HTML rende facile aggiungerne una nuova in futuro senza toccare il markup sotto.
$homeFeatures = [
    ['icon' => '🔗', 'title' => 'Link in Bio', 'desc' => 'Tutti i tuoi link importanti in una sola pagina, sempre a portata di condivisione.'],
    ['icon' => '🕒', 'title' => 'Timeline', 'desc' => 'Pubblica aggiornamenti e resta in contatto con chi ti segue.'],
    ['icon' => '📰', 'title' => 'Blog', 'desc' => 'Articoli con permalink dedicato, ottimizzati per la ricerca.'],
    ['icon' => '🎧', 'title' => 'Brani', 'desc' => 'Vetrina dei brani che ami, collegata a Spotify.'],
    ['icon' => '📅', 'title' => 'Eventi', 'desc' => 'Calendario pubblico, condivisibile e sempre aggiornato.'],
    ['icon' => '🍽️', 'title' => 'Menù e Prenotazioni', 'desc' => 'Menù digitale e prenotazioni, pensati per locali e ristoranti.'],
    ['icon' => '❤️', 'title' => 'Follower', 'desc' => 'Chi ti segue riceve una notifica ad ogni tua novità.'],
    ['icon' => '🎨', 'title' => 'Oltre 20 temi grafici', 'desc' => 'Scegli lo stile che rispecchia la tua identità.'],
];
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($site) ?> — Link in Bio, timeline, blog, eventi e prenotazioni in un'unica pagina</title>
<meta name="description" content="Crea in pochi minuti la tua pagina pubblica su <?= e($site) ?>: link, brani, eventi, blog, menù e prenotazioni in un unico posto, sempre aggiornato.">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #FAF5EE; color: #17172b; }
  a { text-decoration: none; color: inherit; }

  .lp-nav {
    display: flex; align-items: center; justify-content: space-between;
    max-width: 1180px; margin: 0 auto; padding: 18px 24px;
  }
  .lp-nav .lp-logo { font-weight: 800; font-size: 20px; display: flex; align-items: center; gap: 8px; }
  .lp-nav .lp-logo .dot { width: 10px; height: 10px; border-radius: 50%; background: rgb(108,92,231); display: inline-block; flex-shrink: 0; }
  .lp-nav-links { display: flex; gap: 28px; font-weight: 600; font-size: 14.5px; color: #444; }
  .lp-nav-links a:hover { color: rgb(108,92,231); }
  .lp-nav-cta { background: #17172b; color: #fff; padding: 10px 22px; border-radius: 999px; font-weight: 700; font-size: 14px; white-space: nowrap; }
  @media (max-width: 800px) { .lp-nav-links { display: none; } }

  /* Mobile-first: le regole di base sono per schermo stretto (una colonna, niente scroll
     orizzontale); i breakpoint con min-width AGGIUNGONO la disposizione più ricca man mano che
     c'è spazio — evita che qualcosa "dimentichi" di restare responsive sotto una certa larghezza. */
  .lp-hero {
    max-width: 1180px; margin: 20px auto 0; padding: 20px 24px 50px;
    display: flex; flex-direction: column; gap: 40px; align-items: center; text-align: center;
  }
  .lp-hero h1 { font-size: 34px; line-height: 1.12; font-weight: 800; margin: 0 0 18px; letter-spacing: -1px; }
  .lp-hero h1 .hl { color: rgb(108,92,231); }
  .lp-hero p.lp-sub { font-size: 16px; color: #55555f; line-height: 1.6; margin: 0 auto 28px; max-width: 480px; }
  .lp-cta-row { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; }
  .lp-btn-dark { background: #17172b; color: #fff; padding: 15px 30px; border-radius: 999px; font-weight: 700; font-size: 15px; }
  .lp-btn-outline { background: transparent; color: #17172b; padding: 14px 28px; border-radius: 999px; font-weight: 700; font-size: 15px; border: 1.5px solid #ccc; }

  .lp-illustration { position: relative; display: flex; justify-content: center; width: 100%; min-height: 360px; }
  .lp-phone {
    width: 230px; background: linear-gradient(160deg, #FFD6A5 0%, #A0C4FF 55%, #BDB2FF 100%);
    border-radius: 32px; padding: 22px 16px; box-shadow: 0 30px 60px rgba(23,23,43,0.25);
    text-align: center; position: relative; z-index: 2;
  }
  .lp-phone .lp-avatar { width: 56px; height: 56px; border-radius: 50%; background: #fff; margin: 0 auto 10px; border: 3px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
  .lp-phone .lp-name { font-weight: 800; font-size: 13px; margin-bottom: 2px; }
  .lp-phone .lp-handle { font-size: 10px; color: rgba(23,23,43,0.6); margin-bottom: 12px; }
  .lp-phone .lp-pill-row { display: flex; gap: 4px; justify-content: center; flex-wrap: wrap; margin-bottom: 14px; }
  .lp-phone .lp-pill-row span { background: rgba(255,255,255,0.6); border-radius: 7px; font-size: 8px; font-weight: 700; padding: 3px 6px; }
  .lp-phone .lp-link-btn { display: block; border-radius: 10px; padding: 10px; font-size: 11px; font-weight: 700; margin-bottom: 8px; color: #17172b; }
  .lp-float-card {
    position: absolute; background: #fff; border-radius: 14px; padding: 10px 14px;
    box-shadow: 0 10px 26px rgba(23,23,43,0.15); font-size: 12.5px; font-weight: 700; z-index: 3;
  }
  .lp-float-1 { top: 10px; right: 6%; transform: rotate(4deg); }
  .lp-float-2 { top: 150px; right: 2%; transform: rotate(-3deg); }
  .lp-float-3 { bottom: 30px; right: 8%; transform: rotate(3deg); }
  @media (max-width: 480px) { .lp-float-card { display: none; } }

  @media (min-width: 900px) {
    .lp-hero { flex-direction: row; text-align: left; padding: 40px 24px 60px; }
    .lp-hero > div:first-child { flex: 1.1; }
    .lp-hero h1 { font-size: 56px; }
    .lp-hero p.lp-sub { margin-left: 0; margin-right: 0; }
    .lp-cta-row { justify-content: flex-start; }
    .lp-illustration { flex: 0.9; }
  }

  .lp-features {
    max-width: 1180px; margin: 20px auto 70px; padding: 0 24px;
    display: grid; grid-template-columns: 1fr; gap: 16px;
  }
  .lp-feature { background: #fff; border-radius: 18px; padding: 24px; box-shadow: 0 4px 20px rgba(23,23,43,0.06); }
  .lp-feature .lp-feature-icon { font-size: 26px; margin-bottom: 12px; }
  .lp-feature h3 { font-size: 16px; margin: 0 0 8px; }
  .lp-feature p { font-size: 13.5px; color: #666; line-height: 1.5; margin: 0; }
  @media (min-width: 550px) { .lp-features { grid-template-columns: repeat(2, 1fr); } }
  @media (min-width: 900px) { .lp-features { grid-template-columns: repeat(4, 1fr); } }

  .lp-final-cta { text-align: center; padding: 50px 24px 80px; }
  .lp-final-cta h2 { font-size: 26px; margin: 0 0 14px; }
  .lp-final-cta p { color: #666; margin-bottom: 28px; }
  @media (min-width: 900px) { .lp-final-cta h2 { font-size: 32px; } }

  .lp-footer { text-align: center; padding: 30px 24px; color: #999; font-size: 13px; border-top: 1px solid #eee; }
  .lp-footer a { color: #999; text-decoration: underline; }
</style>
</head>
<body>
<?= embedTrackingBodyStart() ?>

<nav class="lp-nav">
  <div class="lp-logo"><span class="dot"></span> <?= e($site) ?></div>
  <div class="lp-nav-links">
    <a href="#come-funziona">Come funziona</a>
    <a href="#funzionalita">Funzionalità</a>
  </div>
  <?php if ($user): ?>
    <a href="/dashboard.php" class="lp-nav-cta">Dashboard</a>
  <?php else: ?>
    <a href="/login.php" class="lp-nav-cta">Accedi</a>
  <?php endif; ?>
</nav>

<section class="lp-hero" id="come-funziona">
  <div>
    <h1>Una pagina.<br>Tutto quello che <span class="hl">fai</span>.</h1>
    <p class="lp-sub">
      Crea in pochi minuti la tua pagina pubblica su <?= e($site) ?>: link importanti, aggiornamenti
      in timeline, blog, brani, eventi, menù e prenotazioni. Tutto da un unico posto, sempre
      aggiornato.
    </p>
    <?php if ($user): ?>
      <div class="lp-cta-row">
        <a href="/dashboard.php" class="lp-btn-dark">Vai alla Dashboard</a>
      </div>
    <?php else: ?>
      <div class="lp-cta-row">
        <a href="/register.php" class="lp-btn-dark">Iscriviti Gratis</a>
        <a href="/login.php" class="lp-btn-outline">Accedi</a>
      </div>
    <?php endif; ?>
  </div>
  <div class="lp-illustration">
    <div class="lp-float-card lp-float-1">🎵 Brani</div>
    <div class="lp-float-card lp-float-2">📅 Eventi</div>
    <div class="lp-float-card lp-float-3">✨ Segui</div>
    <div class="lp-phone">
      <div class="lp-avatar"></div>
      <div class="lp-name">Il Tuo Profilo</div>
      <div class="lp-handle">@tuoprofilo</div>
      <div class="lp-pill-row"><span>Home</span><span>Timeline</span><span>Blog</span></div>
      <div class="lp-link-btn" style="background:#FFD6A5;">Il mio sito web</div>
      <div class="lp-link-btn" style="background:#CAFFBF;">Ascolta su Spotify</div>
      <div class="lp-link-btn" style="background:#9BF6FF;">Prenota adesso</div>
    </div>
  </div>
</section>

<section class="lp-features" id="funzionalita">
  <?php foreach ($homeFeatures as $f): ?>
    <div class="lp-feature">
      <div class="lp-feature-icon"><?= $f['icon'] ?></div>
      <h3><?= e($f['title']) ?></h3>
      <p><?= e($f['desc']) ?></p>
    </div>
  <?php endforeach; ?>
</section>

<?php if (!$user): ?>
<section class="lp-final-cta">
  <h2>Pronto a iniziare?</h2>
  <p>Crea la tua pagina in pochi minuti, gratis.</p>
  <a href="/register.php" class="lp-btn-dark">Iscriviti Gratis</a>
</section>
<?php endif; ?>

<footer class="lp-footer"><?= e($site) ?> &middot; la tua pagina, tutto quello che fai — <a href="/credits.php">Credits</a></footer>

<?= embedTrackingBodyEnd() ?>
</body>
</html>
