<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$activeTab = 'profiles';
$pageTitle = 'I tuoi profili';
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $displayName = trim($_POST['display_name'] ?? '');
        $slug = slugify($_POST['slug'] ?? $displayName);

        if ($displayName === '' || $slug === '') {
            $error = 'Inserisci un nome e un nome pagina validi.';
        } elseif (in_array($slug, RESERVED_SLUGS, true)) {
            $error = 'Questo nome pagina non è disponibile, scegline un altro.';
        } elseif (slugExists($slug)) {
            $error = 'Questo nome pagina è già in uso.';
        } else {
            $newId = createOwnedProfile((int) $user['id'], $displayName, $slug);
            header('Location: /dashboard_profiles.php?created=' . $newId);
            exit;
        }
    } elseif ($action === 'delete') {
        $targetId = (int) ($_POST['id'] ?? 0);
        // Elimina solo un profilo creato da te stesso (role='owner'), mai un profilo altrui di
        // cui sei semplice co-admin — quello si gestisce/rimuove solo dal suo stesso Team.
        $stmt = getDB()->prepare("SELECT 1 FROM profile_admins WHERE owner_user_id = ? AND admin_user_id = ? AND role = 'owner'");
        $stmt->execute([$targetId, $user['id']]);
        if ($stmt->fetch()) {
            if (($_SESSION['acting_as_user_id'] ?? null) == $targetId) {
                unset($_SESSION['acting_as_user_id']);
            }
            getDB()->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
            $success = 'Profilo eliminato.';
        } else {
            $error = 'Profilo non trovato.';
        }
    }
}

// Profili con role='owner' creati da questo utente (non i co-admin "normali" di Team)
$stmt = getDB()->prepare("SELECT u.id, u.slug, p.display_name, p.avatar_path
    FROM profile_admins pa JOIN users u ON u.id = pa.owner_user_id JOIN profiles p ON p.user_id = u.id
    WHERE pa.admin_user_id = ? AND pa.role = 'owner' ORDER BY p.display_name ASC");
$stmt->execute([$user['id']]);
$ownedProfiles = $stmt->fetchAll();

$createdId = (int) ($_GET['created'] ?? 0);

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Crea qui altri profili pubblici (pagine) gestiti dallo stesso tuo account — utile ad
      esempio se segui più attività diverse. Ogni profilo ha la propria pagina pubblica, i propri
      link, eventi, blog, ecc., del tutto separati dagli altri. Passa dall'uno all'altro con il
      selettore in alto nella dashboard (l'icona accanto al menu account).
    </p>
  </details>

  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($createdId): ?>
    <div class="alert success">
      Profilo creato! <a href="/dashboard_timeline.php?acting_as=<?= $createdId ?>">Vai a gestirlo →</a>
    </div>
  <?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <label>Nome del profilo</label>
    <input type="text" name="display_name" required placeholder="es. Il mio locale, La mia azienda...">
    <label>Nome pagina (<?= e(siteName()) ?>/<strong>nomepagina</strong>)</label>
    <input type="text" name="slug" placeholder="es. il-mio-locale">
    <button type="submit" class="btn">Crea profilo</button>
  </form>

  <div class="section-title">I tuoi profili (<?= count($ownedProfiles) ?>)</div>
  <?php if (!$ownedProfiles): ?>
    <div class="card">Non hai ancora creato nessun altro profilo.</div>
  <?php endif; ?>
  <?php foreach ($ownedProfiles as $p): ?>
    <div class="link-item" style="display:flex;align-items:center;justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:12px;">
        <?php if ($p['avatar_path']): ?>
          <img src="/<?= e($p['avatar_path']) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
        <?php endif; ?>
        <div><strong><?= e($p['display_name']) ?></strong><br><small style="color:var(--text-muted)">@<?= e($p['slug']) ?></small></div>
      </div>
      <div style="display:flex;gap:8px;">
        <a class="btn small" href="/dashboard_timeline.php?acting_as=<?= (int) $p['id'] ?>">Gestisci</a>
        <form method="post" onsubmit="return confirm('Eliminare definitivamente questo profilo e tutti i suoi contenuti (link, eventi, blog, ecc.)? L\'operazione non è reversibile.');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="btn small danger" type="submit">Elimina</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
