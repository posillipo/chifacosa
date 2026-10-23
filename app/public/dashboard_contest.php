<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
require_once __DIR__ . '/../src/contest.php';
$user = requireAdmin();
$profile = getActingProfile($user);
$activeTab = 'contest';
$pageTitle = 'Contest';
$error = null;
$success = null;
$db = getDB();

$contestId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $contestId = (int) ($_POST['contest_id'] ?? $contestId);

    if ($action === 'save_contest') {
        $name = trim($_POST['name'] ?? '');
        $off = browserTzOffsetFromRequest();
        $dt = fn ($k) => parseLocalDateTime($_POST[$k] ?? '', $profile, $off);
        $status = in_array($_POST['status'] ?? '', ['draft', 'open', 'voting', 'closed'], true) ? $_POST['status'] : 'draft';
        $age = max(1, min(60, (int) ($_POST['max_track_age_months'] ?? 12)));
        $thr = max(0, min(100, (int) ($_POST['big_threshold'] ?? 40)));
        $bigMin = max(1, (int) ($_POST['big_min_count'] ?? 8));
        $newMin = max(1, (int) ($_POST['new_min_count'] ?? 4));
        if ($name === '') {
            $error = 'Il nome del contest è obbligatorio.';
        } else {
            $vals = [mb_substr($name, 0, 150), trim($_POST['description'] ?? '') ?: null, trim($_POST['regulation'] ?? '') ?: null,
                $dt('candidatures_open_at'), $dt('candidatures_close_at'), $dt('voting_start_at'), $dt('voting_end_at'),
                $age, $thr, $bigMin, $newMin, $status];
            if ($contestId && contestGet($contestId)) {
                $db->prepare('UPDATE contests SET name=?, description=?, regulation=?, candidatures_open_at=?, candidatures_close_at=?, voting_start_at=?, voting_end_at=?, max_track_age_months=?, big_threshold=?, big_min_count=?, new_min_count=?, status=? WHERE id=?')
                    ->execute(array_merge($vals, [$contestId]));
            } else {
                $db->prepare('INSERT INTO contests (name, description, regulation, candidatures_open_at, candidatures_close_at, voting_start_at, voting_end_at, max_track_age_months, big_threshold, big_min_count, new_min_count, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($vals);
                $contestId = (int) $db->lastInsertId();
            }
            setSiteSetting('contest_contact_email', trim($_POST['contact_email'] ?? ''));
            $success = 'Contest salvato.';
        }
    } elseif ($action === 'set_entry') {
        contestSetEntryStatus((int) ($_POST['entry_id'] ?? 0), (string) ($_POST['status'] ?? ''), trim($_POST['note'] ?? ''));
        $success = 'Candidatura aggiornata.';
    } elseif ($action === 'add_entry' && $contestId && ($c = contestGet($contestId))) {
        $res = contestAddEntry($c, null, trim($_POST['band_name'] ?? ''), trim($_POST['email'] ?? ''), (string) ($_POST['track'] ?? ''), null, true);
        if ($res['ok']) {
            contestSetEntryStatus($res['entry_id'], !empty($_POST['approve_now']) ? 'approved' : 'pending');
            $success = 'Candidatura aggiunta.';
        } else {
            $error = $res['error'];
        }
    } elseif ($action === 'delete_contest' && $contestId) {
        $db->prepare('DELETE FROM contests WHERE id = ?')->execute([$contestId]);
        header('Location: /dashboard_contest.php');
        exit;
    }
}

