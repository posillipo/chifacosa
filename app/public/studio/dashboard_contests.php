<?php require_once __DIR__ . '/_studio_boot.php'; /* GENERATO da scripts/make-studio.py: non modificare a mano */ ?>
<?php
// Contest per tutti gli utenti, in sola lettura: elenco dei contest gestiti dagli amministratori, come si partecipa
// e gestione della propria candidatura. La gestione dei contest (creazione, approvazioni, voti) resta in dashboard_contest.php.
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/spotify.php';
require_once __DIR__ . '/../../src/contest.php';
$user = requireLogin();
$profile = getActingProfile($user);
$uid = (int) $profile['id'];
$activeTab = 'contest_list';
$pageTitle = 'Contest';
$pageHelpInline = false;

if (in_array('contest', getSiteDisabledNavKeys(), true)) {
    http_response_code(404);
    exit('Questa funzione non è attiva su questo sito.');
}

$contestId = (int) ($_GET['c'] ?? $_POST['contest_id'] ?? 0);
$c = $contestId ? contestPublicById($contestId) : null;

// Ricerca brani senza ricaricare la pagina (stessa logica della pagina pubblica del contest).
if ($c && isset($_GET['search'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $out = [];
    if (contestCandidaturesOpen($c) && strlen(trim((string) $_GET['search'])) >= 2) {
        foreach (spotifySearchTrack(trim((string) $_GET['search'])) as $t) {
            $out[] = ['id' => $t['id'], 'name' => $t['name'], 'artist' => $t['artist_name'], 'image' => $t['image']];
        }
    }
    echo json_encode(['results' => $out]);
    exit;
}

$message = null;
$error = null;
if ($c && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'apply') {
        $res = contestAddEntry($c, $uid, trim((string) ($_POST['band_name'] ?? '')) ?: $profile['display_name'],
            trim((string) ($_POST['contact_email'] ?? '')) ?: $user['email'], (string) ($_POST['track'] ?? ''), $profile['spotify_artist_id'] ?: null);
        if ($res['ok']) {
            $message = 'Candidatura inviata! Ti abbiamo scritto una email di conferma: il brano resta in attesa di approvazione.';
        } else {
            $error = $res['error'];
        }
    } elseif ($action === 'withdraw') {
        $res = contestWithdrawEntry((int) $c['id'], $uid);
        $res['ok'] ? $message = 'Candidatura ritirata.' : $error = $res['error'];
    } elseif ($action === 'update_contact') {
        $res = contestUpdateEntryContact((int) $c['id'], $uid, (string) ($_POST['band_name'] ?? ''), trim((string) ($_POST['contact_email'] ?? '')));
        $res['ok'] ? $message = 'Dati aggiornati.' : $error = $res['error'];
    }
}

$all = contestListAllPublic();
$myEntries = [];
if ($all) {
    $in = implode(',', array_fill(0, count($all), '?'));
    $st = getDB()->prepare("SELECT contest_id, status FROM contest_entries WHERE user_id = ? AND contest_id IN ($in)");
    $st->execute(array_merge([$uid], array_map(fn($x) => (int) $x['id'], $all)));
    foreach ($st->fetchAll() as $r) {
        $myEntries[(int) $r['contest_id']] = $r['status'];
    }
}
$statusLabel = ['pending' => 'In attesa di approvazione', 'approved' => 'In gara ✔', 'rejected' => 'Non approvata'];
$phaseTone = ['voting' => '#2e7d32', 'open' => '#6c5ce7', 'closed' => '#888'];
if ($c) {
    $pageTitle = $c['name'];
}
include __DIR__ . '/../_dash_header.php';
?>
  <?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$c): ?>
  <?php if ($contestId): ?><div class="alert error">Contest non trovato o non ancora pubblico.</div><?php endif; ?>
  <?php if (!$all): ?>
    <div class="card">Al momento non ci sono contest. Quando l'organizzazione ne pubblicherà uno lo troverai qui, con le regole e il modo di partecipare.</div>
  <?php else: ?>
    <div style="display:grid;gap:12px;">
    <?php foreach ($all as $x): $ph = contestPhaseInfo($x); $mine = $myEntries[(int) $x['id']] ?? null; ?>
      <a href="/dashboard_contests.php?c=<?= (int) $x['id'] ?>" class="card" style="display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit;">
        <?php if ($x['logo_path']): ?>
          <img src="/<?= e(ltrim($x['logo_path'], '/')) ?>" alt="" style="width:64px;height:64px;border-radius:12px;object-fit:cover;flex-shrink:0;">
        <?php else: ?>
          <div style="width:64px;height:64px;border-radius:12px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;"><i class="fa-solid fa-trophy"></i></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <strong style="font-size:16px;"><?= e($x['name']) ?></strong>
          <div style="color:var(--text-muted);font-size:13px;">di <?= e($x['owner_name']) ?><?php $range = contestDateRange($x['voting_start_at'] ?: $x['candidatures_open_at'], $x['voting_end_at'] ?: $x['candidatures_close_at']); echo $range !== '' ? ' · ' . e($range) : ''; ?> · <?= (int) $x['entries_count'] ?> in gara</div>
          <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">
            <span style="background:<?= e($phaseTone[$x['status']] ?? '#888') ?>;color:#fff;border-radius:999px;font-size:11.5px;font-weight:700;padding:2px 10px;"><?= e($ph['label']) ?></span>
            <?php if ($mine): ?><span style="border:1px solid var(--accent);color:var(--accent);border-radius:999px;font-size:11.5px;font-weight:700;padding:2px 10px;">La tua candidatura: <?= e($statusLabel[$mine] ?? $mine) ?></span><?php endif; ?>
          </div>
        </div>
        <i class="fa-solid fa-chevron-right" style="color:var(--text-muted);"></i>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php else:
  $ph = contestPhaseInfo($c);
  $my = contestMyEntry((int) $c['id'], $uid);
  $candOpen = contestCandidaturesOpen($c);
  $fmt = fn($d) => $d ? formatLocalDateTime($d, $profile, 'd/m/Y H:i') : null;
