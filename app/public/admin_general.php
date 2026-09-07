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

$homeMode = getSiteSetting('home_mode') ?: 'landing';
$singleProfileSlug = getSiteSetting('single_profile_slug') ?: '';

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
<?php include __DIR__ . '/_admin_footer.php'; ?>
