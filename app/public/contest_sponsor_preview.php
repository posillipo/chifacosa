<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/contest.php';

// Anteprima riservata agli sponsor: l'elenco dei brani in gara prima che il contest sia pubblico.
// L'accesso è il link personale con il token dello sponsor.
$sponsor = contestSponsorByToken((string) ($_GET['t'] ?? ''));
if (!$sponsor) {
    http_response_code(404);
}
$contest = $sponsor ? contestGet((int) $sponsor['contest_id']) : null;
$entries = [];
if ($sponsor && $contest) {
    $stmt = getDB()->prepare("SELECT e.id, e.band_name, e.track_name, e.image_url, e.track_url, e.release_date, e.artist_popularity, e.popularity_snapshot, e.group_key, e.sponsor_id,
            s.name AS sponsor_name
        FROM contest_entries e LEFT JOIN contest_sponsors s ON s.id = e.sponsor_id
        WHERE e.contest_id = ? AND e.status = 'approved' ORDER BY COALESCE(e.popularity_snapshot, e.artist_popularity) DESC, e.band_name");
    $stmt->execute([(int) $contest['id']]);
    $entries = $stmt->fetchAll();
}
contestHead('Anteprima sponsor', '', true);
if (!$sponsor || !$contest): ?>
  <header class="hero"><div class="logo">🔒</div><h1>Link non valido</h1><p>Questo indirizzo non è valido. Chiedi a MyBand di inviartelo di nuovo.</p></header>
<?php else: ?>
  <header class="hero">
    <div class="logo"><?= $sponsor['logo_path'] ? '<img src="/' . e($sponsor['logo_path']) . '" alt="' . e($sponsor['name']) . '">' : '🤝' ?></div>
    <h1><?= e($contest['name']) ?></h1>
    <p>Anteprima riservata a <strong><?= e($sponsor['name']) ?></strong>: l'elenco dei brani in gara, prima della pubblicazione. Per sostenere un brano scrivi a MyBand: l'abbinamento viene registrato dalla direzione.</p>
  </header>

  <div class="section">Brani in gara (<?= count($entries) ?>)</div>
  <?php foreach ($entries as $en): $pop = $en['popularity_snapshot'] ?? $en['artist_popularity']; ?>
    <div class="song">
      <div class="song-row">
        <div class="cover"><?= $en['image_url'] ? '<img src="' . e($en['image_url']) . '" alt="">' : '♪' ?></div>
        <div class="meta">
          <strong><?= e($en['track_name']) ?></strong>
          <span><?= e($en['band_name']) ?></span>
          <span style="font-size:12px;">Popolarità Spotify: <?= $pop !== null ? (int) $pop : '—' ?><?= $en['release_date'] ? ' · uscito il ' . e(date('d/m/Y', strtotime($en['release_date']))) : '' ?><?= $en['group_key'] && $en['group_key'] !== 'single' ? ' · ' . e(CONTEST_GROUP_LABELS[$en['group_key']] ?? '') : '' ?></span>
          <?php if ($en['track_url']): ?><div class="links"><a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Ascolta su Spotify</a></div><?php endif; ?>
        </div>
        <div class="votes" style="max-width:96px;">
          <?php if ((int) $en['sponsor_id'] === (int) $sponsor['id']): ?><span class="winner" style="color:var(--accent);">Sostenuto da voi ✔</span>
          <?php elseif ($en['sponsor_id']): ?><span>Già sostenuto</span>
          <?php else: ?><span>Libero</span><?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$entries): ?><div class="msg info">Nessun brano ancora approvato.</div><?php endif; ?>
<?php endif;
contestFoot();