$contests = $db->query('SELECT id, name, status FROM contests ORDER BY id DESC')->fetchAll();
if (!$contestId && $contests && !isset($_GET['new'])) {
    $contestId = (int) $contests[0]['id'];
}
$c = $contestId ? contestGet($contestId) : null;
$entries = [];
if ($c) {
    $stmt = $db->prepare('SELECT * FROM contest_entries WHERE contest_id = ? ORDER BY FIELD(status, "pending", "approved", "rejected"), created_at DESC');
    $stmt->execute([$contestId]);
    $entries = $stmt->fetchAll();
}
$statusLabels = ['draft' => 'Bozza', 'open' => 'Candidature aperte', 'voting' => 'Votazioni', 'closed' => 'Chiuso'];
$v = fn ($k, $d = '') => e((string) ($c[$k] ?? $d));
$dtv = fn ($k) => e(localDateTimeInputValue($c[$k] ?? null, $profile));

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Qui configuri il contest (nome, date, regolamento) e gestisci le candidature. Un artista candida
      un solo brano dalla pagina pubblica <a href="/contest">/contest</a>; il brano deve essere su Spotify e
      uscito entro il numero di mesi indicato prima dell'inizio delle votazioni. Una candidatura resta
      «in attesa» finché non la approvi: all'approvazione l'artista riceve la conferma e il brano entra
      nella lista ufficiale. Puoi anche inserire a mano la candidatura di chi non ha un profilo.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <?php foreach ($contests as $ct): ?>
      <a class="btn small <?= (int) $ct['id'] === $contestId ? '' : 'secondary' ?>" href="?id=<?= (int) $ct['id'] ?>"><?= e($ct['name']) ?> · <?= e($statusLabels[$ct['status']]) ?></a>
    <?php endforeach; ?>
    <a class="btn small secondary" href="?new=1">+ Nuovo contest</a>
  </p>

  <div class="section-title"><?= $c ? 'Configurazione' : 'Nuovo contest' ?></div>
  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_contest">
    <input type="hidden" name="contest_id" value="<?= (int) $contestId ?>">
    <input type="hidden" name="tz_offset_minutes" value="">
    <label>Nome</label>
    <input type="text" name="name" value="<?= $v('name') ?>" placeholder="es. MyBand Festival 2027" required maxlength="150">
    <label>Stato</label>
    <select name="status">
      <?php foreach ($statusLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= ($c['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Bozza = invisibile al pubblico. Le candidature si ricevono solo con stato «Candidature aperte» e dentro le date sotto.</p>
    <label>Descrizione breve</label>
    <textarea name="description" rows="3"><?= $v('description') ?></textarea>
    <label>Regolamento</label>
    <textarea name="regulation" rows="10"><?= $v('regulation') ?></textarea>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 14px;">
      <div><label>Apertura candidature</label><input type="datetime-local" name="candidatures_open_at" value="<?= $dtv('candidatures_open_at') ?>"></div>
      <div><label>Chiusura candidature</label><input type="datetime-local" name="candidatures_close_at" value="<?= $dtv('candidatures_close_at') ?>"></div>
      <div><label>Inizio votazioni</label><input type="datetime-local" name="voting_start_at" value="<?= $dtv('voting_start_at') ?>"></div>
      <div><label>Fine votazioni</label><input type="datetime-local" name="voting_end_at" value="<?= $dtv('voting_end_at') ?>"></div>
    </div>
    <label>Il brano deve essere uscito da non più di (mesi, rispetto all'inizio votazioni)</label>
    <input type="number" name="max_track_age_months" min="1" max="60" value="<?= $v('max_track_age_months', '12') ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 14px;">
      <div><label>Soglia popolarità Spotify «Big» (0-100)</label><input type="number" name="big_threshold" min="0" max="100" value="<?= $v('big_threshold', '40') ?>"></div>
      <div><label>Minimo di band sopra soglia per dividere</label><input type="number" name="big_min_count" min="1" value="<?= $v('big_min_count', '8') ?>"></div>
      <div><label>Minimo di Nuove Proposte</label><input type="number" name="new_min_count" min="1" value="<?= $v('new_min_count', '4') ?>"></div>
    </div>
    <p style="color:var(--text-muted);font-size:12.5px;margin-top:-8px;">Soglia e minimi servono nelle fasi successive (gruppi Big / Nuove Proposte): per ora vengono solo salvati.</p>
    <label>Email di contatto per chi non ha un profilo (mostrata nella pagina pubblica)</label>
    <input type="email" name="contact_email" value="<?= e(getSiteSetting('contest_contact_email') ?: '') ?>">
    <button type="submit" class="btn">Salva</button>
  </form>

  <?php if ($c): ?>
    <div class="section-title">Candidature (<?= count($entries) ?>)</div>
    <?php foreach ($entries as $en): ?>
      <div class="card" style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;">
        <?php if ($en['image_url']): ?><img src="<?= e($en['image_url']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;" alt=""><?php endif; ?>
        <div style="flex:1;min-width:220px;">
          <strong><?= e($en['band_name']) ?></strong> — <?= e($en['track_name']) ?>
          <span class="badge"><?= $en['status'] === 'approved' ? 'Approvata' : ($en['status'] === 'rejected' ? 'Rifiutata' : 'In attesa') ?></span>
          <div style="color:var(--text-muted);font-size:12.5px;">
            <?= e($en['contact_email']) ?> · uscito il <?= $en['release_date'] ? e(date('d/m/Y', strtotime($en['release_date']))) : '—' ?>
            · popolarità artista: <?= $en['artist_popularity'] !== null ? (int) $en['artist_popularity'] : '—' ?>
            · <?= $en['user_id'] ? ($en['artist_match'] ? 'artista Spotify del profilo coincide ✔' : '⚠ artista Spotify del profilo NON coincide') : 'senza profilo' ?>
            <?php if ($en['track_url']): ?> · <a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Spotify</a><?php endif; ?>
          </div>
          <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_entry">
            <input type="hidden" name="contest_id" value="<?= (int) $contestId ?>">
            <input type="hidden" name="entry_id" value="<?= (int) $en['id'] ?>">
            <input type="text" name="note" value="<?= e($en['admin_note'] ?? '') ?>" placeholder="Nota (opzionale)" style="flex:1;min-width:140px;" maxlength="300">
            <?php if ($en['status'] !== 'approved'): ?><button class="btn small" name="status" value="approved">Approva</button><?php endif; ?>
            <?php if ($en['status'] !== 'rejected'): ?><button class="btn small secondary" name="status" value="rejected">Rifiuta</button><?php endif; ?>
            <?php if ($en['status'] !== 'pending'): ?><button class="btn small secondary" name="status" value="pending">Rimetti in attesa</button><?php endif; ?>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$entries): ?><p style="color:var(--text-muted)">Nessuna candidatura per ora.</p><?php endif; ?>

    <div class="section-title">Aggiungi una candidatura a mano</div>
    <form method="post" class="card">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_entry">
      <input type="hidden" name="contest_id" value="<?= (int) $contestId ?>">
      <label>Nome band/artista</label><input type="text" name="band_name" required maxlength="150">
      <label>Email</label><input type="email" name="email" required>
      <label>Link (o ID) del brano su Spotify</label><input type="text" name="track" placeholder="https://open.spotify.com/track/..." required>
      <label style="display:flex;gap:6px;align-items:center;font-weight:normal;"><input type="checkbox" name="approve_now" value="1" style="width:auto;"> Approva subito</label>
      <button type="submit" class="btn">Aggiungi</button>
    </form>

    <form method="post" onsubmit="return confirm('Eliminare il contest e tutte le sue candidature?');" style="margin-top:18px;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete_contest">
      <input type="hidden" name="contest_id" value="<?= (int) $contestId ?>">
      <button class="btn small secondary" type="submit">Elimina questo contest</button>
    </form>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
