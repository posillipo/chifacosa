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
        $date = $_POST['event_date'] ?? '';
        $ticketUrl = trim($_POST['ticket_url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $acceptsReservations = isset($_POST['accepts_reservations']) ? 1 : 0;
        if ($title === '' || $date === '') {
            $error = 'Titolo e data sono obbligatori.';
        } else {
            $coverPath = handleCoverUpload($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO events (user_id, title, venue, city, event_date, ticket_url, description, cover_path, accepts_reservations) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$profile['id'], $title, $venue ?: null, $city ?: null, $date, $ticketUrl ?: null, $description ?: null, $coverPath, $acceptsReservations]);
            $newEventId = (int) getDB()->lastInsertId();

            $eventUrl = siteUrl('/' . $profile['slug'] . '/eventi/' . $newEventId);
            notifyFollowersNewContent((int)$profile['id'], $profile['display_name'], $profile['slug'], 'evento', $title, $eventUrl);
        }
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $venue = trim($_POST['venue'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $date = $_POST['event_date'] ?? '';
        $ticketUrl = trim($_POST['ticket_url'] ?? '');
        $description = trim($_POST['description'] ?? '');
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
                $stmt = getDB()->prepare('UPDATE events SET title=?, venue=?, city=?, event_date=?, ticket_url=?, description=?, cover_path=?, accepts_reservations=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $venue ?: null, $city ?: null, $date, $ticketUrl ?: null, $description ?: null, $newCoverPath, $acceptsReservations, $id, $profile['id']]);
            } else {
                $stmt = getDB()->prepare('UPDATE events SET title=?, venue=?, city=?, event_date=?, ticket_url=?, description=?, accepts_reservations=? WHERE id=? AND user_id=?');
                $stmt->execute([$title, $venue ?: null, $city ?: null, $date, $ticketUrl ?: null, $description ?: null, $acceptsReservations, $id, $profile['id']]);
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

$stmt = getDB()->prepare('SELECT * FROM events WHERE user_id=? ORDER BY event_date ASC');
$stmt->execute([$profile['id']]);
$events = $stmt->fetchAll();

include __DIR__ . '/_dash_header.php';
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <label>Nome evento</label>
    <input type="text" name="title" required>
    <label>Locale</label>
    <input type="text" name="venue">
    <label>Città</label>
    <input type="text" name="city">
    <label>Data e ora</label>
    <input type="datetime-local" name="event_date" required>
    <label>Link biglietti (opzionale)</label>
    <input type="url" name="ticket_url" placeholder="https://...">
    <label>Descrizione (opzionale)</label>
    <textarea name="description" rows="4" placeholder="Racconta l'evento: scaletta, ospiti, informazioni utili..."></textarea>
    <label>Copertina (opzionale, jpg/png/webp)</label>
    <input type="file" name="cover" accept="image/*">
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Comparirà così come l'hai caricata, senza ritagli — qualsiasi proporzione va bene.</p>
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
        <div class="date"><?= date('d/m/Y H:i', strtotime($ev['event_date'])) ?></div>
        <strong><?= e($ev['title']) ?></strong>
        <?php if ($ev['venue'] || $ev['city']): ?>
          <div style="color:var(--text-muted)"><?= e($ev['venue']) ?><?= $ev['venue'] && $ev['city'] ? ', ' : '' ?><?= e($ev['city']) ?></div>
        <?php endif; ?>
        <?php if ((int) $ev['accepts_reservations'] === 1): ?>
          <div style="color:var(--accent);font-size:12.5px;font-weight:700;margin-top:4px;"><i class="fa-solid fa-chair"></i> Prenotazioni attive</div>
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
            <label>Nome evento</label>
            <input type="text" name="title" value="<?= e($ev['title']) ?>" required>
            <label>Locale</label>
            <input type="text" name="venue" value="<?= e($ev['venue'] ?? '') ?>">
            <label>Città</label>
            <input type="text" name="city" value="<?= e($ev['city'] ?? '') ?>">
            <label>Data e ora</label>
            <input type="datetime-local" name="event_date" value="<?= e(date('Y-m-d\TH:i', strtotime($ev['event_date']))) ?>" required>
            <label>Link biglietti (opzionale)</label>
            <input type="url" name="ticket_url" value="<?= e($ev['ticket_url'] ?? '') ?>" placeholder="https://...">
            <label>Descrizione (opzionale)</label>
            <textarea name="description" rows="4" placeholder="Racconta l'evento: scaletta, ospiti, informazioni utili..."><?= e($ev['description'] ?? '') ?></textarea>
            <label>Copertina (opzionale — lascia vuoto per non cambiarla)</label>
            <input type="file" name="cover" accept="image/*">
            <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">
              <?= $ev['cover_path'] ? 'Hai già caricato una copertina — seleziona un nuovo file per sostituirla.' : 'Comparirà così come l\'hai caricata, senza ritagli — qualsiasi proporzione va bene.' ?>
            </p>
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
<?php include __DIR__ . '/_dash_footer.php'; ?>
