<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
require_once __DIR__ . '/../src/contest.php';
require_once __DIR__ . '/../src/contest_adminlte.php';

$user = currentUser();
$artist = contestLoadArtist((string) ($_GET['slug'] ?? ''));
if (!$artist || !contestModuleEnabled((int) $artist['id'])) {
    http_response_code(404);
    exit('Pagina non trovata.');
}
$c = contestGetBySlug((int) $artist['id'], (string) ($_GET['edition'] ?? ''));
$isDraftPreview = false;
if ($c && $c['status'] === 'draft') {
    // Le bozze le vedono solo l'amministratore e chi gestisce il profilo, come anteprima.
    if (contestCanSeeDrafts($user, (int) $artist['id'])) {
        $isDraftPreview = true;
    } else {
        $c = null;
    }
}
if (!$c) {
    http_response_code(404);
    contestPageOpen($artist, 'Contest non trovato', '', true);
    ?>
  <header class="hero"><div class="logo">🏆</div><h1>Contest non trovato</h1><p>Questo contest non esiste o non è ancora pubblico.</p></header>
  <a class="btn-link primary" href="/<?= e($artist['slug']) ?>/contest"><span class="ico">🏆</span><span>Vedi i contest di <?= e($artist['display_name']) ?></span><span class="arrow">›</span></a>
<?php
    contestPageClose($artist);
    exit;
}
$base = contestUrl($c);
$listHref = '/' . $artist['slug'] . '/contest';

