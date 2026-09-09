<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'general';
$pageTitle = 'Impostazioni generali';
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save_home_mode';

    if ($action === 'clear_share_cache') {
        $cleared = clearFeedShareImageCache();
        $success = $cleared > 0
            ? 'Cancellate ' . $cleared . ' immagini per la condivisione social — verranno rigenerate automaticamente alla prossima visita/condivisione di ciascun post.'
            : 'Nessuna immagine da cancellare al momento.';
    } else {
        $mode = ($_POST['home_mode'] ?? 'landing') === 'single_profile' ? 'single_profile' : 'landing';
        $slug = trim($_POST['single_profile_slug'] ?? '');

        if ($mode === 'single_profile') {
            $stmt = getDB()->prepare('SELECT id FROM users WHERE slug = ? AND is_active = 1');
            $stmt->execute([$slug]);
            if (!$stmt->fetch()) {
                $error = 'Nessun profilo attivo trovato con questo slug: "' . $slug . '". Impostazione non salvata.';
            }
        }

        if (!$error) {
            setSiteSetting('home_mode', $mode);
            setSiteSetting('single_profile_slug', $slug);
            $success = $mode === 'single_profile'
                ? 'Salvato: chi arriva sul dominio principale viene ora reindirizzato a /' . e($slug) . '.'
                : 'Salvato: la home page pubblica (login/registrazione) è di nuovo attiva per tutti.';
        }
    }
}

$homeMode = getSiteSetting('home_mode') ?: 'landing';
$singleProfileSlug = getSiteSetting('single_profile_slug') ?: '';
$shareImageStatus = diagnoseFeedShareImage();

