<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
require_once __DIR__ . '/../src/contest.php';

$c = contestPublicCurrent();
$user = currentUser();
$myProfile = null;
if ($user) {
    $stmt = getDB()->prepare('SELECT p.display_name, p.spotify_artist_id, u.email, u.id FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
    $stmt->execute([(int) $user['id']]);
    $myProfile = $stmt->fetch() ?: null;
}
// Ricerca brani senza ricaricare la pagina: risposta JSON per la candidatura.
if ($c && isset($_GET['search'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $out = [];
    if ($myProfile && contestCandidaturesOpen($c) && strlen(trim((string) $_GET['search'])) >= 2) {
        foreach (spotifySearchTrack(trim((string) $_GET['search'])) as $t) {
            $out[] = ['id' => $t['id'], 'name' => $t['name'], 'artist' => $t['artist_name'], 'image' => $t['image']];
        }
    }
    echo json_encode(['results' => $out]);
    exit;
}
$message = null;
$error = null;
$myEntry = null;
if ($c && $myProfile) {
    $stmt = getDB()->prepare('SELECT * FROM contest_entries WHERE contest_id = ? AND user_id = ?');
    $stmt->execute([(int) $c['id'], (int) $myProfile['id']]);
    $myEntry = $stmt->fetch() ?: null;
}

$voteMessage = null;
$voteError = null;
if ($c && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote') {
    checkCsrf();
    $r = contestRequestVote($c, (int) ($_POST['entry_id'] ?? 0), (string) ($_POST['email'] ?? ''));
    if ($r['ok']) {
        $voteMessage = 'Ti abbiamo inviato una email con il link per confermare il voto: il voto conta solo dopo la conferma.';
    } else {
        $voteError = $r['error'];
    }
} elseif ($c && $myProfile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $res = contestAddEntry($c, (int) $myProfile['id'], $myProfile['display_name'], $myProfile['email'], (string) ($_POST['track'] ?? ''), $myProfile['spotify_artist_id'] ?: null);
    if ($res['ok']) {
        $message = 'Candidatura inviata! Ti abbiamo scritto una email di conferma: il brano resta in attesa di approvazione.';
        $stmt = getDB()->prepare('SELECT * FROM contest_entries WHERE contest_id = ? AND user_id = ?');
        $stmt->execute([(int) $c['id'], (int) $myProfile['id']]);
        $myEntry = $stmt->fetch() ?: null;
    } else {
        $error = $res['error'];
    }
}

$entries = [];
if ($c) {
    $stmt = getDB()->prepare("SELECT e.id, e.band_name, e.track_name, e.image_url, e.track_url, s.name AS sponsor_name, s.logo_path AS sponsor_logo, s.website_url AS sponsor_url
        FROM contest_entries e LEFT JOIN contest_sponsors s ON s.id = e.sponsor_id
        WHERE e.contest_id = ? AND e.status = 'approved' ORDER BY e.approved_at ASC, e.id ASC");
    $stmt->execute([(int) $c['id']]);
    $entries = $stmt->fetchAll();
}
$sponsors = $c ? array_values(array_filter(contestSponsors((int) $c['id']), fn ($sp) => !empty($sp['logo_path']) || !empty($sp['name']))) : [];
$ranking = ($c && in_array($c['status'], ['voting', 'closed'], true)) ? contestRanking($c) : [];
$votingOpen = $c ? contestVotingOpen($c) : false;
$q = trim($_GET['q'] ?? '');
$results = ($c && $myProfile && !$myEntry && $q !== '' && contestCandidaturesOpen($c)) ? spotifySearchTrack($q) : [];
$contact = getSiteSetting('contest_contact_email') ?: '';
$fmt = fn ($d) => $d ? date('d/m/Y H:i', strtotime($d)) : null;
?>
<?php
$sponsorBadge = function (array $en): string {
    if (empty($en['sponsor_name'])) {
        return '';
    }
    $logo = !empty($en['sponsor_logo']) ? '<img src="/' . e($en['sponsor_logo']) . '" alt="' . e($en['sponsor_name']) . '" style="height:22px;max-width:90px;object-fit:contain;vertical-align:middle;">' : e($en['sponsor_name']);
    return '<div style="margin-top:4px;font-size:12.5px;color:var(--text-muted);">Sostenuto da ' . $logo . '</div>';
};
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($c['name'] ?? 'Contest') ?> — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="container" style="max-width:760px;">
<?php if (!$c): ?>
  <h1>Contest</h1>
  <p style="color:var(--text-muted)">Nessun contest in programma al momento.</p>
<?php else: ?>
  <h1><?= e($c['name']) ?></h1>
  <?php if ($c['description']): ?><p style="color:var(--text-muted)"><?= nl2br(e($c['description'])) ?></p><?php endif; ?>

  <div class="card">
    <strong>Date</strong>
    <ul style="margin:8px 0 0 18px;color:var(--text-muted)">
      <?php if ($c['candidatures_open_at'] || $c['candidatures_close_at']): ?>
        <li>Candidature: <?= e($fmt($c['candidatures_open_at']) ?: '—') ?> → <?= e($fmt($c['candidatures_close_at']) ?: '—') ?></li>
      <?php endif; ?>
      <?php if ($c['voting_start_at'] || $c['voting_end_at']): ?>
        <li>Votazioni: <?= e($fmt($c['voting_start_at']) ?: '—') ?> → <?= e($fmt($c['voting_end_at']) ?: '—') ?></li>
      <?php endif; ?>
    </ul>
  </div>

  <?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <?php if (contestCandidaturesOpen($c)): ?>
    <div class="card">
      <strong>Partecipa al contest</strong>
      <p style="color:var(--text-muted)">Un solo brano per artista. Il brano deve essere pubblicato su Spotify, uscito da non più di <?= (int) $c['max_track_age_months'] ?> mesi rispetto all'inizio del festival. La candidatura resta in attesa finché non viene approvata.</p>
      <?php if (!$myProfile): ?>
        <p><a class="btn" href="/login.php">Accedi</a> con il tuo profilo per candidare un brano<?= $contact ? ', oppure scrivi a <a href="mailto:' . e($contact) . '">' . e($contact) . '</a> se non hai un profilo' : '' ?>.</p>
      <?php elseif ($myEntry): ?>
        <p>Hai candidato <strong><?= e($myEntry['track_name']) ?></strong> — stato: <strong><?= $myEntry['status'] === 'approved' ? 'in gara ✔' : ($myEntry['status'] === 'rejected' ? 'non approvata' : 'in attesa di approvazione') ?></strong>.</p>
      <?php else: ?>
        <form method="get" id="track-search">
          <label>Cerca il tuo brano su Spotify</label>
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="titolo e artista" autocomplete="off">
          <button class="btn small" type="submit">Cerca</button>
        </form>
        <div id="csrf-holder" hidden><?= csrfField() ?></div>
        <div id="track-results" aria-live="polite">
        <?php foreach ($results as $t): ?>
          <form method="post" style="display:flex;gap:10px;align-items:center;margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="track" value="<?= e($t['id']) ?>">
            <?php if ($t['image']): ?><img src="<?= e($t['image']) ?>" style="width:48px;height:48px;border-radius:6px;" alt=""><?php endif; ?>
            <span style="flex:1;"><strong><?= e($t['name']) ?></strong><br><small style="color:var(--text-muted)"><?= e($t['artist_name']) ?></small></span>
            <button class="btn small" type="submit">Candida questo brano</button>
          </form>
        <?php endforeach; ?>
        </div>
        <form method="post" style="margin-top:14px;">
          <?= csrfField() ?>
          <label>Oppure incolla il link del brano</label>
          <input type="text" name="track" placeholder="https://open.spotify.com/track/...">
          <button class="btn small secondary" type="submit">Candida</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($voteMessage): ?><div class="alert success"><?= e($voteMessage) ?></div><?php endif; ?>
  <?php if ($voteError): ?><div class="alert error"><?= e($voteError) ?></div><?php endif; ?>

  <?php if ($ranking): ?>
    <?php if ($votingOpen): ?>
      <p style="color:var(--text-muted)">Per votare serve un indirizzo Gmail: riceverai una email con il link per confermare il voto. Un voto per persona in ogni classifica.</p>
    <?php endif; ?>
    <?php foreach ($ranking as $gkey => $g): $top = $g['rows'][0]['votes'] ?? 0; ?>
      <div class="section-title"><?= e($g['label']) ?></div>
      <?php foreach ($g['rows'] as $i => $en): ?>
        <div class="blog-item" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
          <strong style="width:28px;text-align:center;"><?= $i + 1 ?></strong>
          <?php if ($en['image_url']): ?><img src="<?= e($en['image_url']) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;" alt=""><?php endif; ?>
          <div style="flex:1;min-width:180px;">
            <strong><a href="/contest/brano/<?= (int) $en['id'] ?>" style="color:inherit;"><?= e($en['track_name']) ?></a></strong>
            <?php if ($c['status'] === 'closed' && $top > 0 && (int) $en['votes'] === (int) $top): ?> 🏆 <em>Vincitore</em><?php endif; ?>
            <br><span style="color:var(--text-muted)"><?= e($en['band_name']) ?></span>
            <?php if ($en['track_url']): ?> · <a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Ascolta su Spotify</a><?php endif; ?>
            <?= $sponsorBadge($en) ?>
          </div>
          <div style="font-weight:700;"><?= (int) $en['votes'] ?> voti</div>
          <?php if ($votingOpen): ?>
            <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="vote">
              <input type="hidden" name="entry_id" value="<?= (int) $en['id'] ?>">
              <input type="email" name="email" placeholder="tuo.indirizzo@gmail.com" required style="min-width:190px;">
              <button class="btn small" type="submit">Vota</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php else: ?>
  <div class="section-title">Candidati in gara (<?= count($entries) ?>)</div>
  <?php foreach ($entries as $en): ?>
    <div class="blog-item" style="display:flex;gap:14px;align-items:center;">
      <?php if ($en['image_url']): ?><img src="<?= e($en['image_url']) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;" alt=""><?php endif; ?>
      <div><strong><a href="/contest/brano/<?= (int) $en['id'] ?>" style="color:inherit;"><?= e($en['track_name']) ?></a></strong><br><span style="color:var(--text-muted)"><?= e($en['band_name']) ?></span>
        <?php if ($en['track_url']): ?> · <a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Ascolta su Spotify</a><?php endif; ?>
        <?= $sponsorBadge($en) ?></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$entries): ?><p style="color:var(--text-muted)">Nessun brano ancora in gara.</p><?php endif; ?>
  <?php endif; ?>

  <?php if ($sponsors): ?>
    <div class="section-title" style="margin-top:24px;">Sponsor</div>
    <div class="card" style="display:flex;gap:22px;flex-wrap:wrap;align-items:center;">
      <?php foreach ($sponsors as $sp): $inner = !empty($sp['logo_path']) ? '<img src="/' . e($sp['logo_path']) . '" alt="' . e($sp['name']) . '" style="height:48px;max-width:160px;object-fit:contain;">' : '<strong>' . e($sp['name']) . '</strong>'; ?>
        <?php if (!empty($sp['website_url'])): ?><a href="<?= e($sp['website_url']) ?>" target="_blank" rel="noopener sponsored" title="<?= e($sp['name']) ?>"><?= $inner ?></a><?php else: ?><span title="<?= e($sp['name']) ?>"><?= $inner ?></span><?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($c['regulation']): ?>
    <div class="card" style="margin-top:24px;"><strong>Regolamento</strong><div style="margin-top:8px;"><?= nl2br(e($c['regulation'])) ?></div></div>
  <?php endif; ?>
<?php endif; ?>
  <p><a href="/">← Torna alla home</a></p>
</div>
<script>
// Ricerca del brano senza ricaricare la pagina (senza JavaScript resta il normale invio del modulo).
(function () {
  var form = document.getElementById('track-search');
  if (!form || !window.fetch) { return; }
  var box = document.getElementById('track-results');
  var holder = document.getElementById('csrf-holder');
  var input = form.querySelector('input[name="q"]');
  var button = form.querySelector('button');
  var seq = 0;
  function note(text) {
    box.textContent = '';
    var p = document.createElement('p');
    p.style.color = 'var(--text-muted)';
    p.textContent = text;
    box.appendChild(p);
  }
  function render(items) {
    box.textContent = '';
    if (!items.length) { note('Nessun brano trovato.'); return; }
    items.forEach(function (t) {
      var f = document.createElement('form');
      f.method = 'post';
      f.style.cssText = 'display:flex;gap:10px;align-items:center;margin-top:10px;';
      f.innerHTML = holder.innerHTML;
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'track'; h.value = t.id;
      f.appendChild(h);
      if (t.image) {
        var img = document.createElement('img');
        img.src = t.image; img.alt = '';
        img.style.cssText = 'width:48px;height:48px;border-radius:6px;';
        f.appendChild(img);
      }
      var span = document.createElement('span');
      span.style.flex = '1';
      var strong = document.createElement('strong');
      strong.textContent = t.name;
      var small = document.createElement('small');
      small.style.color = 'var(--text-muted)';
      small.textContent = t.artist;
      span.appendChild(strong);
      span.appendChild(document.createElement('br'));
      span.appendChild(small);
      f.appendChild(span);
      var b = document.createElement('button');
      b.className = 'btn small'; b.type = 'submit'; b.textContent = 'Candida questo brano';
      f.appendChild(b);
      box.appendChild(f);
    });
  }
  form.addEventListener('submit', function (ev) {
    var q = input.value.trim();
    if (q.length < 2) { return; }
    ev.preventDefault();
    var mine = ++seq;
    note('Ricerca in corso…');
    button.disabled = true;
    fetch('/contest?search=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (mine === seq) { render(d.results || []); } })
      .catch(function () { if (mine === seq) { note('Ricerca non riuscita: riprova.'); } })
      .then(function () { button.disabled = false; });
  });
})();
</script>
</body>
</html>
