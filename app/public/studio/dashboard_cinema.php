<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
if (!siteToolEnabled('cinema')) {
    http_response_code(404);
    exit('Questa funzione non è attiva su questo sito.');
}
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
        $priceRaw = trim(str_replace(',', '.', $_POST['ticket_price'] ?? ''));
        if ($jsonUrl !== '' && !filter_var($jsonUrl, FILTER_VALIDATE_URL)) {
            $error = 'L\'URL inserito non è valido.';
        } elseif ($priceRaw !== '' && (!is_numeric($priceRaw) || (float) $priceRaw < 0)) {
            $error = 'Il prezzo del biglietto non è valido.';
        } else {
            $ticketPrice = $priceRaw !== '' ? round((float) $priceRaw, 2) : null;
            $stmt = getDB()->prepare('UPDATE profiles SET cinema_films_json_url=?, cinema_ticket_price=? WHERE user_id=?');
            $stmt->execute([$jsonUrl ?: null, $ticketPrice, $profile['id']]);
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
$merchantFeedUrl = siteUrl('/' . $profile['slug'] . '/cinema-feed.xml');

include __DIR__ . '/../_dash_header.php';
$hasUrl = !empty($profile['cinema_films_json_url']);
$cronLine = '0 5 * * * curl -s "' . $cronUrl . '" >/dev/null 2>&1';
?>
  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($syncResult): ?>
    <?php if ($syncResult['ok']): ?><div class="alert success">Sincronizzazione completata: <?= (int) $syncResult['added'] ?> film aggiunti, <?= (int) $syncResult['updated'] ?> aggiornati, <?= (int) $syncResult['removed'] ?> rimossi (<?= (int) $syncResult['total'] ?> film totali in programmazione).</div>
    <?php else: ?><div class="alert error">Sincronizzazione non riuscita: <?= e($syncResult['error']) ?></div><?php endif; ?>
  <?php endif; ?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= $filmCount ?></b><span>Film pubblicati</span></div>
      <div class="tlx-kpi"><b><?= $profile['cinema_ticket_price'] !== null ? '€ ' . e(number_format((float) $profile['cinema_ticket_price'], 2, ',', '.')) : '—' ?></b><span>Prezzo biglietto</span></div>
      <div class="tlx-kpi"><b><?= !empty($profile['cinema_films_synced_at']) ? e(formatLocalDateTime($profile['cinema_films_synced_at'], $profile, 'd/m H:i')) : 'Mai' ?></b><span>Ultima sincronizzazione</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_links.php"><b><i class="fa-solid fa-link"></i></b><span>Pulsanti nei Link</span></a>
    </div>

    <form method="post" class="box">
      <?= csrfField() ?><input type="hidden" name="action" value="save_url">
      <h3><i class="fa-solid fa-film"></i> Fonte dei film</h3>
      <label for="cn-url">Indirizzo del JSON dei film in programmazione</label>
      <input type="url" name="json_url" id="cn-url" value="<?= e($profile['cinema_films_json_url'] ?? '') ?>" placeholder="https://.../api/v2/films/expanded.json">
      <label for="cn-price">Prezzo del biglietto in € <small>(opzionale, per il feed Google/Meta)</small></label>
      <input type="number" name="ticket_price" id="cn-price" step="0.01" min="0" value="<?= $profile['cinema_ticket_price'] !== null ? e((string) $profile['cinema_ticket_price']) : '' ?>" placeholder="es. 8">
      <button type="submit" class="tlx-submit cmp-done">Salva</button>
    </form>

    <?php if ($hasUrl): ?>
      <div class="box">
        <h3><i class="fa-solid fa-rotate"></i> Sincronizzazione</h3>
        <p><?= $filmCount ?> film attualmente pubblicati.<?= !empty($profile['cinema_films_synced_at']) ? ' Ultima sincronizzazione: ' . e(formatLocalDateTime($profile['cinema_films_synced_at'], $profile)) . '.' : ' Non ancora sincronizzato.' ?><?php if ($filmCount > 0): ?> <a href="/dashboard_links.php">Vedi i pulsanti nel modulo Link</a>.<?php endif; ?></p>
        <form method="post" data-busy="Sincronizzazione in corso…"><?= csrfField() ?><input type="hidden" name="action" value="sync"><button type="submit" class="stc-act primary"><i class="fa-solid fa-rotate"></i> <span>Sincronizza ora</span></button></form>
      </div>

      <div class="box">
        <h3><i class="fa-regular fa-clock"></i> Aggiornamento automatico</h3>
        <p>Per aggiornare i film da solo (es. ogni giorno alle 5:00) chiedi di aggiungere questa riga al crontab del server. Sincronizza <b>tutti</b> i profili che hanno un indirizzo JSON impostato. Il token nell'indirizzo è segreto: non condividerlo.</p>
        <div class="box-pre"><?= e($cronLine) ?><button type="button" class="stc-act" data-copy="<?= e($cronLine) ?>" aria-label="Copia"><i class="fa-regular fa-copy"></i></button></div>
      </div>

      <div class="box">
        <h3><i class="fa-solid fa-bag-shopping"></i> Feed prodotti per Google Merchant e Meta</h3>
        <p>Un file XML con un «prodotto» per ogni film in programmazione (titolo, descrizione, locandina, link, prezzo), sempre aggiornato. Incolla l'indirizzo in Google Merchant Center («Recupero pianificato») o Meta Commerce Manager («Origine dati → Feed programmato»).</p>
        <?php if ($profile['cinema_ticket_price'] === null): ?><div class="alert error">Imposta prima il prezzo del biglietto: senza un prezzo il feed non è utilizzabile da Google e Meta.</div><?php endif; ?>
        <div class="box-pre"><?= e($merchantFeedUrl) ?><button type="button" class="stc-act" data-copy="<?= e($merchantFeedUrl) ?>" aria-label="Copia"><i class="fa-regular fa-copy"></i></button></div>
      </div>
    <?php else: ?>
      <div class="tlx-empty"><i class="fa-solid fa-film"></i><b>Collega la fonte dei film</b><span>Inserisci l'indirizzo del JSON qui sopra: poi potrai sincronizzare i film e generare il feed.</span></div>
    <?php endif; ?>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
