<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
$admin = requireAdmin();
$activeAdminTab = 'spotify';
$pageTitle = 'Spotify';
$success = null;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        setSiteSetting('spotify_client_id', trim($_POST['spotify_client_id'] ?? ''));
        $newSecret = trim($_POST['spotify_client_secret'] ?? '');
        if ($newSecret !== '') {
            setSiteSetting('spotify_client_secret', $newSecret);
        }
        // Invalida il token in cache: verrà richiesto uno nuovo con le credenziali aggiornate
        setSiteSetting('spotify_app_token', '');
        setSiteSetting('spotify_app_token_expires', '');
        $success = 'Credenziali Spotify salvate.';
    } elseif ($action === 'test') {
        $token = getSpotifyAppToken();
        $testResult = $token
            ? ['ok' => true, 'msg' => 'Connessione a Spotify riuscita: le credenziali funzionano.']
            : ['ok' => false, 'msg' => 'Connessione fallita. Controlla Client ID e Client Secret, o i log del container chifacosa_app (cerca [Spotify]).'];
    }
}

$clientId = getSiteSetting('spotify_client_id') ?: '';
$hasSecret = (getSiteSetting('spotify_client_secret') ?: '') !== '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="alert <?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Questa integrazione permette ad ogni band manager di collegare (dalla propria dashboard)
      il proprio profilo Artista su Spotify, e mostra automaticamente album e brani più
      ascoltati su una pagina pubblica dedicata (<code><?= e(siteName()) ?>/slug/spotify</code>). Non
      richiede il login Spotify degli utenti: usa solo l'accesso al catalogo pubblico.
    </p>
    <p style="color:var(--text-muted)">
      La stessa integrazione permette anche di collegare un eventuale <strong>podcast</strong>
      dell'artista (Dashboard → Podcast) — nessuna credenziale aggiuntiva da configurare qui,
      usa questa stessa chiave.
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere le credenziali: vai su
      <a href="https://developer.spotify.com/dashboard" target="_blank">developer.spotify.com/dashboard</a>,
      crea una nuova app (gratuito), e copia <strong>Client ID</strong> e
      <strong>Client Secret</strong> dalle impostazioni dell'app.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <label>Client ID</label>
    <input type="text" name="spotify_client_id" value="<?= e($clientId) ?>" placeholder="es. 8f3a2b1c...">

    <label>Client Secret</label>
    <input type="password" name="spotify_client_secret" placeholder="<?= $hasSecret ? '••••••••  (lascia vuoto per non modificarlo)' : 'inserisci il client secret' ?>">

    <button type="submit" class="btn">Salva credenziali</button>
  </form>

  <div class="card">
    <strong>Test connessione</strong>
    <p style="color:var(--text-muted)">Verifica che le credenziali funzionino, senza dover collegare un artista di prova.</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="test">
      <button type="submit" class="btn secondary">Testa connessione</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
