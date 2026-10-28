<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/contest.php';

$artist = contestLoadArtist((string) ($_GET['slug'] ?? ''));
if (!$artist || !contestModuleEnabled((int) $artist['id'])) {
    http_response_code(404);
    exit('Pagina non trovata.');
}
$c = contestGetBySlug((int) $artist['id'], (string) ($_GET['edition'] ?? ''));
$en = null;
if ($c && ($c['status'] !== 'draft' || contestCanSeeDrafts(currentUser(), (int) $artist['id']))) {
    $stmt = getDB()->prepare("SELECT e.*, s.name AS sponsor_name, s.logo_path AS sponsor_logo, s.website_url AS sponsor_url, p.avatar_path AS artist_avatar
        FROM contest_entries e
        LEFT JOIN contest_sponsors s ON s.id = e.sponsor_id
        LEFT JOIN profiles p ON p.user_id = e.user_id
        WHERE e.id = ? AND e.contest_id = ? AND e.status = 'approved'");
    $stmt->execute([(int) ($_GET['id'] ?? 0), (int) $c['id']]);
    $en = $stmt->fetch() ?: null;
}
if (!$en) {
    http_response_code(404);
    contestPageOpen($artist, 'Brano non trovato', '', true);
    ?>
  <header class="hero"><div class="logo">🎵</div><h1>Brano non trovato</h1></header>
  <a class="btn-link primary" href="/<?= e($artist['slug']) ?>/contest"><span class="ico">🏆</span><span>Vedi i contest</span><span class="arrow">›</span></a>
<?php
    contestPageClose($artist);
    exit;
}
$contestHref = contestUrl($c);
$artistImg = $en['artist_avatar'] ?: $en['image_url'];
$og = '<meta property="og:title" content="' . e($en['track_name'] . ' — ' . $en['band_name']) . '">'
    . '<meta property="og:description" content="' . e($c['name'] . ($en['sponsor_name'] ? ' · sostenuto da ' . $en['sponsor_name'] : '')) . '">'
    . '<meta property="og:url" content="' . e(siteUrl(contestEntryUrl($c, (int) $en['id']))) . '">'
    . ($en['image_url'] ? '<meta property="og:image" content="' . e($en['image_url']) . '">' : '');
contestPageOpen($artist, $en['track_name'] . ' — ' . $en['band_name'], $og);
?>
  <a class="back" href="<?= e($contestHref) ?>">← <?= e($c['name']) ?></a>

  <header class="hero">
    <h1><?= e($en['track_name']) ?></h1>
    <p style="font-size:17px;"><?= e($en['band_name']) ?></p>
  </header>

  <div class="box">
    <div class="pair">
      <div>
        <?php if ($artistImg): ?><img class="art" src="<?= e(str_starts_with($artistImg, 'http') ? $artistImg : '/' . $artistImg) ?>" alt="<?= e($en['band_name']) ?>"><?php endif; ?>
        <div style="margin-top:6px;font-weight:700;"><?= e($en['band_name']) ?></div>
      </div>
      <?php if ($en['sponsor_name']): ?>
        <div class="x">×</div>
        <div>
          <?php $logo = $en['sponsor_logo'] ? '<img class="spon" src="/' . e($en['sponsor_logo']) . '" alt="' . e($en['sponsor_name']) . '">' : '<strong style="font-size:20px;">' . e($en['sponsor_name']) . '</strong>'; ?>
          <?php if ($en['sponsor_url']): ?><a href="<?= e($en['sponsor_url']) ?>" target="_blank" rel="noopener sponsored"><?= $logo ?></a><?php else: ?><?= $logo ?><?php endif; ?>
          <div style="margin-top:6px;color:var(--muted);font-size:13px;">Sponsor</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($en['track_url']): ?><a class="btn-link primary" href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener"><span class="ico">▶️</span><span>Ascolta su Spotify</span><span class="arrow">›</span></a><?php endif; ?>
  <?php if ($c['status'] === 'voting'): ?><a class="btn-link" href="<?= e($contestHref) ?>#brano-<?= (int) $en['id'] ?>"><span class="ico">🗳️</span><span>Vota questo brano</span><span class="arrow">›</span></a><?php endif; ?>
<?php contestPageClose($artist); ?>
