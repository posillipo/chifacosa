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
    } elseif ($action === 'save_terms') {
        $newTerms = trim($_POST['terms_content'] ?? '');
        if ($newTerms !== '' && trim((string) (getSiteSetting('terms_content') ?: '')) === '') {
            setSiteSetting('terms_enabled_since', date('Y-m-d H:i:s')); // da qui in poi i nuovi iscritti devono accettare
        }
        setSiteSetting('terms_content', $newTerms);
        setSiteSetting('terms_gate_existing', !empty($_POST['terms_gate_existing']) ? '1' : '0');
        $success = $newTerms !== ''
            ? 'Condizioni d\'uso salvate. Chi si registra da ora deve accettarle.'
            : 'Condizioni d\'uso rimosse: la registrazione non richiede più l\'accettazione.';
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
$termsContent = getSiteSetting('terms_content') ?: '';
$termsGate = getSiteSetting('terms_gate_existing') === '1';

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
      Testo mostrato su <a href="<?= e(siteUrl('/termini_segui.php')) ?>" target="_blank" rel="noopener"><?= e(siteUrl('/termini_segui.php')) ?> <i class="fas fa-external-link-alt" style="font-size:.7em;"></i></a>, la pagina collegata alla casella
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
    <div class="card">
      <strong>Anteprima del documento pubblicato</strong>
      <span style="float:right;"><a href="<?= e(siteUrl('/termini_segui.php')) ?>" target="_blank" rel="noopener">Apri la pagina pubblica <i class="fas fa-external-link-alt" style="font-size:.75em;"></i></a></span>
      <hr style="margin:10px 0;">
      <div style="max-height:320px;overflow:auto;white-space:normal;"><?= nl2br(e($followTermsContent)) ?></div>
    </div>
  <?php endif; ?>

  <hr style="margin:32px 0; border-color:rgba(0,0,0,.1);">

  <div class="card">
    <strong>Come funziona — Condizioni d'uso (registrazione)</strong>
    <p style="color:var(--text-muted)">
      Testo pubblicato su <a href="<?= e(siteUrl('/condizioni.php')) ?>" target="_blank" rel="noopener"><?= e(siteUrl('/condizioni.php')) ?> <i class="fas fa-external-link-alt" style="font-size:.7em;"></i></a>.
      Se il campo non è vuoto, nel modulo di registrazione compare la casella obbligatoria
      "Ho letto e accetto le Condizioni d'uso", e si salva data e versione dell'accettazione. Se lo lasci vuoto non cambia nulla.
      Chi si registra con Google (senza passare dal modulo) accetta al primo accesso.
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_terms">
    <label>Testo delle Condizioni d'uso</label>
    <textarea name="terms_content" rows="14" placeholder="Incolla qui le Condizioni d'uso del portale..."><?= e($termsContent) ?></textarea>
    <label style="display:flex;align-items:flex-start;gap:8px;font-weight:normal;">
      <input type="checkbox" name="terms_gate_existing" value="1" <?= $termsGate ? 'checked' : '' ?> style="width:auto;margin-top:3px;">
      Chiedi l'accettazione anche agli utenti già registrati (la vedono una volta, al primo accesso dopo il salvataggio)
    </label>
    <button type="submit" class="btn">Salva Condizioni d'uso</button>
  </form>

  <?php if (trim($termsContent) === ''): ?>
    <div class="alert error">Nessuna condizione configurata — la registrazione non richiede accettazioni.</div>
  <?php else: ?>
    <div class="alert success">Condizioni attive: casella obbligatoria nella registrazione<?= $termsGate ? ' e richiesta agli utenti già registrati' : '' ?>.</div>
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
