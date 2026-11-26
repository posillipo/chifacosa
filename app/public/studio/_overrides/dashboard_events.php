<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/event_geo.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
$activeTab = 'events';
$pageTitle = 'Eventi';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $provincia = trim($_POST['provincia'] ?? '');
        // Interpretato nel fuso orario scelto dal profilo (Dashboard -> Profilo e anagrafica),
        // non in quello del server — vedi parseLocalDateTime() in functions.php.
        $date = parseLocalDateTime($_POST['event_date'] ?? '', $profile, browserTzOffsetFromRequest()) ?? '';
        $ticketUrl = trim($_POST['ticket_url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isPerpetual = isset($_POST['is_perpetual']) ? 1 : 0;
        $recurrenceRaw = $_POST['recurrence'] ?? 'none';
        $recurrence = in_array($recurrenceRaw, ['none', 'weekdays', 'weekend'], true) ? $recurrenceRaw : 'none';
        $acceptsReservations = isset($_POST['accepts_reservations']) ? 1 : 0;
        $publishAtRaw = trim($_POST['publish_at'] ?? '');
        $publishAt = $publishAtRaw !== '' ? (parseLocalDateTime($publishAtRaw, $profile, browserTzOffsetFromRequest()) ?? null) : null;
        // Città e provincia obbligatorie: la provincia si sceglie dall'elenco delle province italiane (salvata col nome, es. «Napoli»).
        $provNorm = provinceNormalize($provincia);
        if ($provNorm !== null) { $provincia = provinceList()[$provNorm]; }
        if ($title === '' || $date === '') {
            $error = 'Titolo e data sono obbligatori.';
        } elseif (mb_strlen($city) < 2 || $provNorm === null) {
            $error = 'Città e provincia sono obbligatorie: scrivi la città e scegli la provincia dall\'elenco.';
        } else {
            $coverPath = handleCoverUpload($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO events (user_id, title, venue, city, provincia, event_date, ticket_url, description, is_perpetual, recurrence, cover_path, accepts_reservations, publish_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$profile['id'], $title, $venue ?: null, $city ?: null, $provincia ?: null, $date, $ticketUrl ?: null, $description ?: null, $isPerpetual, $recurrence, $coverPath, $acceptsReservations, $publishAt]);
            $newEventId = (int) getDB()->lastInsertId();
            setContentAuthor('events', $newEventId, dashboardAuthorSignature($user));
            eventGeocodeAndSave($newEventId, (int) $profile['id']); // posizione sulla mappa pubblica

            $isScheduledForFuture = $publishAt !== null && strtotime($publishAt) > time();
            if (!$isScheduledForFuture) {
                $eventUrl = siteUrl('/' . $profile['slug'] . '/eventi/' . $newEventId);
                notifyFollowersNewContent((int)$profile['id'], $profile['display_name'], $profile['slug'], 'evento', $title, $eventUrl);
                require_once __DIR__ . '/../../src/metricool_queue.php';
                socialQueueEnqueueIfEnabled((int) $profile['id'], 'event', [
                    'content_id' => $newEventId,
                    'title'      => $title,
                    'body'       => $description,
                    'image_url'  => $coverPath ? siteUrl($coverPath) : null,
                    'public_url' => $eventUrl,
                ]);
            } else {
                // Programmato: si accoda subito con la data di pubblicazione (prima restava fuori dalla coda).
                require_once __DIR__ . '/../../src/metricool_queue.php';
                socialQueueEnqueueIfEnabled((int) $profile['id'], 'event', [
                    'content_id' => $newEventId,
                    'title'      => $title,
                    'body'       => $description,
                    'image_url'  => $coverPath ? siteUrl($coverPath) : null,
                    'public_url' => siteUrl('/' . $profile['slug'] . '/eventi/' . $newEventId),
                ], $publishAt);
            }
        }
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $provincia = trim($_POST['provincia'] ?? '');
        // Interpretato nel fuso orario scelto dal profilo (Dashboard -> Profilo e anagrafica),
        // non in quello del server — vedi parseLocalDateTime() in functions.php.
        $date = parseLocalDateTime($_POST['event_date'] ?? '', $profile, browserTzOffsetFromRequest()) ?? '';
        $ticketUrl = trim($_POST['ticket_url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isPerpetual = isset($_POST['is_perpetual']) ? 1 : 0;
        $recurrenceRaw = $_POST['recurrence'] ?? 'none';
        $recurrence = in_array($recurrenceRaw, ['none', 'weekdays', 'weekend'], true) ? $recurrenceRaw : 'none';
        $acceptsReservations = isset($_POST['accepts_reservations']) ? 1 : 0;
        $publishAtRaw = trim($_POST['publish_at'] ?? '');
        $publishAt = $publishAtRaw !== '' ? (parseLocalDateTime($publishAtRaw, $profile, browserTzOffsetFromRequest()) ?? null) : null;
        // Città e provincia obbligatorie: la provincia si sceglie dall'elenco delle province italiane (salvata col nome, es. «Napoli»).
        $provNorm = provinceNormalize($provincia);
        if ($provNorm !== null) { $provincia = provinceList()[$provNorm]; }
        if ($title === '' || $date === '') {
            $error = 'Titolo e data sono obbligatori.';
        } elseif (mb_strlen($city) < 2 || $provNorm === null) {
            $error = 'Città e provincia sono obbligatorie: scrivi la città e scegli la provincia dall\'elenco.';
        } else {
            $newCoverPath = handleCoverUpload($profile['slug']);
            if ($newCoverPath) {
                $stmt = getDB()->prepare('SELECT cover_path FROM events WHERE id=? AND user_id=?');
                $stmt->execute([$id, $profile['id']]);
                if ($old = $stmt->fetch()) {
                    deleteCoverFile($old['cover_path']);
                }
                $stmt = getDB()->prepare('UPDATE events SET title=?, venue=?, city=?, provincia=?, event_date=?, ticket_url=?, description=?, is_perpetual=?, recurrence=?, cover_path=?, accepts_reservations=?, publish_at=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $venue ?: null, $city ?: null, $provincia ?: null, $date, $ticketUrl ?: null, $description ?: null, $isPerpetual, $recurrence, $newCoverPath, $acceptsReservations, $publishAt, $id, $profile['id']]);
            } else {
                $stmt = getDB()->prepare('UPDATE events SET title=?, venue=?, city=?, provincia=?, event_date=?, ticket_url=?, description=?, is_perpetual=?, recurrence=?, accepts_reservations=?, publish_at=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $venue ?: null, $city ?: null, $provincia ?: null, $date, $ticketUrl ?: null, $description ?: null, $isPerpetual, $recurrence, $acceptsReservations, $publishAt, $id, $profile['id']]);
            }
            eventGeocodeAndSave($id, (int) $profile['id']); // ricalcola solo se l'indirizzo è cambiato
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM events WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM events WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'geocode_missing') {
        // Calcola la posizione degli eventi già presenti, a piccoli gruppi: OpenStreetMap chiede al
        // massimo una richiesta al secondo. Si può premere più volte finché non ne restano.
        @set_time_limit(60);
        $done = 0;
        $notFound = 0;
        foreach (eventsMissingGeo((int) $profile['id'], 8) as $evId) {
            $r = eventGeocodeAndSave($evId, (int) $profile['id']);
            $r === 'ok' ? $done++ : ($r === 'notfound' ? $notFound++ : null);
            usleep(1200000);
        }
        $left = count(eventsMissingGeo((int) $profile['id']));
        header('Location: /dashboard_events.php?geo=' . $done . '-' . $notFound . '-' . $left);
        exit;
    } elseif ($action === 'toggle_reservations') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE events SET accepts_reservations = NOT accepts_reservations WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    }
    if (!$error) {
        header('Location: /dashboard_events.php');
        exit;
    }
}

$stmt = getDB()->prepare('SELECT * FROM events WHERE user_id=? ORDER BY is_perpetual DESC, event_date ASC');
$stmt->execute([$profile['id']]);
$events = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
$geoMissing = count(eventsMissingGeo((int) $profile['id']));
$now = time();
$evNext = 0; $evSched = 0; $evRes = 0;
foreach ($events as $x) {
    $past = !(int) ($x['is_perpetual'] ?? 0) && ($x['recurrence'] ?? 'none') === 'none' && strtotime($x['event_date']) < $now;
    if (!$past) { $evNext++; }
    if (!empty($x['publish_at']) && strtotime($x['publish_at']) > $now) { $evSched++; }
    if ((int) $x['accepts_reservations'] === 1) { $evRes++; }
}
$months = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['geo']) && preg_match('/^(\d+)-(\d+)-(\d+)$/', $_GET['geo'], $gm)): ?>
    <div class="alert success"><?= (int) $gm[1] ?> posizioni calcolate<?= (int) $gm[2] ? ', ' . (int) $gm[2] . ' indirizzi non trovati (controlla locale e città)' : '' ?><?= (int) $gm[3] ? '. Ne restano ' . (int) $gm[3] . ': premi di nuovo il pulsante.' : '. Tutti gli eventi con indirizzo hanno ora una posizione.' ?></div>
  <?php endif; ?>
  <?php if ($geoMissing > 0): ?>
    <div class="evx-geo">
      <div><b><i class="fa-solid fa-location-dot"></i> Mappa degli eventi</b>
        <p><?= $geoMissing ?> event<?= $geoMissing === 1 ? 'o' : 'i' ?> senza posizione sulla mappa pubblica. Si calcola da locale, città e provincia (OpenStreetMap, gratuito): 8 eventi per volta, circa un secondo ciascuno. I nuovi eventi vengono posizionati da soli al salvataggio.</p></div>
      <form method="post" data-busy="Calcolo in corso… (circa 10 secondi)"><?= csrfField() ?><input type="hidden" name="action" value="geocode_missing"><button type="submit" class="stc-act primary"><i class="fa-solid fa-location-crosshairs"></i> <span>Calcola le posizioni</span></button></form>
    </div>
  <?php endif; ?>

  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($events) ?></b><span>Eventi</span></div>
      <div class="tlx-kpi"><b><?= $evNext ?></b><span>In arrivo</span></div>
      <div class="tlx-kpi"><b><?= $evRes ?></b><span>Con prenotazioni</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_reservations.php"><b><i class="fa-solid fa-chair"></i></b><span>Vedi le prenotazioni</span></a>
    </div>

    <button type="button" class="cmp-trigger" data-dlg="ev-dlg"><span class="tlx-av"><i class="fa-regular fa-calendar-plus"></i></span><span class="cmp-trigger-t">Crea un nuovo evento…</span><span class="cmp-trigger-i"><i class="fa-regular fa-image"></i></span></button>

    <div class="tlx-listhead"><h2>I tuoi eventi <small>(<?= count($events) ?>)</small></h2>
      <div class="tlx-filters" data-filters="#ev-list"><button type="button" class="on" data-f="all">Tutti</button><button type="button" data-f="next">In arrivo</button><button type="button" data-f="past">Passati</button><button type="button" data-f="sched">Programmati</button><button type="button" data-f="rec">Ricorrenti</button></div>
    </div>
    <?php if (!$events): ?><div class="tlx-empty"><i class="fa-regular fa-calendar"></i><b>Nessun evento ancora</b><span>Crea il primo: comparirà sulla tua pagina, sulla mappa e nel Feed.</span></div><?php endif; ?>
    <div class="tlx-empty" data-filter-empty hidden><i class="fa-regular fa-circle-check"></i><b>Niente da mostrare con questo filtro</b></div>

    <div id="ev-list">
    <?php foreach ($events as $ev):
      $ts = strtotime($ev['event_date']);
      $rec = ($ev['recurrence'] ?? 'none') !== 'none' || (int) ($ev['is_perpetual'] ?? 0) === 1;
      $isPast = !$rec && $ts < $now;
      $isSched = !empty($ev['publish_at']) && strtotime($ev['publish_at']) > $now;
      $state = trim(($isPast ? 'past' : 'next') . ($isSched ? ' sched' : '') . ($rec ? ' rec' : ''));
      $scheduleLabel = eventScheduleLabel($ev['recurrence'] ?? 'none', (bool) ($ev['is_perpetual'] ?? false));
      $pub = siteUrl('/' . $profile['slug'] . '/eventi/' . (int) $ev['id']);
      $share = json_encode(['t' => $ev['title'], 'u' => $pub], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
      $fill = json_encode(['id' => (int) $ev['id'], 'title' => $ev['title'], 'venue' => $ev['venue'] ?? '', 'city' => $ev['city'] ?? '', 'provincia' => (($pn0 = provinceNormalize($ev['provincia'] ?? '')) !== null ? provinceList()[$pn0] : ''),
        'event_date' => date('Y-m-d\TH:i', $ts), 'ticket_url' => $ev['ticket_url'] ?? '', 'description' => $ev['description'] ?? '',
        'publish_at' => !empty($ev['publish_at']) ? date('Y-m-d\TH:i', strtotime($ev['publish_at'])) : '', 'recurrence' => $ev['recurrence'] ?? 'none',
        'is_perpetual' => (int) ($ev['is_perpetual'] ?? 0), 'accepts_reservations' => (int) $ev['accepts_reservations']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
      <article class="stc stc-list kit-item<?= $isPast ? ' off' : '' ?>" data-state="<?= e($state) ?>">
        <?php if ($ev['cover_path']): ?><span class="stc-thumb"><img src="/<?= e($ev['cover_path']) ?>" alt="" loading="lazy"></span>
        <?php else: ?><span class="stc-thumb evx-date"><b><?= date('j', $ts) ?></b><small><?= e($months[(int) date('n', $ts) - 1]) ?></small></span><?php endif; ?>
        <div class="stc-body">
          <div class="stc-meta1">
            <span class="stc-type stc-violet">Evento</span>
            <?php if ($isPast): ?><span class="stc-status stc-s-grey"><i class="fa-solid fa-flag-checkered"></i> Concluso</span>
            <?php elseif ($isSched): ?><span class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> Programmato · <?= e(date('d/m/Y H:i', strtotime($ev['publish_at']))) ?></span>
            <?php else: ?><span class="stc-status stc-s-ok"><i class="fa-solid fa-circle-check"></i> Pubblicato</span><?php endif; ?>
            <?php if ($scheduleLabel): ?><span class="tlx-tag"><i class="fa-solid fa-repeat"></i> <?= e($scheduleLabel) ?></span><?php endif; ?>
            <?= authorBadgeHtml($ev['created_by'] ?? null) ?>
          </div>
          <h3 class="stc-title"><?= e($ev['title']) ?></h3>
          <div class="stc-meta2"><i class="fa-regular fa-clock"></i> <?= e(formatLocalDateTime($ev['event_date'], $profile)) ?><?php if ($ev['venue'] || $ev['city']): ?> · <i class="fa-solid fa-location-dot"></i> <?= e($ev['venue']) ?><?= $ev['venue'] && $ev['city'] ? ', ' : '' ?><?= e($ev['city']) ?><?php endif; ?></div>
          <div class="stc-actions">
            <button type="button" class="stc-act primary" data-dlg="ev-dlg" data-fill='<?= e($fill) ?>'><i class="fa-solid fa-pen"></i> <span>Modifica</span></button>
            <a class="stc-act" href="/<?= e($profile['slug']) ?>/eventi/<?= (int) $ev['id'] ?>" target="_blank" title="Pagina pubblica"><i class="fa-solid fa-arrow-up-right-from-square"></i> <span>Pagina pubblica</span></a>
            <button type="button" class="stc-act" data-share='<?= e($share) ?>' title="Condividi"><i class="fa-solid fa-share-nodes"></i> <span>Condividi</span></button>
            <label class="tlx-switch kit-sw" title="Accetta prenotazioni"><input type="checkbox" <?= (int) $ev['accepts_reservations'] === 1 ? 'checked' : '' ?> data-post='<?= e(json_encode(['action' => 'toggle_reservations', 'id' => (int) $ev['id']])) ?>' data-msg-on="Prenotazioni attive" data-msg-off="Prenotazioni chiuse"><i></i><span class="kit-sw-l">Prenotazioni</span></label>
            <form method="post" class="ibx-inline" data-confirm="Eliminare questo evento?"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><button class="stc-act danger" type="submit" title="Elimina"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button></form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </div>

  <?php studioDlgOpen('ev-dlg', ['new_title' => 'Nuovo evento', 'edit_title' => 'Modifica evento', 'action_new' => 'add', 'action_edit' => 'edit']); ?>
    <label for="ev-title">Nome dell'evento</label>
    <input type="text" name="title" id="ev-title" required>
    <div class="kit-row2">
      <div><label for="ev-venue">Location</label><input type="text" name="venue" id="ev-venue"></div>
      <div><label for="ev-city">Città <span class="pfx-req">*</span></label><input type="text" name="city" id="ev-city" required minlength="2"></div>
    </div>
    <label for="ev-prov">Provincia <span class="pfx-req">*</span></label>
    <select name="provincia" id="ev-prov" required><?= provinceNameOptionsHtml(null) ?></select>
    <p class="tlx-hint">Città e provincia servono per raggruppare gli eventi per provincia e, con la location, per mostrarli sulla mappa.</p>
    <label for="ev-date">Data e ora</label>
    <input type="datetime-local" name="event_date" id="ev-date" required>
    <label for="ev-ticket">Link biglietti <small>(opzionale)</small></label>
    <input type="url" name="ticket_url" id="ev-ticket" placeholder="https://...">
    <label for="ev-desc">Descrizione <small>(opzionale)</small></label>
    <textarea name="description" id="ev-desc" rows="4" data-ai-target placeholder="Racconta l'evento: scaletta, ospiti, informazioni utili..."></textarea>
    <?php studioAiBox('es. concerto acustico sabato, ospite speciale a sorpresa'); ?>
    <label>Copertina <small>(opzionale: in modifica, vuoto = resta quella attuale)</small></label>
    <input type="file" name="cover" accept="image/*" data-preview="#ev-prev">
    <div id="ev-prev" class="kit-gallery" data-preview-box hidden></div>
    <label>Ricorrenza</label>
    <?php studioSeg('recurrence', ['none' => 'Data singola', 'weekdays' => 'Lun–Ven', 'weekend' => 'Weekend'], 'none'); ?>
    <?php studioSwitch('is_perpetual', 'Evento perpetuo (sempre visibile in «Prossimi eventi»)', false); ?>
    <?php studioSwitch('accepts_reservations', 'Accetta prenotazioni', false); ?>
    <label for="ev-pub">Pubblicazione programmata <small>(opzionale: l'evento resta nascosto fino a quel momento)</small></label>
    <input type="datetime-local" name="publish_at" id="ev-pub">
  <?php studioDlgClose('Salva evento'); ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
