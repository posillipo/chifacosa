<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Crediti — CHI FA COSA</title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="container" style="max-width:680px;">
  <h1>Crediti</h1>
  <p style="color:var(--text-muted)">
    CHI FA COSA utilizza alcune librerie e progetti open source. Qui trovi le attribuzioni dovute.
  </p>

  <div class="card">
    <strong>Sfondo animato "Wave" (tema pagina pubblica)</strong>
    <p style="color:var(--text-muted)">
      Adattato e semplificato a partire dal progetto open source
      <a href="https://github.com/franky-adl/3d-wave-grid" target="_blank" rel="noopener">"3D Wave Grid"</a>
      di franky-adl, rilasciato con licenza MIT.
    </p>
  </div>

  <div class="card">
    <strong>Three.js</strong>
    <p style="color:var(--text-muted)">
      Libreria per la grafica 3D nel browser — <a href="https://github.com/mrdoob/three.js" target="_blank" rel="noopener">mrdoob/three.js</a>, licenza MIT.
    </p>
  </div>

  <div class="card">
    <strong>Font Awesome</strong>
    <p style="color:var(--text-muted)">Icone usate in tutto il sito — <a href="https://fontawesome.com" target="_blank" rel="noopener">fontawesome.com</a>.</p>
  </div>

  <p><a href="/">← Torna alla home</a></p>
</div>
</body>
</html>
