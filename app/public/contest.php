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
    $stmt = getDB()->prepare("SELECT band_name, track_name, image_url, track_url FROM contest_entries WHERE contest_id = ? AND status = 'approved' ORDER BY approved_at ASC, id ASC");
    $stmt->execute([(int) $c['id']]);
    $entries = $stmt->fetchAll();
}
$ranking = ($c && in_array($c['status'], ['voting', 'closed'], true)) ? contestRanking($c) : [];
$votingOpen = $c ? contestVotingOpen($c) : false;
$q = trim($_GET['q'] ?? '');
$results = ($c && $myProfile && !$myEntry && $q !== '' && contestCandidaturesOpen($c)) ? spotifySearchTrack($q) : [];
$contact = getSiteSetting('contest_contact_email') ?: '';
$fmt = fn ($d) => $d ? date('d/m/Y H:i', strtotime($d)) : null;
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

  <?php if ($c['regulation']): ?>
    <div class="card"><strong>Regolamento</strong><div style="margin-top:8px;"><?= nl2br(e($c['regulation'])) ?></div></div>
  <?php endif; ?>

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
        <form method="get">
          <label>Cerca il tuo brano su Spotify</label>
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="titolo e artista">
          <button class="btn small" type="submit">Cerca</button>
        </form>
        <?php foreach ($results as $t): ?>
          <form method="post" style="display:flex;gap:10px;align-items:center;margin-top:10px;">
            <?= csrfField() ?>
            <input type="hidden" name="track" value="<?= e($t['id']) ?>">
            <?php if ($t['image']): ?><img src="<?= e($t['image']) ?>" style="width:48px;height:48px;border-radius:6px;" alt=""><?php endif; ?>
            <span style="flex:1;"><strong><?= e($t['name']) ?></strong><br><small style="color:var(--text-muted)"><?= e($t['artist_name']) ?></small></span>
            <button class="btn small" type="submit">Candida questo brano</button>
          </form>
        <?php endforeach; ?>
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
            <strong><?= e($en['track_name']) ?></strong>
            <?php if ($c['status'] === 'closed' && $top > 0 && (int) $en['votes'] === (int) $top): ?> 🏆 <em>Vincitore</em><?php endif; ?>
            <br><span style="color:var(--text-muted)"><?= e($en['band_name']) ?></span>
            <?php if ($en['track_url']): ?> · <a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Ascolta su Spotify</a><?php endif; ?>
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
      <div><strong><?= e($en['track_name']) ?></strong><br><span style="color:var(--text-muted)"><?= e($en['band_name']) ?></span>
        <?php if ($en['track_url']): ?> · <a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Ascolta su Spotify</a><?php endif; ?></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$entries): ?><p style="color:var(--text-muted)">Nessun brano ancora in gara.</p><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
  <p><a href="/">← Torna alla home</a></p>
</div>
</body>
</html>
