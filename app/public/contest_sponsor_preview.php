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
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Anteprima sponsor — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
</head>
<body>
<div class="container" style="max-width:760px;">
<?php if (!$sponsor || !$contest): ?>
  <h1>Link non valido</h1>
  <p style="color:var(--text-muted)">Questo indirizzo non è valido. Chiedi a MyBand di inviartelo di nuovo.</p>
<?php else: ?>
  <h1><?= e($contest['name']) ?></h1>
  <p style="color:var(--text-muted)">Anteprima riservata a <strong><?= e($sponsor['name']) ?></strong>: l'elenco dei brani in gara, prima della pubblicazione. Per sostenere un brano scrivi a MyBand: l'abbinamento viene registrato dalla direzione.</p>
  <?php if ($sponsor['logo_path']): ?><p><img src="/<?= e($sponsor['logo_path']) ?>" alt="<?= e($sponsor['name']) ?>" style="max-height:60px;max-width:200px;object-fit:contain;"></p><?php endif; ?>

  <div class="section-title">Brani in gara (<?= count($entries) ?>)</div>
  <?php foreach ($entries as $en): $pop = $en['popularity_snapshot'] ?? $en['artist_popularity']; ?>
    <div class="blog-item" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
      <?php if ($en['image_url']): ?><img src="<?= e($en['image_url']) ?>" style="width:64px;height:64px;border-radius:8px;object-fit:cover;" alt=""><?php endif; ?>
      <div style="flex:1;min-width:200px;">
        <strong><?= e($en['track_name']) ?></strong> — <?= e($en['band_name']) ?>
        <div style="color:var(--text-muted);font-size:12.5px;">
          Popolarità Spotify artista: <?= $pop !== null ? (int) $pop : '—' ?>
          <?= $en['release_date'] ? ' · uscito il ' . e(date('d/m/Y', strtotime($en['release_date']))) : '' ?>
          <?= $en['group_key'] && $en['group_key'] !== 'single' ? ' · ' . e(CONTEST_GROUP_LABELS[$en['group_key']] ?? '') : '' ?>
          <?php if ($en['track_url']): ?> · <a href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Spotify</a><?php endif; ?>
        </div>
      </div>
      <div style="font-size:13px;">
        <?php if ((int) $en['sponsor_id'] === (int) $sponsor['id']): ?><strong style="color:var(--accent);">Sostenuto da voi ✔</strong>
        <?php elseif ($en['sponsor_id']): ?><span style="color:var(--text-muted);">Già sostenuto</span>
        <?php else: ?><span style="color:var(--text-muted);">Libero</span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$entries): ?><p style="color:var(--text-muted)">Nessun brano ancora approvato.</p><?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
