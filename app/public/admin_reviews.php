<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'reviews';
$pageTitle = 'Recensioni';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    checkCsrf();
    $type = $_POST['type'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($type === 'band') {
        $stmt = getDB()->prepare('DELETE FROM band_reviews WHERE id = ?');
        $stmt->execute([$id]);
    } elseif ($type === 'track') {
        $stmt = getDB()->prepare('DELETE FROM track_reviews WHERE id = ?');
        $stmt->execute([$id]);
    }
}

$bandReviews = getDB()->query("SELECT br.id, br.rating, br.created_at, u1.slug AS band_slug, u2.slug AS reviewer_slug
    FROM band_reviews br
    JOIN users u1 ON u1.id = br.band_user_id
    JOIN users u2 ON u2.id = br.reviewer_user_id
    ORDER BY br.created_at DESC LIMIT 200")->fetchAll();

$trackReviews = getDB()->query("SELECT tr.id, tr.rating, tr.created_at, ft.track_name, u1.slug AS band_slug, u2.slug AS reviewer_slug
    FROM track_reviews tr
    JOIN favorite_tracks ft ON ft.id = tr.track_id
    JOIN users u1 ON u1.id = ft.user_id
    JOIN users u2 ON u2.id = tr.reviewer_user_id
    ORDER BY tr.created_at DESC LIMIT 200")->fetchAll();

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <strong>Moderazione recensioni</strong>
    <p style="color:var(--text-muted)">Elimina una recensione se segnalata come fuori luogo. Operazione immediata, senza conferma aggiuntiva.</p>
  </div>

  <div class="section-title">Recensioni band (<?= count($bandReviews) ?>)</div>
  <?php foreach ($bandReviews as $r): ?>
    <div class="link-item">
      <div>@<?= e($r['reviewer_slug']) ?> → <a href="/<?= e($r['band_slug']) ?>" target="_blank">@<?= e($r['band_slug']) ?></a>: <?= str_repeat('★', (int)$r['rating']) ?> — <?= e($r['created_at']) ?></div>
      <form method="post" onsubmit="return confirm('Eliminare questa recensione?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="type" value="band">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-sm btn-danger" type="submit">Elimina</button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="section-title">Recensioni brani (<?= count($trackReviews) ?>)</div>
  <?php foreach ($trackReviews as $r): ?>
    <div class="link-item">
      <div>@<?= e($r['reviewer_slug']) ?> → "<?= e($r['track_name']) ?>" (<?= e($r['band_slug']) ?>): <?= str_repeat('★', (int)$r['rating']) ?> — <?= e($r['created_at']) ?></div>
      <form method="post" onsubmit="return confirm('Eliminare questa recensione?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="type" value="track">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-sm btn-danger" type="submit">Elimina</button>
      </form>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