$myProfile = null;
if ($user) {
    $stmt = getDB()->prepare('SELECT p.display_name, p.spotify_artist_id, u.email, u.id FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
    $stmt->execute([(int) $user['id']]);
    $myProfile = $stmt->fetch() ?: null;
}

// Ricerca brani senza ricaricare la pagina: risposta JSON per la candidatura.
if (isset($_GET['search'])) {
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
$voteMessage = null;
$voteError = null;
$myEntry = null;
$loadMyEntry = function () use ($c, $myProfile) {
    if (!$myProfile) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM contest_entries WHERE contest_id = ? AND user_id = ?');
    $stmt->execute([(int) $c['id'], (int) $myProfile['id']]);
    return $stmt->fetch() ?: null;
};
$myEntry = $loadMyEntry();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote') {
    checkCsrf();
    $r = contestRequestVote($c, (int) ($_POST['entry_id'] ?? 0), (string) ($_POST['email'] ?? ''));
    if ($r['ok']) {
        $voteMessage = 'Ti abbiamo inviato una email con il link per confermare il voto: il voto conta solo dopo la conferma.';
    } else {
        $voteError = $r['error'];
    }
} elseif ($myProfile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $res = contestAddEntry($c, (int) $myProfile['id'], $myProfile['display_name'], $myProfile['email'], (string) ($_POST['track'] ?? ''), $myProfile['spotify_artist_id'] ?: null);
    if ($res['ok']) {
        $message = 'Candidatura inviata! Ti abbiamo scritto una email di conferma: il brano resta in attesa di approvazione.';
        $myEntry = $loadMyEntry();
    } else {
        $error = $res['error'];
    }
}

$stmt = getDB()->prepare("SELECT e.id, e.band_name, e.track_name, e.image_url, e.track_url, s.name AS sponsor_name, s.logo_path AS sponsor_logo, s.website_url AS sponsor_url
    FROM contest_entries e LEFT JOIN contest_sponsors s ON s.id = e.sponsor_id
    WHERE e.contest_id = ? AND e.status = 'approved' ORDER BY e.approved_at ASC, e.id ASC");
$stmt->execute([(int) $c['id']]);
$entries = $stmt->fetchAll();
$sponsors = contestSponsors((int) $c['id']);
$ranking = in_array($c['status'], ['voting', 'closed'], true) ? contestRanking($c) : [];
$votingOpen = contestVotingOpen($c);
$candOpen = contestCandidaturesOpen($c);
$ph = contestPhaseInfo($c);
$range = contestDateRange($c['voting_start_at'] ?: $c['candidatures_open_at'], $c['voting_end_at'] ?: $c['candidatures_close_at']);
$q = trim($_GET['q'] ?? '');
$results = ($myProfile && !$myEntry && $q !== '' && $candOpen) ? spotifySearchTrack($q) : [];
$contact = (string) ($c['contact_email'] ?? '');

$spons = function (array $en): string {
    if (empty($en['sponsor_name'])) {
        return '';
    }
    $logo = !empty($en['sponsor_logo']) ? '<img src="/' . e($en['sponsor_logo']) . '" alt="' . e($en['sponsor_name']) . '">' : '<b>' . e($en['sponsor_name']) . '</b>';
    return '<div class="spons">Sostenuto da ' . $logo . '</div>';
};
$cover = function (array $en): string {
    return '<div class="cover">' . (!empty($en['image_url']) ? '<img src="' . e($en['image_url']) . '" alt="">' : '♪') . '</div>';
};

$og = '<meta property="og:title" content="' . e($c['name']) . '"><meta property="og:url" content="' . e(siteUrl($base)) . '">'
    . ($c['description'] ? '<meta property="og:description" content="' . e(textExcerpt($c['description'], 160)) . '">' : '')
    . (!empty($c['logo_path']) ? '<meta property="og:image" content="' . e(siteUrl('/' . $c['logo_path'])) . '">' : '');
if (($artist['page_theme'] ?? 'colorful') === 'adminlte-profile') {
    echo renderAdminLteContestPage($artist, $c, [
        'message' => $message, 'error' => $error, 'voteMessage' => $voteMessage, 'voteError' => $voteError,
        'myProfile' => $myProfile, 'myEntry' => $myEntry, 'entries' => $entries, 'sponsors' => $sponsors,
        'ranking' => $ranking, 'votingOpen' => $votingOpen, 'candOpen' => $candOpen, 'ph' => $ph, 'range' => $range,
        'q' => $q, 'results' => $results, 'contact' => $contact, 'isDraftPreview' => $isDraftPreview,
        'listHref' => $listHref, 'og' => $og,
    ]);
    exit;
}
contestPageOpen($artist, $c['name'], $og, $isDraftPreview);
?>
  <a class="back" href="<?= e($listHref) ?>">← Tutti i contest</a>
  <?php if ($isDraftPreview): ?><div class="msg info">Bozza: questa pagina la vedi solo tu, come amministratore.</div><?php endif; ?>

  <header class="hero">
    <div class="logo"><?= contestLogoHtml($c) ?></div>
    <h1><?= e($c['name']) ?></h1>
    <?php if ($c['description']): ?><p><?= nl2br(e($c['description'])) ?></p><?php endif; ?>
    <div class="chips">
      <span class="chip <?= in_array($c['status'], ['open', 'voting'], true) ? 'live' : '' ?>"><?= in_array($c['status'], ['open', 'voting'], true) ? '● ' : '' ?><?= e($ph['label']) ?></span>
      <?php if ($range): ?><span class="chip"><?= e($range) ?></span><?php endif; ?>
      <span class="chip"><?= count($entries) ?> brani in gara</span>
    </div>
  </header>

  <div class="steps">
    <div class="step <?= $ph['step'] > 0 ? 'done' : ($ph['step'] === 0 ? 'now' : '') ?>"><i></i>Candidature</div>
    <div class="step <?= $ph['step'] > 1 ? 'done' : ($ph['step'] === 1 ? 'now' : '') ?>"><i></i>Votazioni</div>
    <div class="step <?= $ph['step'] === 2 ? 'now' : '' ?>"><i></i>Vincitori</div>
  </div>
  <?php if ($ph['target'] && $ph['target'] > time()): ?>
    <div class="countdown"><?= e($ph['countdown']) ?> <b id="cd" data-target="<?= (int) $ph['target'] ?>">…</b></div>
  <?php else: ?><div class="countdown"></div><?php endif; ?>

  <?php if ($votingOpen && $ranking): ?><a class="btn-link primary" href="#classifiche"><span class="ico">🗳️</span><span>Vota ora<small>Servono un Gmail e un clic di conferma</small></span><span class="arrow">›</span></a><?php endif; ?>
  <?php if ($candOpen): ?><a class="btn-link primary" href="#candidati"><span class="ico">🎤</span><span>Candida il tuo brano<small>Un solo brano per artista</small></span><span class="arrow">›</span></a><?php endif; ?>
  <?php if ($ranking): ?><a class="btn-link" href="#classifiche"><span class="ico">🏆</span><span>Classifiche<small>Aggiornate in tempo reale</small></span><span class="arrow">›</span></a>
  <?php elseif ($entries): ?><a class="btn-link" href="#brani"><span class="ico">🎵</span><span>Brani in gara</span><span class="arrow">›</span></a><?php endif; ?>
  <?php if ($c['regulation']): ?><a class="btn-link" href="#reg"><span class="ico">📜</span><span>Regolamento</span><span class="arrow">›</span></a><?php endif; ?>

  <?php if ($message): ?><div class="msg ok"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($voteMessage): ?><div class="msg ok"><?= e($voteMessage) ?></div><?php endif; ?>
  <?php if ($voteError): ?><div class="msg err"><?= e($voteError) ?></div><?php endif; ?>

  <?php if ($candOpen): ?>
    <div class="section" id="candidati">Candidatura</div>
    <div class="box">
      <p>Il brano deve essere pubblicato su Spotify, uscito da non più di <?= (int) $c['max_track_age_months'] ?> mesi rispetto all'inizio del festival. La candidatura resta in attesa finché non viene approvata.</p>
      <?php if (!$myProfile): ?>
        <a class="btn" href="/login.php">Accedi per candidarti</a>
        <?php if ($contact): ?><p style="margin-top:10px;">Non hai un profilo? Scrivi a <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p><?php endif; ?>
      <?php elseif ($myEntry): ?>
        <p>Hai candidato <strong><?= e($myEntry['track_name']) ?></strong> — stato: <strong><?= $myEntry['status'] === 'approved' ? 'in gara ✔' : ($myEntry['status'] === 'rejected' ? 'non approvata' : 'in attesa di approvazione') ?></strong>.</p>
      <?php else: ?>
        <form method="get" id="track-search">
          <label>Cerca il tuo brano su Spotify</label>
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="titolo e artista" autocomplete="off">
          <button class="btn" type="submit" style="margin-top:8px;">Cerca</button>
        </form>
        <div id="csrf-holder" hidden><?= csrfField() ?></div>
        <div id="track-results" aria-live="polite">
        <?php foreach ($results as $t): ?>
          <form method="post" class="result">
            <?= csrfField() ?>
            <input type="hidden" name="track" value="<?= e($t['id']) ?>">
            <?php if ($t['image']): ?><img src="<?= e($t['image']) ?>" alt=""><?php endif; ?>
            <span><strong><?= e($t['name']) ?></strong><br><small><?= e($t['artist_name']) ?></small></span>
            <button class="btn" type="submit">Candida</button>
          </form>
        <?php endforeach; ?>
        </div>
        <form method="post" style="margin-top:14px;">
          <?= csrfField() ?>
          <label>Oppure incolla il link del brano</label>
          <input type="text" name="track" placeholder="https://open.spotify.com/track/...">
          <button class="btn secondary" type="submit" style="margin-top:8px;">Candida</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($ranking): ?>
    <?php foreach ($ranking as $gkey => $g): $top = (int) ($g['rows'][0]['votes'] ?? 0); ?>
      <div class="section" <?= $gkey === array_key_first($ranking) ? 'id="classifiche"' : '' ?>><?= e($g['label']) ?></div>
      <?php foreach ($g['rows'] as $i => $en): $pct = $top > 0 ? round($en['votes'] / $top * 100) : 0; ?>
        <div class="song <?= $i === 0 ? 'first' : '' ?>" id="brano-<?= (int) $en['id'] ?>">
          <?php if ($i === 0 && $gkey !== 'single'): ?><span class="group-tag <?= $gkey === 'new' ? 'new' : '' ?>"><?= e($g['label']) ?></span><?php endif; ?>
          <div class="song-row">
            <div class="pos"><?= $i + 1 ?></div>
            <?= $cover($en) ?>
            <div class="meta">
              <strong><a href="<?= e(contestEntryUrl($c, (int) $en['id'])) ?>"><?= e($en['track_name']) ?></a></strong>
              <span><?= e($en['band_name']) ?></span>
              <?php if ($c['status'] === 'closed' && $top > 0 && (int) $en['votes'] === $top): ?><span class="winner">🏆 Vincitore</span><?php endif; ?>
              <?php if ($en['track_url']): ?><div class="links"><a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Ascolta su Spotify</a></div><?php endif; ?>
              <?= $spons($en) ?>
            </div>
            <div class="votes"><strong><?= (int) $en['votes'] ?></strong><span>voti</span></div>
          </div>
          <div class="bar"><i style="width:<?= (int) $pct ?>%"></i></div>
          <?php if ($votingOpen): ?>
            <form method="post" class="vote">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="vote">
              <input type="hidden" name="entry_id" value="<?= (int) $en['id'] ?>">
              <input type="email" name="email" placeholder="tuo.indirizzo@gmail.com" required>
              <button class="btn" type="submit">Vota</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php elseif ($entries): ?>
    <div class="section" id="brani">Brani in gara (<?= count($entries) ?>)</div>
    <?php foreach ($entries as $en): ?>
      <div class="song">
        <div class="song-row">
          <?= $cover($en) ?>
          <div class="meta">
            <strong><a href="<?= e(contestEntryUrl($c, (int) $en['id'])) ?>"><?= e($en['track_name']) ?></a></strong>
            <span><?= e($en['band_name']) ?></span>
            <?php if ($en['track_url']): ?><div class="links"><a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Ascolta su Spotify</a></div><?php endif; ?>
            <?= $spons($en) ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($sponsors): ?>
    <div class="section">Sponsor</div>
    <div class="sponsors">
      <?php foreach ($sponsors as $sp): $inner = !empty($sp['logo_path']) ? '<img src="/' . e($sp['logo_path']) . '" alt="' . e($sp['name']) . '">' : e($sp['name']); ?>
        <?php if (!empty($sp['website_url'])): ?><a href="<?= e($sp['website_url']) ?>" target="_blank" rel="noopener sponsored" title="<?= e($sp['name']) ?>"><?= $inner ?></a>
        <?php else: ?><span title="<?= e($sp['name']) ?>"><?= $inner ?></span><?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($c['regulation']): ?>
    <div class="section">Regolamento</div>
    <details class="reg" id="reg">
      <summary>Leggi il regolamento</summary>
      <div><?= nl2br(e($c['regulation'])) ?></div>
    </details>
  <?php endif; ?>
<?php
$js = <<<'JS'
<script>
(function () {
  var cd = document.getElementById('cd');
  if (cd) {
    var target = parseInt(cd.getAttribute('data-target'), 10) * 1000;
    var tick = function () {
      var s = Math.max(0, Math.floor((target - Date.now()) / 1000));
      var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
      cd.textContent = d + 'g ' + h + 'h ' + m + 'm';
    };
    tick(); setInterval(tick, 30000);
  }
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
    p.style.color = 'var(--muted)';
    p.textContent = text;
    box.appendChild(p);
  }
  function render(items) {
    box.textContent = '';
    if (!items.length) { note('Nessun brano trovato.'); return; }
    items.forEach(function (t) {
      var f = document.createElement('form');
      f.method = 'post';
      f.className = 'result';
      f.innerHTML = holder.innerHTML;
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'track'; h.value = t.id;
      f.appendChild(h);
      if (t.image) {
        var img = document.createElement('img');
        img.src = t.image; img.alt = '';
        f.appendChild(img);
      }
      var span = document.createElement('span');
      var strong = document.createElement('strong');
      strong.textContent = t.name;
      var small = document.createElement('small');
      small.textContent = t.artist;
      span.appendChild(strong);
      span.appendChild(document.createElement('br'));
      span.appendChild(small);
      f.appendChild(span);
      var b = document.createElement('button');
      b.className = 'btn'; b.type = 'submit'; b.textContent = 'Candida';
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
    fetch(location.pathname + '?search=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (mine === seq) { render(d.results || []); } })
      .catch(function () { if (mine === seq) { note('Ricerca non riuscita: riprova.'); } })
      .then(function () { button.disabled = false; });
  });
})();
</script>
JS;
contestPageClose($artist, $js);