// Diagnostica fusi orari: l'app (PHP) e il database (MySQL) girano in due container separati, e
// tutte le conversioni fatte in PHP (formatLocalDateTime()/parseLocalDateTime()) presumono che
// condividano lo stesso fuso — dato che i confronti con date salvate dall'utente avvengono
// sempre con NOW()/CURRENT_TIMESTAMP di MySQL. Se sono diversi, ogni conversione parte da un
// presupposto sbagliato: qui si vede subito se è così, senza bisogno di un accesso alla shell.
$phpTimezone = date_default_timezone_get();
$phpNow = date('Y-m-d H:i:s');
try {
    $dbTzRow = getDB()->query("SELECT NOW() AS db_now, @@session.time_zone AS session_tz, @@global.time_zone AS global_tz")->fetch();
} catch (Throwable $e) {
    $dbTzRow = null;
}
$tzMismatchSeconds = $dbTzRow ? abs(strtotime($phpNow) - strtotime($dbTzRow['db_now'])) : null;

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="card">
    <strong>Come funziona — Home page del portale</strong>
    <p style="color:var(--text-muted)">
      Per default, chi arriva sul dominio principale (<?= e(siteUrl('/')) ?>) vede la landing page
      pubblica con login e registrazione — pensata per un portale multi-azienda dove chiunque può
      iscriversi e creare la propria pagina.
    </p>
    <p style="color:var(--text-muted)">
      Se invece questa installazione serve <strong>una sola azienda/profilo</strong> (nessun
      bisogno delle funzionalità da social network — registrazione, scoperta di altri profili),
      puoi far sì che chi arriva sul dominio principale venga reindirizzato direttamente a quel
      profilo. Il reindirizzamento è permanente (301): i motori di ricerca aggiornano la loro
      indicizzazione facendo confluire tutto sul profilo, senza penalità — è la stessa tecnica
      standard usata per "questo indirizzo porta sempre a quest'altro". Login, registrazione e
      area admin restano comunque raggiungibili direttamente dal loro indirizzo, per chi ne ha
      bisogno (es. te).
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <label style="display:flex;align-items:flex-start;gap:10px;font-weight:normal;margin-bottom:14px;">
      <input type="radio" name="home_mode" value="landing" <?= $homeMode !== 'single_profile' ? 'checked' : '' ?> style="width:auto;margin-top:3px;">
      <span><strong>Landing page pubblica</strong><br>
        <span style="color:var(--text-muted);font-size:13px;">Comportamento di sempre: chi arriva sul dominio vede la presentazione del portale, con login e registrazione.</span>
      </span>
    </label>
    <label style="display:flex;align-items:flex-start;gap:10px;font-weight:normal;margin-bottom:6px;">
      <input type="radio" name="home_mode" value="single_profile" <?= $homeMode === 'single_profile' ? 'checked' : '' ?> style="width:auto;margin-top:3px;">
      <span><strong>Reindirizza a un singolo profilo</strong><br>
        <span style="color:var(--text-muted);font-size:13px;">Chi arriva sul dominio principale viene mandato direttamente sulla pagina pubblica del profilo indicato qui sotto.</span>
      </span>
    </label>
    <label>Slug del profilo di destinazione</label>
    <input type="text" name="single_profile_slug" value="<?= e($singleProfileSlug) ?>" placeholder="es. nomeazienda">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:4px;">
      Lo slug è la parte finale dell'indirizzo pubblico del profilo (<?= e(siteUrl('/nomeazienda')) ?>).
      Deve corrispondere a un profilo esistente e attivo.
    </p>
    <button type="submit" class="btn" style="margin-top:10px;">Salva</button>
  </form>

  <?php if ($homeMode === 'single_profile' && $singleProfileSlug !== ''): ?>
    <div class="alert success">Attivo: <?= e(siteUrl('/')) ?> reindirizza a <?= e(siteUrl('/' . $singleProfileSlug)) ?>.</div>
  <?php else: ?>
    <div class="alert error">Non attivo: la landing page pubblica è visibile a chiunque arrivi sul dominio principale.</div>
  <?php endif; ?>

  <hr style="margin:32px 0; border-color:rgba(0,0,0,.1);">

  <div class="card">
    <strong>Diagnostica fusi orari (app vs database)</strong>
    <p style="color:var(--text-muted)">
      Le date che gli utenti digitano (validità offerte, data eventi, programmazione post) vengono
      convertite dal fuso scelto nel loro profilo al fuso di QUESTO server PHP, perché vengono poi
      confrontate con <code>NOW()</code> del database — se il container del database ha un fuso
      diverso da questo, il confronto parte già sbagliato. Qui sotto il confronto diretto, adesso.
    </p>
    <table style="width:100%;font-size:13.5px;">
      <tr><td style="padding:4px 8px;color:var(--text-muted);">Fuso orario PHP (questo server)</td><td style="padding:4px 8px;font-weight:700;"><?= e($phpTimezone) ?></td></tr>
      <tr><td style="padding:4px 8px;color:var(--text-muted);">Ora attuale secondo PHP</td><td style="padding:4px 8px;font-weight:700;"><?= e($phpNow) ?></td></tr>
      <?php if ($dbTzRow): ?>
        <tr><td style="padding:4px 8px;color:var(--text-muted);">Ora attuale secondo il database (NOW())</td><td style="padding:4px 8px;font-weight:700;"><?= e($dbTzRow['db_now']) ?></td></tr>
        <tr><td style="padding:4px 8px;color:var(--text-muted);">Fuso orario MySQL (session/global)</td><td style="padding:4px 8px;font-weight:700;"><?= e($dbTzRow['session_tz']) ?> / <?= e($dbTzRow['global_tz']) ?></td></tr>
      <?php else: ?>
        <tr><td colspan="2" style="padding:4px 8px;color:#c0392b;">Impossibile leggere l'ora dal database.</td></tr>
      <?php endif; ?>
    </table>
    <?php if ($tzMismatchSeconds !== null): ?>
      <?php if ($tzMismatchSeconds > 120): ?>
        <div class="alert error" style="margin-top:10px;">
          Scarto di <?= round($tzMismatchSeconds / 60) ?> minuti tra PHP e MySQL — sono su fusi
          orari diversi. Le conversioni per la visualizzazione/il salvataggio delle date partono
          quindi da un presupposto sbagliato: serve allineare i due container, o cambiare l'approccio
          (dimmelo e sistemo il codice per non dipendere da questo).
        </div>
      <?php else: ?>
        <div class="alert success" style="margin-top:10px;">PHP e MySQL sono allineati (scarto sotto i 2 minuti, normale differenza di rete/elaborazione).</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <strong>Come funziona — Scritta "Link Album in Descrizione" sui post con più foto</strong>
    <p style="color:var(--text-muted)">
      Diagnostica pensata per chi aggiorna solo da Portainer (senza un terminale sul server): qui
      sotto viene generata davvero, in questo momento, un'immagine di prova con lo stesso codice
      usato per i post veri — se la vedi con la scritta in basso, funziona; se la vedi identica
      (senza scritta), il server non ha il supporto necessario per scrivere con un font (manca
      FreeType nella build di GD) e serve intervenire sull'immagine Docker, non sul codice PHP.
    </p>
  </div>

  <div class="card">
    <strong>Stato</strong>
    <p style="margin:8px 0 4px;">
      GD con supporto FreeType (necessario per scrivere il testo):
      <?php if ($shareImageStatus['has_freetype']): ?>
        <span style="color:#2e7d32;font-weight:700;">✓ presente</span>
      <?php else: ?>
        <span style="color:#c0392b;font-weight:700;">✗ assente</span> — la scritta non può essere disegnata su questo server, qualunque cosa dica il codice PHP.
      <?php endif; ?>
    </p>
    <p style="margin:4px 0;">
      Font incluso nel progetto:
      <?php if ($shareImageStatus['font_exists']): ?>
        <span style="color:#2e7d32;font-weight:700;">✓ trovato</span>
      <?php else: ?>
        <span style="color:#c0392b;font-weight:700;">✗ non trovato</span> — verifica che il deploy includa <code>app/public/assets/themes/garden-anomaly/fonts/SpaceGrotesk-SemiBold.ttf</code>.
      <?php endif; ?>
    </p>
    <?php if ($shareImageStatus['error']): ?>
      <div class="alert error" style="margin-top:10px;"><?= e($shareImageStatus['error']) ?></div>
    <?php elseif ($shareImageStatus['preview_url']): ?>
      <p style="margin:14px 0 6px;">Anteprima generata ora (ricarica la pagina per rigenerarla):</p>
      <img src="<?= e($shareImageStatus['preview_url']) ?>" style="max-width:300px;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,0.15);display:block;">
      <p style="color:var(--text-muted);font-size:12.5px;margin-top:8px;">
        Se qui sopra <strong>non</strong> vedi la scritta "Link Album in Descrizione" in basso,
        conferma che è un limite del server (GD senza FreeType), non un problema di cache o di
        deploy mancato.
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <strong>Pulisci cache</strong>
    <p style="color:var(--text-muted)">
      Ogni immagine con la scritta viene generata una sola volta e tenuta in cache accanto
      all'originale — utile dopo una correzione al codice che le genera, per forzare la
      rigenerazione di tutte quelle già create in precedenza (altrimenti restano quelle vecchie
      finché la foto originale non cambia). Non cancella nessuna foto originale, solo le copie
      derivate.
    </p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="clear_share_cache">
      <button type="submit" class="btn secondary">Rigenera tutte le immagini per la condivisione social</button>
    </form>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
