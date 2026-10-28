<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/contest.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = getDB()->prepare("SELECT e.*, c.name AS contest_name, c.status AS contest_status, s.name AS sponsor_name, s.logo_path AS sponsor_logo, s.website_url AS sponsor_url,
        p.avatar_path AS artist_avatar
    FROM contest_entries e JOIN contests c ON c.id = e.contest_id
    LEFT JOIN contest_sponsors s ON s.id = e.sponsor_id
    LEFT JOIN profiles p ON p.user_id = e.user_id
    WHERE e.id = ? AND e.status = 'approved' AND c.status <> 'draft'");
$stmt->execute([$id]);
$en = $stmt->fetch();
if (!$en) {
    http_response_code(404);
}
$artistImg = $en ? ($en['artist_avatar'] ?: $en['image_url']) : null;
$pageUrl = $en ? siteUrl('/contest/brano/' . (int) $en['id']) : '';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $en ? e($en['track_name'] . ' — ' . $en['band_name']) : 'Brano non trovato' ?> — <?= e(siteName()) ?></title>
<?php if ($en): ?>
<meta property="og:title" content="<?= e($en['track_name'] . ' — ' . $en['band_name']) ?>">
<meta property="og:description" content="<?= e($en['contest_name'] . ($en['sponsor_name'] ? ' · sostenuto da ' . $en['sponsor_name'] : '')) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<?php if ($en['image_url']): ?><meta property="og:image" content="<?= e($en['image_url']) ?>"><?php endif; ?>
<?php endif; ?>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body>
<div class="container" style="max-width:640px;">
<?php if (!$en): ?>
  <h1>Brano non trovato</h1>
  <p><a href="/contest">← Torna al contest</a></p>
<?php else: ?>
  <p style="color:var(--text-muted);margin-bottom:4px;"><a href="/contest"><?= e($en['contest_name']) ?></a></p>
  <h1><?= e($en['track_name']) ?></h1>
  <p style="font-size:18px;"><?= e($en['band_name']) ?></p>

  <div class="card" style="display:flex;gap:24px;align-items:center;justify-content:center;flex-wrap:wrap;text-align:center;">
    <div>
      <?php if ($artistImg): ?><img src="<?= e(str_starts_with($artistImg, 'http') ? $artistImg : '/' . $artistImg) ?>" alt="<?= e($en['band_name']) ?>" style="width:140px;height:140px;border-radius:14px;object-fit:cover;"><?php endif; ?>
      <div style="margin-top:6px;"><strong><?= e($en['band_name']) ?></strong></div>
    </div>
    <?php if ($en['sponsor_name']): ?>
      <div style="font-size:26px;color:var(--text-muted);">×</div>
      <div>
        <?php $logo = $en['sponsor_logo'] ? '<img src="/' . e($en['sponsor_logo']) . '" alt="' . e($en['sponsor_name']) . '" style="max-width:180px;max-height:120px;object-fit:contain;">' : '<strong style="font-size:20px;">' . e($en['sponsor_name']) . '</strong>'; ?>
        <?php if ($en['sponsor_url']): ?><a href="<?= e($en['sponsor_url']) ?>" target="_blank" rel="noopener sponsored"><?= $logo ?></a><?php else: ?><?= $logo ?><?php endif; ?>
        <div style="margin-top:6px;color:var(--text-muted);font-size:13px;">Sponsor</div>
      </div>
    <?php endif; ?>
  </div>

  <p style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if ($en['track_url']): ?><a class="btn" href="<?= e($en['track_url']) ?>" target="_blank" rel="noopener">Ascolta su Spotify</a><?php endif; ?>
    <?php if ($en['contest_status'] === 'voting'): ?><a class="btn secondary" href="/contest">Vota nella classifica</a><?php endif; ?>
  </p>
  <p><a href="/contest">← Torna al contest</a></p>
<?php endif; ?>
</div>
</body>
</html>
