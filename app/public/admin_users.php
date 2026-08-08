<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'users';
$pageTitle = 'Utenti iscritti (band manager)';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'toggle_active' && $id !== (int)$admin['id']) {
        getDB()->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
    } elseif ($action === 'toggle_admin' && $id !== (int)$admin['id']) {
        getDB()->prepare('UPDATE users SET is_admin = NOT is_admin WHERE id = ?')->execute([$id]);
    } elseif ($action === 'verify_manually' && $id !== (int)$admin['id']) {
        getDB()->prepare('UPDATE users SET email_verified = 1, verification_token = NULL, verification_expires = NULL WHERE id = ?')->execute([$id]);
    } elseif ($action === 'delete_user' && $id !== (int)$admin['id']) {
        getDB()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /admin_users.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// Filtri
$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';
$origin = $_GET['origin'] ?? 'all'; // all | real | legacy
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

// Ordinamento: whitelist dei campi ordinabili per sicurezza (mai concatenare l'input diretto in ORDER BY)
$sortableColumns = [
    'id' => 'u.id',
    'display_name' => 'p.display_name',
    'email' => 'u.email',
    'slug' => 'u.slug',
    'citta' => 'p.citta',
    'is_active' => 'u.is_active',
    'email_verified' => 'u.email_verified',
    'created_at' => 'u.created_at',
];
$sort = $_GET['sort'] ?? 'created_at';
if (!isset($sortableColumns[$sort])) {
    $sort = 'created_at';
}
$dir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$orderBySql = $sortableColumns[$sort] . ' ' . $dir;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.display_name LIKE ? OR u.email LIKE ? OR u.slug LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($status === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($status === 'disabled') {
    $where[] = 'u.is_active = 0';
} elseif ($status === 'unverified') {
    $where[] = 'u.email_verified = 0';
}
if ($origin === 'real') {
    $where[] = 'u.legacy_gestore_id IS NULL';
} elseif ($origin === 'legacy') {
    $where[] = 'u.legacy_gestore_id IS NOT NULL';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = getDB()->prepare("SELECT COUNT(*) c FROM users u JOIN profiles p ON p.user_id = u.id {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = getDB()->prepare("SELECT u.id, u.slug, u.email, u.created_at, u.is_active, u.is_admin, u.email_verified,
        u.legacy_gestore_id, u.legacy_stato, u.account_type, p.display_name, p.citta
    FROM users u JOIN profiles p ON p.user_id = u.id
    {$whereSql}
    ORDER BY {$orderBySql}
    LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$users = $stmt->fetchAll();

function qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

// Genera l'intestazione di colonna cliccabile per l'ordinamento, con la freccia sulla colonna attiva
function sortHeader(string $label, string $field, string $currentSort, string $currentDir): string {
    $isActive = $currentSort === $field;
    $newDir = ($isActive && $currentDir === 'ASC') ? 'desc' : 'asc';
    $arrow = $isActive ? ($currentDir === 'ASC' ? ' ▲' : ' ▼') : '';
    $url = '?' . qs(['sort' => $field, 'dir' => $newDir, 'page' => 1]);
    return '<a href="' . e($url) . '" style="color:inherit;text-decoration:none;">' . e($label) . $arrow . '</a>';
}

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <a href="/admin_profiles.php" class="btn btn-secondary btn-sm">Vedi tabella profili pubblici →</a>
    <a href="/admin_apply_percorso.php" class="btn btn-secondary btn-sm">Applica percorso come slug →</a>
  </div>

  <form method="get" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div style="flex:1;min-width:200px;">
      <label class="mb-1 d-block">Cerca (nome, email, slug)</label>
      <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="es. gianluca">
    </div>
    <div style="min-width:170px;">
      <label class="mb-1 d-block">Stato</label>
      <select name="status" class="form-control">
        <option value="all" <?= $status==='all'?'selected':'' ?>>Tutti</option>
        <option value="active" <?= $status==='active'?'selected':'' ?>>Attivi</option>
        <option value="disabled" <?= $status==='disabled'?'selected':'' ?>>Disattivati</option>
        <option value="unverified" <?= $status==='unverified'?'selected':'' ?>>Da verificare</option>
      </select>
    </div>
    <div style="min-width:170px;">
      <label class="mb-1 d-block">Origine</label>
      <select name="origin" class="form-control">
        <option value="all" <?= $origin==='all'?'selected':'' ?>>Tutte</option>
        <option value="real" <?= $origin==='real'?'selected':'' ?>>Registrati sul nuovo sito</option>
        <option value="legacy" <?= $origin==='legacy'?'selected':'' ?>>Importati (legacy)</option>
      </select>
    </div>
    <div><button type="submit" class="btn btn-primary">Filtra</button></div>
  </form>

  <p class="text-muted">
    <?= $totalRows ?> risultati totali — pagina <?= $page ?> di <?= $totalPages ?>
  </p>

  <div class="table-responsive">
    <table class="table table-striped table-hover table-sm bg-white">
      <thead>
        <tr>
          <th><?= sortHeader('ID', 'id', $sort, $dir) ?></th>
          <th><?= sortHeader('Nome band', 'display_name', $sort, $dir) ?></th>
          <th><?= sortHeader('Email', 'email', $sort, $dir) ?></th>
          <th><?= sortHeader('Slug', 'slug', $sort, $dir) ?></th>
          <th><?= sortHeader('Città', 'citta', $sort, $dir) ?></th>
          <th>Tipo</th>
          <th><?= sortHeader('Stato', 'is_active', $sort, $dir) ?></th>
          <th><?= sortHeader('Email verif.', 'email_verified', $sort, $dir) ?></th>
          <th>Origine</th>
          <th><?= sortHeader('Iscritto il', 'created_at', $sort, $dir) ?></th>
          <th>Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td>
              <?= e($u['display_name']) ?>
              <?php if ($u['is_admin']): ?><span class="badge badge-primary">ADMIN</span><?php endif; ?>
            </td>
            <td><?= e($u['email']) ?></td>
            <td><a href="/<?= e($u['slug']) ?>" target="_blank"><?= e($u['slug']) ?></a></td>
            <td><?= e($u['citta'] ?: '—') ?></td>
            <td>
              <?php
                $typeLabels = ['band' => 'Band/Artista', 'fan' => 'Fan', 'label' => 'Etichetta'];
                echo e($typeLabels[$u['account_type']] ?? 'Band/Artista');
              ?>
            </td>
            <td>
              <?php if ($u['is_active']): ?>
                <span class="badge badge-success">Attivo</span>
              <?php else: ?>
                <span class="badge badge-secondary">Disattivato</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['email_verified']): ?>
                <span class="badge badge-success">Sì</span>
              <?php else: ?>
                <span class="badge badge-warning">No</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['legacy_gestore_id']): ?>
                <span class="badge badge-info" title="Stato legacy: <?= e($u['legacy_stato'] ?? '') ?>">
                  Legacy (<?= e($u['legacy_stato'] ?? '?') ?>)
                </span>
              <?php else: ?>
                <span class="badge badge-light">Reale</span>
              <?php endif; ?>
            </td>
            <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="/admin_user_detail.php?id=<?= (int)$u['id'] ?>" title="Dettagli"><i class="fas fa-eye"></i></a>
              <a class="btn btn-sm btn-outline-secondary" href="/admin_user_edit.php?id=<?= (int)$u['id'] ?>" title="Modifica"><i class="fas fa-pen"></i></a>
              <?php if ($u['id'] != $admin['id']): ?>
              <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-<?= $u['is_active'] ? 'danger' : 'success' ?>" type="submit" title="<?= $u['is_active'] ? 'Disattiva' : 'Attiva' ?>">
                  <i class="fas <?= $u['is_active'] ? 'fa-ban' : 'fa-check' ?>"></i>
                </button>
              </form>
              <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_admin">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit" title="Rendi/rimuovi admin">
                  <i class="fas fa-user-shield"></i>
                </button>
              </form>
              <?php if (!$u['email_verified']): ?>
              <form method="post" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="verify_manually">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-success" type="submit" title="Verifica email manualmente">
                  <i class="fas fa-envelope-circle-check"></i>
                </button>
              </form>
              <?php endif; ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare definitivamente questo utente e tutti i suoi contenuti?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit" title="Elimina">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav>
      <ul class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= e(qs(['page' => $p])) ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
