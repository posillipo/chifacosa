<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/google_oauth.php';
$admin = requireAdmin();
$activeAdminTab = 'google_login';
$pageTitle = 'Accedi con Google';
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    setSiteSetting('google_oauth_client_id', trim($_POST['google_oauth_client_id'] ?? ''));
    setSiteSetting('google_oauth_client_secret', trim($_POST['google_oauth_client_secret'] ?? ''));
    $success = 'Credenziali Google salvate.';
}

$clientId = getSiteSetting('google_oauth_client_id') ?: '';
$clientSecret = getSiteSetting('google_oauth_client_secret') ?: '';
$redirectUri = googleOAuthRedirectUri();

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Abilita il pulsante "Accedi con Google" su login e registrazione — completamente gratuito
      (l'autenticazione OAuth di Google non ha costi, serve solo un progetto gratuito su Google
      Cloud Console). Chi accede così, se non ha ancora un account, ne ottiene subito uno nuovo
      (la registrazione è aperta a chiunque): non deve più scegliere una password, né confermare
      l'email — Google l'ha già verificata.
    </p>
    <p style="color:var(--text-muted)">
      Per ottenere le credenziali: vai su <a href="https://console.cloud.google.com/apis/credentials" target="_blank">console.cloud.google.com/apis/credentials</a>,
      crea un progetto gratuito (se non ne hai già uno), poi "Crea credenziali" → "ID client
      OAuth" → tipo applicazione "Applicazione web". In "URI di reindirizzamento autorizzati"
      incolla esattamente:
    </p>
    <p><code><?= e($redirectUri) ?></code></p>
    <p style="color:var(--text-muted)">
      Copia poi qui sotto il Client ID e il Client Secret mostrati da Google.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label>Google OAuth Client ID</label>
    <input type="text" name="google_oauth_client_id" value="<?= e($clientId) ?>" placeholder="es. 123456-abc.apps.googleusercontent.com">
    <label>Google OAuth Client Secret</label>
    <input type="text" name="google_oauth_client_secret" value="<?= e($clientSecret) ?>" placeholder="es. GOCSPX-...">
    <button type="submit" class="btn">Salva credenziali</button>
  </form>
<?php include __DIR__ . '/_admin_footer.php'; ?>
