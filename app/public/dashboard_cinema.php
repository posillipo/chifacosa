<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'cinema';
$pageTitle = 'Cinema — Film in programmazione';
$error = null;
$syncResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_url') {
        $jsonUrl = trim($_POST['json_url'] ?? '');
        if ($jsonUrl !== '' && !filter_var($jsonUrl, FILTER_VALIDATE_URL)) {
            $error = 'L\'URL inserito non è valido.';
        } else {
            $stmt = getDB()->prepare('UPDATE profiles SET cinema_films_json_url=? WHERE user_id=?');
            $stmt->execute([$jsonUrl ?: null, $profile['id']]);
            $user = currentUser();
            $profile = getActingProfile($user);
        }
    } elseif ($action === 'sync') {
        $syncResult = syncCinemaFilms($profile);
        $user = currentUser();
        $profile = getActingProfile($user);
    }
}

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM links WHERE user_id=? AND link_type='film'");
$stmt->execute([$profile['id']]);
$filmCount = (int) $stmt->fetch()['c'];

$cronToken = getCinemaSyncCronToken();
$cronUrl = siteUrl('/cron_cinema_sync.php') . '?token=' . urlencode($cronToken);

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Funzionalità dedicata ai profili cinema: incolla qui l'URL di un feed JSON dei film in
      programmazione (formato 18tickets, con un campo <code>films</code>) e verrà creato
      automaticamente un pulsante per ogni film nel modulo <strong>Link</strong> — con
      locandina, titolo e link alla pagina del film. Lato pubblico, i pulsanti dei film
      compaiono sempre <strong>in fondo</strong> all'elenco Link, dopo tutti gli altri pulsanti
      che hai pubblicato, indipendentemente dall'ordine impostato in Link.
    </p>
    <p style="color:var(--text-muted)">
      Ogni sincronizzazione è una <strong>sincronizzazione vera</strong>: aggiunge i film nuovi
      trovati nel JSON, aggiorna quelli già presenti, e rimuove quelli non più in programmazione
      — il modulo Link rispecchia sempre esattamente il JSON. Puoi sincronizzare a mano quando
      vuoi col pulsante qui sotto, oppure impostare un aggiornamento automatico periodico (es.
      una volta al giorno) seguendo le istruzioni più in basso.
    </p>
  </details>

  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($syncResult): ?>
    <?php if ($syncResult['ok']): ?>
      <div class="alert success">
        Sincronizzazione completata: <?= (int) $syncResult['added'] ?> film aggiunti,
        <?= (int) $syncResult['updated'] ?> aggiornati, <?= (int) $syncResult['removed'] ?> rimossi
        (<?= (int) $syncResult['total'] ?> film totali in programmazione).
      </div>
    <?php else: ?>
      <div class="alert error">Sincronizzazione non riuscita: <?= e($syncResult['error']) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_url">
    <label>URL del JSON film in programmazione</label>
    <input type="url" name="json_url" value="<?= e($profile['cinema_films_json_url'] ?? '') ?>" placeholder="https://.../api/v2/films/expanded.json">
    <button type="submit" class="btn">Salva URL</button>
  </form>

  <?php if (!empty($profile['cinema_films_json_url'])): ?>
    <div class="card">
      <strong>Stato sincronizzazione</strong>
      <p style="color:var(--text-muted);margin:6px 0 14px;">
        <?= $filmCount ?> film attualmente pubblicati.
        <?php if (!empty($profile['cinema_films_synced_at'])): ?>
          Ultima sincronizzazione: <?= e(date('d/m/Y H:i', strtotime($profile['cinema_films_synced_at']))) ?>.
        <?php else: ?>
          Non ancora sincronizzato.
        <?php endif; ?>
        <?php if ($filmCount > 0): ?> — <a href="/dashboard_links.php">vedi i pulsanti nel modulo Link</a>.<?php endif; ?>
      </p>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="sync">
        <button type="submit" class="btn">Sincronizza ora</button>
      </form>
    </div>

    <div class="card">
      <strong>Aggiornamento automatico periodico</strong>
      <p style="color:var(--text-muted)">
        Per aggiornare i film da solo (es. una volta al giorno) senza dover aprire questa pagina,
        chiedi di aggiungere questa riga al crontab del server (es. ogni giorno alle 5:00):
      </p>
      <pre style="background:rgba(0,0,0,0.05);padding:10px 12px;border-radius:8px;overflow-x:auto;font-size:12.5px;">0 5 * * * curl -s "<?= e($cronUrl) ?>" >/dev/null 2>&1</pre>
      <p style="color:var(--text-muted);font-size:12.5px;">
        Sincronizza automaticamente <strong>tutti</strong> i profili che hanno un URL JSON
        configurato in questa pagina, non solo il tuo — basta impostarlo una volta sul server.
        Il token nell'URL è segreto: non condividerlo.
      </p>
    </div>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