?>
  <p><a href="/dashboard_contests.php"><i class="fa-solid fa-arrow-left"></i> Tutti i contest</a></p>

  <div class="card">
    <div style="display:flex;gap:14px;align-items:center;">
      <?php if ($c['logo_path']): ?><img src="/<?= e(ltrim($c['logo_path'], '/')) ?>" alt="" style="width:72px;height:72px;border-radius:12px;object-fit:cover;"><?php endif; ?>
      <div>
        <h2 style="margin:0;"><?= e($c['name']) ?></h2>
        <div style="color:var(--text-muted);font-size:13px;">organizzato da <?= e($c['owner_name']) ?> · <strong><?= e($ph['label']) ?></strong></div>
      </div>
    </div>
    <?php if (!empty($c['description'])): ?><p style="margin-top:14px;"><?= nl2br(e($c['description'])) ?></p><?php endif; ?>
    <p style="margin-bottom:0;"><a class="btn small secondary" href="<?= e(contestUrl($c)) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Pagina pubblica del contest</a></p>
  </div>

  <div class="section-title">Come si partecipa</div>
  <div class="card">
    <ol style="margin:0;padding-left:20px;line-height:1.7;">
      <li><strong>Candidature</strong>: <?= $c['candidatures_open_at'] || $c['candidatures_close_at'] ? 'dal ' . e($fmt($c['candidatures_open_at']) ?: 'subito') . ' al ' . e($fmt($c['candidatures_close_at']) ?: 'termine non indicato') : 'secondo le indicazioni dell\'organizzazione' ?>. Puoi proporre <strong>un solo brano</strong> per artista.</li>
      <li><strong>Il brano</strong> deve essere su Spotify e uscito da non più di <strong><?= (int) $c['max_track_age_months'] ?> mesi</strong> rispetto all'inizio del contest.</li>
      <li><strong>Approvazione</strong>: la candidatura resta in attesa finché l'organizzazione non la approva; riceverai una email di conferma.</li>
      <li><strong>Votazioni</strong>: <?= $c['voting_start_at'] || $c['voting_end_at'] ? 'dal ' . e($fmt($c['voting_start_at']) ?: 'data da definire') . ' al ' . e($fmt($c['voting_end_at']) ?: 'data da definire') : 'in date che verranno comunicate' ?>. Si vota con un indirizzo Gmail, confermando il voto con il link ricevuto per email.</li>
      <li><strong>Gruppi e classifica</strong>: i brani sono divisi in gruppi (<?= e(implode(', ', array_values(CONTEST_GROUP_LABELS))) ?>) e vincono i più votati.</li>
    </ol>
    <?php if (!empty($c['regulation'])): ?>
      <details style="margin-top:12px;"><summary class="btn small secondary" style="display:inline-block;cursor:pointer;">Leggi il regolamento</summary>
        <div style="margin-top:10px;line-height:1.55;"><?= nl2br(e($c['regulation'])) ?></div>
      </details>
    <?php endif; ?>
    <?php if (!empty($c['contact_email'])): ?><p style="color:var(--text-muted);font-size:13px;margin:12px 0 0;">Domande? Scrivi a <a href="mailto:<?= e($c['contact_email']) ?>"><?= e($c['contact_email']) ?></a>.</p><?php endif; ?>
  </div>

  <div class="section-title">La tua partecipazione</div>
  <?php if ($my): ?>
    <div class="card">
      <div style="display:flex;gap:14px;align-items:center;">
        <?php if ($my['image_url']): ?><img src="<?= e($my['image_url']) ?>" alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover;"><?php endif; ?>
        <div style="flex:1;min-width:0;">
          <strong><?= e($my['track_name']) ?></strong>
          <div style="color:var(--text-muted);font-size:13px;"><?= e($my['band_name']) ?><?= $my['track_url'] ? ' · <a href="' . e($my['track_url']) . '" target="_blank" rel="noopener">Ascolta su Spotify</a>' : '' ?></div>
          <div style="margin-top:6px;font-weight:700;color:<?= $my['status'] === 'approved' ? '#2e7d32' : ($my['status'] === 'rejected' ? '#c0392b' : '#b7791f') ?>;"><?= e($statusLabel[$my['status']] ?? $my['status']) ?></div>
          <?php if ($my['status'] === 'rejected' && !empty($my['admin_note'])): ?><div style="font-size:13px;">Nota dell'organizzazione: <?= e($my['admin_note']) ?></div><?php endif; ?>
          <?php if (in_array($c['status'], ['voting', 'closed'], true) && $my['status'] === 'approved'): ?>
            <div style="font-size:13px;margin-top:4px;"><?= (int) $my['votes_confirmed'] ?> voti confermati<?= !empty($my['group_key']) && isset(CONTEST_GROUP_LABELS[$my['group_key']]) ? ' · gruppo ' . e(CONTEST_GROUP_LABELS[$my['group_key']]) : '' ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($c['status'] !== 'closed'): ?>
        <details style="margin-top:12px;"><summary class="btn small secondary" style="display:inline-block;cursor:pointer;">✏️ Modifica i miei dati</summary>
          <form method="post" style="margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="contest_id" value="<?= (int) $c['id'] ?>">
            <input type="hidden" name="action" value="update_contact">
            <label>Nome dell'artista</label>
            <input type="text" name="band_name" value="<?= e($my['band_name']) ?>" maxlength="150" required>
            <label>Email di contatto</label>
            <input type="email" name="contact_email" value="<?= e($my['contact_email']) ?>" maxlength="190" required>
            <button class="btn small" type="submit" style="margin-top:8px;">Salva</button>
          </form>
        </details>
        <form method="post" style="margin-top:10px;" onsubmit="return confirm('Ritirare la candidatura? Il brano uscirà dal contest.');">
          <?= csrfField() ?>
          <input type="hidden" name="contest_id" value="<?= (int) $c['id'] ?>">
          <input type="hidden" name="action" value="withdraw">
          <button class="btn small danger" type="submit">Ritira la candidatura</button>
        </form>
      <?php endif; ?>
    </div>
  <?php elseif ($candOpen): ?>
    <div class="card">
      <p style="margin-top:0;">Candida il tuo brano: cercalo su Spotify oppure incolla il link.</p>
      <label>Cerca il tuo brano su Spotify</label>
      <input type="text" id="ct-search" placeholder="titolo e artista" autocomplete="off">
      <div id="ct-results" aria-live="polite" style="margin-top:8px;"></div>
      <form method="post" style="margin-top:12px;">
        <?= csrfField() ?>
        <input type="hidden" name="contest_id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="action" value="apply">
        <label>Nome dell'artista</label>
        <input type="text" name="band_name" value="<?= e($profile['display_name']) ?>" maxlength="150" required>
        <label>Email di contatto</label>
        <input type="email" name="contact_email" value="<?= e($user['email']) ?>" maxlength="190" required>
        <label>Link o codice del brano su Spotify</label>
        <input type="text" name="track" id="ct-track" placeholder="https://open.spotify.com/track/..." required>
        <button class="btn" type="submit" style="margin-top:10px;">Candida il brano</button>
      </form>
    </div>
    <script>
    (function () {
      var input = document.getElementById('ct-search'), box = document.getElementById('ct-results'), track = document.getElementById('ct-track'), t = null, n = 0;
      function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
      input.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
          var q = input.value.trim(), my = ++n;
          if (q.length < 2) { box.innerHTML = ''; return; }
          fetch('/dashboard_contests.php?c=<?= (int) $c['id'] ?>&search=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (my !== n) return;
              box.innerHTML = (d.results || []).map(function (x) {
                return '<button type="button" class="ct-pick" data-id="' + esc(x.id) + '" style="display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:6px;margin-bottom:4px;border:1px solid rgba(128,128,128,.3);border-radius:10px;background:transparent;color:inherit;cursor:pointer;">'
                  + (x.image ? '<img src="' + esc(x.image) + '" alt="" style="width:40px;height:40px;border-radius:6px;object-fit:cover;">' : '')
                  + '<span><strong>' + esc(x.name) + '</strong><br><small>' + esc(x.artist) + '</small></span></button>';
              }).join('') || '<small>Nessun risultato.</small>';
            });
        }, 300);
      });
      box.addEventListener('click', function (ev) {
        var b = ev.target.closest('.ct-pick'); if (!b) return;
        track.value = b.getAttribute('data-id'); box.innerHTML = '<small>Brano scelto: premi «Candida il brano».</small>';
      });
    })();
    </script>
  <?php else: ?>
    <div class="card">
      <?php if ($c['status'] === 'open'): ?>
        Le candidature non sono aperte in questo momento<?= $c['candidatures_open_at'] && strtotime($c['candidatures_open_at']) > time() ? ': apriranno il ' . e($fmt($c['candidatures_open_at'])) : '' ?>.
      <?php elseif ($c['status'] === 'voting'): ?>
        Le candidature sono chiuse: il contest è in fase di votazione. Puoi seguirlo dalla pagina pubblica.
      <?php else: ?>
        Il contest è concluso.
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
