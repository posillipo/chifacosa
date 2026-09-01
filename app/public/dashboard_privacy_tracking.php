<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'privacy_tracking';
$pageTitle = 'Privacy e Tracking';
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $t = getProfileTracking($profile);

    $t['privacy_script'] = $_POST['privacy_script'] ?? '';
    $t['privacy_policy_url'] = trim($_POST['privacy_policy_url'] ?? '');
    $t['ga_measurement_id'] = trim($_POST['ga_measurement_id'] ?? '');
    $t['gtm_head_script'] = $_POST['gtm_head_script'] ?? '';
    $t['gtm_body_script'] = $_POST['gtm_body_script'] ?? '';
    $t['fb_pixel_script'] = $_POST['fb_pixel_script'] ?? '';
    $t['fb_pixel_id'] = trim($_POST['fb_pixel_id'] ?? '');
    $newCapiToken = trim($_POST['fb_capi_token'] ?? '');
    if ($newCapiToken !== '') {
        $t['fb_capi_token'] = $newCapiToken;
    } elseif (!empty($_POST['fb_capi_token_clear'])) {
        $t['fb_capi_token'] = '';
    }

    $stmt = getDB()->prepare('UPDATE profiles SET privacy_tracking_settings=? WHERE user_id=?');
    $stmt->execute([json_encode($t), $profile['id']]);
    $success = 'Impostazioni aggiornate. Saranno visibili sulla tua pagina pubblica entro pochi secondi.';
    $user = currentUser();
    $profile = getActingProfile($user);
}

$t = getProfileTracking($profile);
$hasCapiToken = trim($t['fb_capi_token'] ?? '') !== '';
$anyFilled = trim($t['privacy_script'] ?? '') !== ''
    || trim($t['ga_measurement_id'] ?? '') !== ''
    || trim($t['gtm_head_script'] ?? '') !== ''
    || trim($t['gtm_body_script'] ?? '') !== ''
    || trim($t['fb_pixel_script'] ?? '') !== '';

include __DIR__ . '/_dash_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Per impostazione predefinita la tua pagina pubblica usa lo script Privacy/Cookie e i
      tracciamenti (Google Analytics, Google Tag Manager, Meta Pixel) configurati a livello di
      sito da <?= e(siteName()) ?>. Se compili uno dei campi qui sotto, <strong>solo quel campo</strong>
      viene sostituito con il tuo — gli altri campi lasciati vuoti continuano a usare quelli
      generali del sito. Svuota un campo e salva per tornare a quello generale.
    </p>
    <p style="color:var(--text-muted)">
      Questa pagina è pensata per chi gestisce le proprie campagne pubblicitarie o ha un
      proprio obbligo di trasparenza privacy indipendente da <?= e(siteName()) ?> — se non sai
      di cosa si tratta, probabilmente non ti serve: lascia tutto vuoto.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label>Script privacy / cookie (HTML/JS fornito dal tuo servizio, es. Iubenda, Cookiebot)</label>
    <textarea name="privacy_script" rows="8" placeholder="&lt;script type=&quot;text/javascript&quot;&gt;...&lt;/script&gt;"><?= e($t['privacy_script'] ?? '') ?></textarea>

    <label>URL della tua Privacy Policy (per il link "Privacy" nel footer della tua pagina)</label>
    <input type="url" name="privacy_policy_url" value="<?= e($t['privacy_policy_url'] ?? '') ?>" placeholder="https://www.iubenda.com/privacy-policy/...">

    <label>Google Analytics — Measurement ID</label>
    <input type="text" name="ga_measurement_id" value="<?= e($t['ga_measurement_id'] ?? '') ?>" placeholder="G-XXXXXXXXXX">

    <label>Google Tag Manager — script per l'&lt;head&gt;</label>
    <textarea name="gtm_head_script" rows="4" placeholder="&lt;script&gt;(function(w,d,s,l,i){...})(window,document,'script','dataLayer','GTM-XXXXXXX');&lt;/script&gt;"><?= e($t['gtm_head_script'] ?? '') ?></textarea>

    <label>Google Tag Manager — snippet noscript per subito dopo &lt;body&gt;</label>
    <textarea name="gtm_body_script" rows="3" placeholder="&lt;noscript&gt;&lt;iframe src=&quot;https://www.googletagmanager.com/ns.html?id=GTM-XXXXXXX&quot; ...&gt;&lt;/iframe&gt;&lt;/noscript&gt;"><?= e($t['gtm_body_script'] ?? '') ?></textarea>

    <label>Facebook (Meta) Pixel — script completo</label>
    <textarea name="fb_pixel_script" rows="5" placeholder="&lt;script&gt;!function(f,b,e,v,n,t,s){...}(window, document,'script', 'https://connect.facebook.net/en_US/fbevents.js');&lt;/script&gt;"><?= e($t['fb_pixel_script'] ?? '') ?></textarea>

    <button type="submit" class="btn">Salva</button>
  </form>

  <?php if (!$anyFilled): ?>
    <div class="alert" style="background:rgba(0,0,0,0.05);color:var(--text-muted);">Nessun campo personalizzato: la tua pagina usa le impostazioni generali di <?= e(siteName()) ?>.</div>
  <?php endif; ?>

  <div class="card">
    <strong>Meta Conversions API</strong>
    <p style="color:var(--text-muted)">
      Invia lo stesso evento anche dal server — recupera dati persi dal solo Pixel per via di
      ad blocker o restrizioni Safari/iOS. Trovi entrambi i valori in Meta Events Manager → il
      tuo Pixel → Impostazioni → Conversions API → Genera token di accesso. Se compilato, invia
      automaticamente un evento quando qualcuno <strong>ti segue</strong>, <strong>ti scrive dal
      form Contatti</strong>, o <strong>prenota un tavolo/posto</strong> a un tuo evento.
    </p>
  </div>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="privacy_script" value="<?= e($t['privacy_script'] ?? '') ?>">
    <input type="hidden" name="privacy_policy_url" value="<?= e($t['privacy_policy_url'] ?? '') ?>">
    <input type="hidden" name="ga_measurement_id" value="<?= e($t['ga_measurement_id'] ?? '') ?>">
    <input type="hidden" name="gtm_head_script" value="<?= e($t['gtm_head_script'] ?? '') ?>">
    <input type="hidden" name="gtm_body_script" value="<?= e($t['gtm_body_script'] ?? '') ?>">
    <input type="hidden" name="fb_pixel_script" value="<?= e($t['fb_pixel_script'] ?? '') ?>">

    <label>ID del Pixel</label>
    <input type="text" name="fb_pixel_id" placeholder="es. 123456789012345" value="<?= e($t['fb_pixel_id'] ?? '') ?>">

    <label>Token di accesso Conversions API</label>
    <input type="password" name="fb_capi_token" placeholder="<?= $hasCapiToken ? '••••••••  (lascia vuoto per non modificarlo)' : 'incolla qui il token generato da Meta' ?>">

    <?php if ($hasCapiToken): ?>
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;">
        <input type="checkbox" name="fb_capi_token_clear" value="1" style="width:auto;"> Rimuovi il token salvato (torna a quello generale del sito, se impostato)
      </label>
    <?php endif; ?>

    <button type="submit" class="btn">Salva Conversions API</button>
  </form>
<?php include __DIR__ . '/_dash_footer.php'; ?>
