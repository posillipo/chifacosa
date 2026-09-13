<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'privacy';
$pageTitle = 'Privacy / Cookie';
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save_privacy';

    if ($action === 'save_privacy') {
        setSiteSetting('privacy_script', $_POST['privacy_script'] ?? '');
        setSiteSetting('privacy_policy_url', trim($_POST['privacy_policy_url'] ?? ''));
        $success = 'Script privacy aggiornato. Sarà visibile su tutte le pagine pubbliche entro pochi secondi.';
    } elseif ($action === 'save_follow_terms') {
        setSiteSetting('follow_terms_content', trim($_POST['follow_terms_content'] ?? ''));
        $success = 'Termini di Utilizzo aggiornati.';
    } elseif ($action === 'save_ga') {
        $gaId = trim($_POST['ga_measurement_id'] ?? '');
        setSiteSetting('ga_measurement_id', $gaId);
        $success = $gaId !== ''
            ? 'ID Google Analytics salvato. Il tracciamento è attivo su tutte le pagine pubbliche.'
            : 'Google Analytics disattivato (ID rimosso).';
    }
}

$currentScript = getSiteSetting('privacy_script') ?: '';
$privacyPolicyUrl = getSiteSetting('privacy_policy_url') ?: '';
$gaId = getSiteSetting('ga_measurement_id') ?: '';
$followTermsContent = getSiteSetting('follow_terms_content') ?: '';

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <div class="card">
    <strong>Come funziona — Privacy / Cookie</strong>
    <p style="color:var(--text-muted)">
      Incolla qui sotto lo script di embed fornito dal tuo servizio di gestione privacy/cookie
      (es. <a href="https://www.iubenda.com/" target="_blank">Iubenda</a>, Cookiebot, ecc.).
      Lo script viene inserito automaticamente nell'<code>&lt;head&gt;</code> di ogni pagina pubblica
      del sito (homepage, pagine artista, blog, brani, eventi, contatti) — non serve modificare il codice.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_privacy">
    <label>Script privacy / cookie (HTML/JS fornito dal servizio esterno)</label>
    <textarea name="privacy_script" rows="10" placeholder="&lt;script type=&quot;text/javascript&quot;&gt;...&lt;/script&gt;"><?= e($currentScript) ?></textarea>
    <label>URL della tua Privacy Policy (per il link "Privacy" nel footer delle pagine pubbliche)</label>
    <input type="url" name="privacy_policy_url" value="<?= e($privacyPolicyUrl) ?>" placeholder="https://www.iubenda.com/privacy-policy/...">
    <button type="submit" class="btn">Salva script</button>
  </form>

  <?php if (trim($currentScript) === ''): ?>
    <div class="alert error">Nessuno script privacy configurato al momento — le pagine pubbliche non mostrano alcun banner cookie.</div>
  <?php else: ?>
    <div class="alert success">Script attivo su tutte le pagine pubbliche.</div>
  <?php endif; ?>

  <hr style="margin:32px 0; border-color:rgba(0,0,0,.1);">

  <div class="card">
    <strong>Come funziona — Termini di Utilizzo (chi segue via email)</strong>
    <p style="color:var(--text-muted)">
      Testo mostrato su <?= e(siteUrl('/termini_segui.php')) ?>, la pagina collegata alla casella
      "Accetto i Termini di Utilizzo" che compare nel modulo "Segui" per chi non ha un account
      (email pubbliche) — chi ha già un account e segue con un click resta escluso, dato che ha
      già un rapporto diretto con la piattaforma. Se lasci il campo vuoto la casella non compare
      affatto nel modulo: non ha senso chiedere di accettare termini che non esistono.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_follow_terms">
    <label>Testo dei Termini di Utilizzo</label>
    <textarea name="follow_terms_content" rows="10" placeholder="Iscrivendoti accetti di ricevere email di notifica quando questo profilo pubblica nuovi contenuti..."><?= e($followTermsContent) ?></textarea>
    <button type="submit" class="btn">Salva Termini di Utilizzo</button>
  </form>

  <?php if (trim($followTermsContent) === ''): ?>
    <div class="alert error">Nessun termine configurato — la casella di accettazione non compare nel modulo "Segui" via email.</div>
  <?php else: ?>
    <div class="alert success">Casella di accettazione attiva nel modulo "Segui" via email.</div>
  <?php endif; ?>

  <hr style="margin:32px 0; border-color:rgba(0,0,0,.1);">

  <div class="card">
    <strong>Come funziona — Google Analytics</strong>
    <p style="color:var(--text-muted)">
      Inserisci solo il <strong>Measurement ID</strong> di Google Analytics 4 (formato
      <code>G-XXXXXXXXXX</code>), lo trovi in Google Analytics → Amministrazione → Flussi di dati
      → (il tuo flusso web). Lo snippet completo (gtag.js) viene generato e inserito
      automaticamente — non serve incollare codice.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_ga">
    <label>Google Analytics — Measurement ID</label>
    <input type="text" name="ga_measurement_id" value="<?= e($gaId) ?>" placeholder="G-XXXXXXXXXX">
    <button type="submit" class="btn">Salva Google Analytics</button>
  </form>

  <?php if (trim($gaId) === ''): ?>
    <div class="alert error">Google Analytics non configurato — nessun tracciamento attivo.</div>
  <?php else: ?>
    <div class="alert success">Google Analytics attivo (ID: <?= e($gaId) ?>) su tutte le pagine pubbliche.</div>
  <?php endif; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
