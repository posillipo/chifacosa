<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'users';
$pageTitle = 'Profili pubblici';

$q = trim($_GET['q'] ?? '');
$genere = trim($_GET['genere'] ?? '');
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.display_name LIKE ? OR p.citta LIKE ? OR u.slug LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($genere !== '') {
    $where[] = 'p.genere = ?';
    $params[] = $genere;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = getDB()->prepare("SELECT COUNT(*) c FROM profiles p JOIN users u ON u.id = p.user_id {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = getDB()->prepare("SELECT u.id, u.slug, u.is_active,
        p.display_name, p.genere, p.citta, p.provincia, p.telefono, p.bio, p.avatar_path,
        p.theme_color, p.spotify_artist_name
    FROM profiles p JOIN users u ON u.id = p.user_id
    {$whereSql}
    ORDER BY p.display_name ASC
    LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$profiles = $stmt->fetchAll();

// Elenco generi distinti per il filtro a tendina
$generi = getDB()->query("SELECT DISTINCT genere FROM profiles WHERE genere IS NOT NULL AND genere <> '' ORDER BY genere")->fetchAll(PDO::FETCH_COLUMN);

function qsProfiles(array $overrides = []): string {
    return http_build_query(array_merge($_GET, $overrides));
}

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <a href="/admin_users.php" class="btn btn-secondary btn-sm">← Vedi tabella account/band manager</a>
  </div>

  <form method="get" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div style="flex:1;min-width:200px;">
      <label class="mb-1 d-block">Cerca (nome band, città, slug)</label>
      <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="es. Napoli">
    </div>
    <div style="min-width:200px;">
      <label class="mb-1 d-block">Genere musicale</label>
      <select name="genere" class="form-control">
        <option value="">Tutti</option>
        <?php foreach ($generi as $g): ?>
          <option value="<?= e($g) ?>" <?= $genere === $g ? 'selected' : '' ?>><?= e($g) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><button type="submit" class="btn btn-primary">Filtra</button></div>
  </form>

  <p class="text-muted"><?= $totalRows ?> profili totali — pagina <?= $page ?> di <?= $totalPages ?></p>

  <div class="table-responsive">
    <table class="table table-striped table-hover table-sm bg-white">
      <thead>
        <tr>
          <th>Avatar</th>
          <th>Nome band</th>
          <th>Genere</th>
          <th>Città</th>
          <th>Provincia</th>
          <th>Telefono</th>
          <th>Spotify</th>
          <th>Stato pagina</th>
          <th>Bio (estratto)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($profiles as $p): ?>
          <tr>
            <td>
              <?php if ($p['avatar_path']): ?>
                <img src="/<?= e($p['avatar_path']) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="/<?= e($p['slug']) ?>" target="_blank"><?= e($p['display_name']) ?></a>
              <?php if ($p['theme_color']): ?>
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?= e($p['theme_color']) ?>;vertical-align:middle;margin-left:4px;" title="<?= e($p['theme_color']) ?>"></span>
              <?php endif; ?>
            </td>
            <td><?= e($p['genere'] ?: '—') ?></td>
            <td><?= e($p['citta'] ?: '—') ?></td>
            <td><?= e($p['provincia'] ?: '—') ?></td>
            <td><?= e($p['telefono'] ?: '—') ?></td>
            <td><?= $p['spotify_artist_name'] ? '🎵 ' . e($p['spotify_artist_name']) : '—' ?></td>
            <td>
              <?php if ($p['is_active']): ?>
                <span class="badge badge-success">Pubblica</span>
              <?php else: ?>
                <span class="badge badge-secondary">Non pubblica</span>
              <?php endif; ?>
            </td>
            <td style="max-width:280px;"><?= e(textExcerpt((string)($p['bio'] ?? ''), 100)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav>
      <ul class="pagination flex-wrap">
        <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
          <li class="page-item <?= $pg === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= e(qsProfiles(['page' => $pg])) ?>"><?= $pg ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
