<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
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

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx">
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($createdId): ?><div class="alert success">Profilo creato! <a href="/dashboard_timeline.php?acting_as=<?= $createdId ?>">Vai a gestirlo →</a></div><?php endif; ?>

    <form method="post" class="box">
      <?= csrfField() ?><input type="hidden" name="action" value="create">
      <h3><i class="fa-solid fa-plus"></i> Crea un nuovo profilo</h3>
      <p>Per un locale, un'azienda, un progetto: lo gestisci dallo stesso account.</p>
      <label for="pf-name">Nome del profilo</label>
      <input type="text" name="display_name" id="pf-name" required placeholder="es. Il mio locale, La mia azienda...">
      <label for="pf-slug">Nome pagina <small>(<?= e(siteName()) ?>/<b>nomepagina</b>)</small></label>
      <input type="text" name="slug" id="pf-slug" placeholder="es. il-mio-locale">
      <button type="submit" class="tlx-submit cmp-done">Crea profilo</button>
    </form>

    <div class="tlx-listhead"><h2>I tuoi profili <small>(<?= count($ownedProfiles) ?>)</small></h2></div>
    <?php if (!$ownedProfiles): ?><div class="tlx-empty"><i class="fa-regular fa-id-card"></i><b>Nessun altro profilo</b><span>Non hai ancora creato nessun altro profilo.</span></div><?php endif; ?>
    <?php foreach ($ownedProfiles as $p): ?>
      <div class="pnx">
        <?php if ($p['avatar_path']): ?><img class="pnx-img rnd" src="/<?= e($p['avatar_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="pnx-img rnd stc-ph"><?= e(mb_strtoupper(mb_substr($p['display_name'], 0, 1))) ?></span><?php endif; ?>
        <div class="pnx-b"><b><?= e($p['display_name']) ?></b><small>@<?= e($p['slug']) ?></small></div>
        <div class="pnx-act">
          <a class="stc-act primary" href="/dashboard_timeline.php?acting_as=<?= (int) $p['id'] ?>"><i class="fa-solid fa-sliders"></i> <span>Gestisci</span></a>
          <form method="post" data-confirm="Eliminare definitivamente questo profilo e tutti i suoi contenuti (link, eventi, blog, ecc.)? L'operazione non è reversibile."><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="stc-act danger" type="submit" title="Elimina"><i class="fa-solid fa-trash"></i></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
