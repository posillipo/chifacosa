<?php
// Incluso da tutte le pagine admin_*. Richiede $admin già caricato e $activeAdminTab impostato.
$navItems = [
    'dashboard' => ['url' => '/admin_dashboard.php', 'icon' => 'fas fa-gauge-high', 'label' => 'Dashboard'],
    'users'     => ['url' => '/admin_users.php',     'icon' => 'fas fa-users',       'label' => 'Utenti iscritti'],
    'contacts'  => ['url' => '/admin_contacts.php',  'icon' => 'fas fa-envelope',    'label' => 'Contatti ricevuti'],
    'privacy'   => ['url' => '/admin_privacy.php',   'icon' => 'fas fa-shield-halved','label' => 'Privacy / Cookie'],
    'tracking'  => ['url' => '/admin_tracking.php',  'icon' => 'fas fa-chart-line',  'label' => 'Tracking (GTM/Pixel)'],
    'smtp'      => ['url' => '/admin_smtp.php',      'icon' => 'fas fa-paper-plane', 'label' => 'Email / SMTP'],
    'spotify'   => ['url' => '/admin_spotify.php',   'icon' => 'fa-brands fa-spotify', 'label' => 'Spotify'],
    'youtube'   => ['url' => '/admin_youtube.php',   'icon' => 'fa-brands fa-youtube', 'label' => 'YouTube'],
    'gemini'    => ['url' => '/admin_gemini.php',    'icon' => 'fas fa-wand-magic-sparkles', 'label' => 'Assistente AI'],
    'tmdb'      => ['url' => '/admin_tmdb.php',      'icon' => 'fas fa-clapperboard', 'label' => 'TMDb (Attori)'],
    'googlebooks' => ['url' => '/admin_googlebooks.php', 'icon' => 'fas fa-book', 'label' => 'Google Books (Libri)'],
    'import_legacy' => ['url' => '/admin_import_legacy.php', 'icon' => 'fas fa-file-import', 'label' => 'Import legacy'],
    'reviews' => ['url' => '/admin_reviews.php', 'icon' => 'fas fa-star', 'label' => 'Recensioni'],
    'access_requests' => ['url' => '/admin_access_requests.php', 'icon' => 'fas fa-user-check', 'label' => 'Richieste di accesso'],
];
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?> — CHI FA COSA</title>

<!-- AdminLTE 3 (Bootstrap 4) via CDN — open source, MIT license -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
<style>
  /* Compatibilità: le pagine admin_*.php usano già queste classi (card, btn, alert, ecc.)
     dallo stile precedente; qui le facciamo apparire coerenti con AdminLTE senza dover
     riscrivere il markup di ogni singola pagina. */
  .card { background:#fff; border:1px solid rgba(0,0,0,.125); border-radius:.25rem;
          padding:1rem; margin-bottom:1rem; box-shadow:0 0 1px rgba(0,0,0,.125),0 1px 3px rgba(0,0,0,.2); }
  .btn { display:inline-block; font-weight:400; text-align:center; vertical-align:middle;
         border:1px solid transparent; padding:.375rem .75rem; font-size:1rem; border-radius:.25rem;
         cursor:pointer; background:#007bff; color:#fff; text-decoration:none; }
  .btn:hover { opacity:.9; color:#fff; }
  .btn.small { padding:.25rem .5rem; font-size:.8rem; }
  .btn.secondary { background:#6c757d; }
  .btn.danger { background:#dc3545; }
  .alert { padding:.75rem 1.25rem; margin-bottom:1rem; border:1px solid transparent; border-radius:.25rem; }
  .alert.error { color:#842029; background:#f8d7da; border-color:#f5c2c7; }
  .alert.success { color:#0f5132; background:#d1e7dd; border-color:#badbcc; }
  .section-title { text-transform:uppercase; font-size:.75rem; letter-spacing:.05em;
                    color:#6c757d; margin:1.5rem 0 .75rem; font-weight:600; }
  .link-item, .event-item, .blog-item {
    background:#fff; border:1px solid rgba(0,0,0,.125); border-radius:.25rem;
    padding:.75rem 1rem; margin-bottom:.5rem;
  }
  .link-item { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem; }
  .event-item .date, .blog-item .date { color:#0d6efd; font-size:.8rem; font-weight:600; }
  form label { display:block; margin-top:.5rem; margin-bottom:.25rem; font-size:.875rem; color:#495057; }
  form input[type=text], form input[type=email], form input[type=password], form input[type=url],
  form input[type=date], form input[type=datetime-local], form select, form textarea {
    display:block; width:100%; padding:.375rem .75rem; font-size:1rem;
    border:1px solid #ced4da; border-radius:.25rem; margin-bottom:.75rem;
  }
  form input[type=color] { width:60px; height:38px; padding:2px; margin-bottom:.75rem; }
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

  <!-- Navbar in alto -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="/" class="nav-link">Sito pubblico</a>
      </li>
    </ul>
    <ul class="navbar-nav ml-auto">
      <li class="nav-item">
        <a class="nav-link" href="/dashboard_profile.php">La mia pagina</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="/logout.php">Esci</a>
      </li>
    </ul>
  </nav>

  <!-- Sidebar -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <a href="/admin_dashboard.php" class="brand-link">
      <span class="brand-text font-weight-light" style="margin-left:10px;">CHI FA COSA<b>admin</b></span>
    </a>
    <div class="sidebar">
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
          <?php foreach ($navItems as $key => $item): ?>
            <li class="nav-item">
              <a href="<?= e($item['url']) ?>" class="nav-link <?= $activeAdminTab === $key ? 'active' : '' ?>">
                <i class="nav-icon <?= e($item['icon']) ?>"></i>
                <p><?= e($item['label']) ?></p>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>
    </div>
  </aside>

  <!-- Contenuto della pagina -->
  <div class="content-wrapper">
    <div class="content-header">
      <div class="container-fluid">
        <h1 class="m-0"><?= e($pageTitle ?? 'Admin') ?></h1>
      </div>
    </div>
    <section class="content">
      <div class="container-fluid">
