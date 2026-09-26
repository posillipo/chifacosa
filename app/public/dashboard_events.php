<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
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
        if ($title === '' || $date === '') {
            $error = 'Titolo e data sono obbligatori.';
        } else {
            $coverPath = handleCoverUpload($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO events (user_id, title, venue, city, provincia, event_date, ticket_url, description, is_perpetual, recurrence, cover_path, accepts_reservations) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$profile['id'], $title, $venue ?: null, $city ?: null, $provincia ?: null, $date, $ticketUrl ?: null, $description ?: null, $isPerpetual, $recurrence, $coverPath, $acceptsReservations]);
            $newEventId = (int) getDB()->lastInsertId();

            $eventUrl = siteUrl('/' . $profile['slug'] . '/eventi/' . $newEventId);
            notifyFollowersNewContent((int)$profile['id'], $profile['display_name'], $profile['slug'], 'evento', $title, $eventUrl);
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
        if ($title === '' || $date === '') {
            $error = 'Titolo e data sono obbligatori.';
        } else {
            // Copertina opzionale: un nuovo file la sostituisce (e cancella quella precedente),
            // se il campo è lasciato vuoto quella già caricata resta invariata.
            $newCoverPath = handleCoverUpload($profile['slug']);
            if ($newCoverPath) {
                $stmt = getDB()->prepare('SELECT cover_path FROM events WHERE id=? AND user_id=?');
                $stmt->execute([$id, $profile['id']]);
                if ($old = $stmt->fetch()) {
                    deleteCoverFile($old['cover_path']);
                }
                $stmt = getDB()->prepare('UPDATE events SET title=?, venue=?, city=?, provincia=?, event_date=?, ticket_url=?, description=?, is_perpetual=?, recurrence=?, cover_path=?, accepts_reservations=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $venue ?: null, $city ?: null, $provincia ?: null, $date, $ticketUrl ?: null, $description ?: null, $isPerpetual, $recurrence, $newCoverPath, $acceptsReservations, $id, $profile['id']]);
            } else {
                $stmt = getDB()->prepare('UPDATE events SET title=?, venue=?, city=?, provincia=?, event_date=?, ticket_url=?, description=?, is_perpetual=?, recurrence=?, accepts_reservations=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $venue ?: null, $city ?: null, $provincia ?: null, $date, $ticketUrl ?: null, $description ?: null, $isPerpetual, $recurrence, $acceptsReservations, $id, $profile['id']]);
            }
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

include __DIR__ . '/_dash_header.php';
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="tz_offset_minutes" value="">
    <label>Nome evento</label>
    <input type="text" name="title" required>
    <label>Locale</label>
    <input type="text" name="venue">
    <label>Città</label>
    <input type="text" name="city">
    <label>Provincia (opzionale)</label>
    <input type="text" name="provincia" placeholder="es. Napoli">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Usata per raggruppare i tuoi eventi nel riquadro "Eventi per provincia".</p>
    <label>Data e ora</label>
    <input type="datetime-local" name="event_date" required>
    <label>Link biglietti (opzionale)</label>
    <input type="url" name="ticket_url" placeholder="https://...">
    <label>Descrizione (opzionale)</label>
    <textarea name="description" id="ev-add-description" rows="4" placeholder="Racconta l'evento: scaletta, ospiti, informazioni utili..."></textarea>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 14px;">
      <button type="button" class="btn small secondary" id="ev-add-ai-toggle">✨ Genera con AI</button>
    </div>
    <div id="ev-add-ai-panel" class="card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-8px 0 14px;">
      <label>Qualche parola chiave o istruzione per l'AI</label>
      <textarea id="ev-add-ai-keywords" rows="2" placeholder="es. concerto acustico sabato, ospite speciale a sorpresa"></textarea>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn small" id="ev-add-ai-generate">Genera testo</button>
        <button type="button" class="btn small secondary" id="ev-add-ai-cancel">Annulla</button>
      </div>
      <p id="ev-add-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
    </div>
    <label>Copertina (opzionale, jpg/png/webp)</label>
    <input type="file" name="cover" accept="image/*">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:6px;">Comparirà così come l'hai caricata, senza ritagli — qualsiasi proporzione va bene.</p>
    <label>Ricorrenza</label>
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="recurrence" value="none" checked style="width:auto;"> Data singola
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="recurrence" value="weekdays" style="width:auto;"> Dal Lunedì al Venerdì
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
        <input type="radio" name="recurrence" value="weekend" style="width:auto;"> Solo Weekend
      </label>
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:4px 0 8px;">
      <input type="checkbox" name="is_perpetual" value="1" style="width:auto;margin-bottom:0;">
      Evento perpetuo (nessuna data di fine — resta sempre visibile in "Prossimi eventi")
    </label>
    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:4px 0 16px;">
      <input type="checkbox" name="accepts_reservations" value="1" style="width:auto;margin-bottom:0;">
      Accetta prenotazioni per questo evento
    </label>
    <button type="submit" class="btn">Aggiungi evento</button>
  </form>

  <div class="section-title">Prossimi eventi (<?= count($events) ?>)</div>
  <?php foreach ($events as $ev): ?>
    <div class="event-item" style="display:flex;gap:14px;align-items:flex-start;">
      <?php if ($ev['cover_path']): ?>
        <img src="/<?= e($ev['cover_path']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;flex-shrink:0;">
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div class="date"><?= e(formatLocalDateTime($ev['event_date'], $profile)) ?></div>
        <strong><?= e($ev['title']) ?></strong>
        <?php if ($ev['venue'] || $ev['city']): ?>
          <div style="color:var(--text-muted)"><?= e($ev['venue']) ?><?= $ev['venue'] && $ev['city'] ? ', ' : '' ?><?= e($ev['city']) ?></div>
        <?php endif; ?>
        <?php if ((int) $ev['accepts_reservations'] === 1): ?>
          <div style="color:var(--accent);font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-chair"></i> Prenotazioni attive</div>
        <?php endif; ?>
        <?php $scheduleLabel = eventScheduleLabel($ev['recurrence'] ?? 'none', (bool) ($ev['is_perpetual'] ?? false)); ?>
        <?php if ($scheduleLabel): ?>
          <div style="color:var(--accent);font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-repeat"></i> <?= e($scheduleLabel) ?></div>
        <?php endif; ?>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_reservations">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <button class="btn small secondary" type="submit"><?= (int) $ev['accepts_reservations'] === 1 ? 'Disattiva prenotazioni' : 'Attiva prenotazioni' ?></button>
          </form>
          <form method="post" onsubmit="return confirm('Eliminare questo evento?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        </div>

        <details style="margin-top:8px;">
          <summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica</summary>
          <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <input type="hidden" name="tz_offset_minutes" value="">
            <label>Nome evento</label>
            <input type="text" name="title" value="<?= e($ev['title']) ?>" required>
            <label>Locale</label>
            <input type="text" name="venue" value="<?= e($ev['venue'] ?? '') ?>">
            <label>Città</label>
            <input type="text" name="city" value="<?= e($ev['city'] ?? '') ?>">
            <label>Provincia (opzionale)</label>
            <input type="text" name="provincia" value="<?= e($ev['provincia'] ?? '') ?>" placeholder="es. Napoli">
            <label>Data e ora</label>
            <input type="datetime-local" name="event_date" value="<?= e(date('Y-m-d\TH:i', strtotime($ev['event_date']))) ?>" required>
            <label>Link biglietti (opzionale)</label>
            <input type="url" name="ticket_url" value="<?= e($ev['ticket_url'] ?? '') ?>" placeholder="https://...">
            <label>Descrizione (opzionale)</label>
            <textarea name="description" class="ev-edit-description" rows="4" placeholder="Racconta l'evento: scaletta, ospiti, informazioni utili..."><?= e($ev['description'] ?? '') ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:-8px 0 14px;">
              <button type="button" class="btn small secondary ev-edit-ai-toggle">✨ Genera con AI</button>
            </div>
            <div class="ev-edit-ai-panel card" style="display:none;background:var(--bg-alt,#f7f7f9);margin:-8px 0 14px;">
              <label>Qualche parola chiave o istruzione per l'AI</label>
              <textarea class="ev-edit-ai-keywords" rows="2" placeholder="es. concerto acustico sabato, ospite speciale a sorpresa"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn small ev-edit-ai-generate">Genera testo</button>
                <button type="button" class="btn small secondary ev-edit-ai-cancel">Annulla</button>
              </div>
              <p class="ev-edit-ai-status" style="color:var(--text-muted);font-size:12.5px;margin:8px 0 0;"></p>
            </div>
            <label>Copertina (opzionale — lascia vuoto per non cambiarla)</label>
            <input type="file" name="cover" accept="image/*">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:6px;">
              <?= $ev['cover_path'] ? 'Hai già caricato una copertina — seleziona un nuovo file per sostituirla.' : 'Comparirà così come l\'hai caricata, senza ritagli — qualsiasi proporzione va bene.' ?>
            </p>
            <label>Ricorrenza</label>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="recurrence" value="none" <?= ($ev['recurrence'] ?? 'none') === 'none' ? 'checked' : '' ?> style="width:auto;"> Data singola
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="recurrence" value="weekdays" <?= ($ev['recurrence'] ?? '') === 'weekdays' ? 'checked' : '' ?> style="width:auto;"> Dal Lunedì al Venerdì
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-bottom:0;">
                <input type="radio" name="recurrence" value="weekend" <?= ($ev['recurrence'] ?? '') === 'weekend' ? 'checked' : '' ?> style="width:auto;"> Solo Weekend
              </label>
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:4px 0 8px;">
              <input type="checkbox" name="is_perpetual" value="1" <?= (int) ($ev['is_perpetual'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;margin-bottom:0;">
              Evento perpetuo (nessuna data di fine — resta sempre visibile in "Prossimi eventi")
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:4px 0 16px;">
              <input type="checkbox" name="accepts_reservations" value="1" <?= (int) $ev['accepts_reservations'] === 1 ? 'checked' : '' ?> style="width:auto;margin-bottom:0;">
              Accetta prenotazioni per questo evento
            </label>
            <button type="submit" class="btn small">Salva modifiche</button>
          </form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>

  <script>
  (function () {
    const toggleBtn = document.getElementById('ev-add-ai-toggle');
    const panel = document.getElementById('ev-add-ai-panel');
    const cancelBtn = document.getElementById('ev-add-ai-cancel');
    const generateBtn = document.getElementById('ev-add-ai-generate');
    const keywordsInput = document.getElementById('ev-add-ai-keywords');
    const statusEl = document.getElementById('ev-add-ai-status');
    const textarea = document.getElementById('ev-add-description');
    const csrfInput = toggleBtn.closest('form').querySelector('input[name="csrf"]');

    toggleBtn.addEventListener('click', function () {
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
      if (panel.style.display === 'block') keywordsInput.focus();
    });
    cancelBtn.addEventListener('click', function () {
      panel.style.display = 'none';
      statusEl.textContent = '';
    });
    generateBtn.addEventListener('click', function () {
      const keywords = keywordsInput.value.trim();
      if (!keywords) {
        statusEl.textContent = 'Scrivi almeno qualche parola chiave.';
        return;
      }
      generateBtn.disabled = true;
      statusEl.textContent = 'Generazione in corso...';
      const body = new URLSearchParams();
      body.set('csrf', csrfInput.value);
      body.set('keywords', keywords);
      fetch('/dashboard_ai_caption.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          generateBtn.disabled = false;
          if (data.ok) {
            textarea.value = data.text;
            statusEl.textContent = 'Fatto! Puoi modificare il testo prima di pubblicare.';
          } else {
            statusEl.textContent = data.error || 'Qualcosa è andato storto.';
          }
        })
        .catch(function () {
          generateBtn.disabled = false;
          statusEl.textContent = 'Errore di connessione. Riprova.';
        });
    });
  })();

  document.addEventListener('click', function (e) {
    const toggleBtn = e.target.closest('.ev-edit-ai-toggle');
    const cancelBtn = e.target.closest('.ev-edit-ai-cancel');
    const generateBtn = e.target.closest('.ev-edit-ai-generate');
    if (!toggleBtn && !cancelBtn && !generateBtn) return;

    if (toggleBtn) {
      const panel = toggleBtn.closest('form').querySelector('.ev-edit-ai-panel');
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
      if (panel.style.display === 'block') panel.querySelector('.ev-edit-ai-keywords').focus();
      return;
    }
    if (cancelBtn) {
      const panel = cancelBtn.closest('.ev-edit-ai-panel');
      panel.style.display = 'none';
      panel.querySelector('.ev-edit-ai-status').textContent = '';
      return;
    }
    if (generateBtn) {
      const panel = generateBtn.closest('.ev-edit-ai-panel');
      const form = generateBtn.closest('form');
      const keywords = panel.querySelector('.ev-edit-ai-keywords').value.trim();
      const statusEl = panel.querySelector('.ev-edit-ai-status');
      if (!keywords) {
        statusEl.textContent = 'Scrivi almeno qualche parola chiave.';
        return;
      }
      generateBtn.disabled = true;
      statusEl.textContent = 'Generazione in corso...';
      const body = new URLSearchParams();
      body.set('csrf', form.querySelector('input[name="csrf"]').value);
      body.set('keywords', keywords);
      fetch('/dashboard_ai_caption.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          generateBtn.disabled = false;
          if (data.ok) {
            form.querySelector('.ev-edit-description').value = data.text;
            statusEl.textContent = 'Fatto! Puoi modificare il testo prima di salvare.';
          } else {
            statusEl.textContent = data.error || 'Qualcosa è andato storto.';
          }
        })
        .catch(function () {
          generateBtn.disabled = false;
          statusEl.textContent = 'Errore di connessione. Riprova.';
        });
    }
  });
  </script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
