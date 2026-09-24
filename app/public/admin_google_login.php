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
    $newSecret = trim($_POST['google_oauth_client_secret'] ?? '');
    if ($newSecret !== '') {
        setSiteSetting('google_oauth_client_secret', $newSecret);
    }
    $success = 'Credenziali Google salvate.';
}

$clientId = getSiteSetting('google_oauth_client_id') ?: '';
$hasSecret = (getSiteSetting('google_oauth_client_secret') ?: '') !== '';
$redirectUri = googleOAuthRedirectUri();
// L'URI sopra riflette l'host da cui STAI navigando ORA questa pagina (con o senza "www."): se
// il sito è raggiungibile anche dall'altra variante, va registrata anche quella, altrimenti chi
// vi arriva vede il login con Google fallire in modo intermittente (vedi il commento su
// googleOAuthRedirectUri() in google_oauth.php per il perché).
$redirectUriAltHost = null;
if (preg_match('#^(https?://)(?:www\.)?(.+)$#i', $redirectUri, $m)) {
    $redirectUriAltHost = str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'www.')
        ? $m[1] . $m[2]
        : $m[1] . 'www.' . $m[2];
}

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
      aggiungi <strong>entrambe</strong> queste righe (una per riga, "Aggiungi URI" per la
      seconda) — il sito potrebbe essere raggiungibile sia con che senza "www.", ed entrambe le
      varianti vanno registrate perché il login con Google non fallisca in modo intermittente a
      seconda di quale usa chi accede:
    </p>
    <p><code><?= e($redirectUri) ?></code><?php if ($redirectUriAltHost): ?><br><code><?= e($redirectUriAltHost) ?></code><?php endif; ?></p>
    <p style="color:var(--text-muted)">
      Copia poi qui sotto il Client ID e il Client Secret mostrati da Google.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label>Google OAuth Client ID</label>
    <input type="text" name="google_oauth_client_id" value="<?= e($clientId) ?>" placeholder="es. 123456-abc.apps.googleusercontent.com">
    <label>Google OAuth Client Secret</label>
    <input type="password" name="google_oauth_client_secret" placeholder="<?= $hasSecret ? '••••••••  (lascia vuoto per non modificarlo)' : 'es. GOCSPX-...' ?>">
    <button type="submit" class="btn">Salva credenziali</button>
  </form>
<?php include __DIR__ . '/_admin_footer.php'; ?>
