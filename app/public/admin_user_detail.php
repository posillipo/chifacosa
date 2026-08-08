<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'users';

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_active' && $id !== (int)$admin['id']) {
        $stmt = getDB()->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?');
        $stmt->execute([$id]);
    } elseif ($action === 'toggle_admin' && $id !== (int)$admin['id']) {
        $stmt = getDB()->prepare('UPDATE users SET is_admin = NOT is_admin WHERE id = ?');
        $stmt->execute([$id]);
    } elseif ($action === 'verify_manually' && $id !== (int)$admin['id']) {
        $stmt = getDB()->prepare('UPDATE users SET email_verified = 1, verification_token = NULL, verification_expires = NULL WHERE id = ?');
        $stmt->execute([$id]);
    } elseif ($action === 'delete_user' && $id !== (int)$admin['id']) {
        getDB()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        header('Location: /admin_users.php');
        exit;
    } elseif ($action === 'delete_link') {
        getDB()->prepare('DELETE FROM links WHERE id = ? AND user_id = ?')->execute([(int)($_POST['item_id'] ?? 0), $id]);
    } elseif ($action === 'delete_track') {
        $t = getDB()->prepare('SELECT file_path FROM audio_tracks WHERE id = ? AND user_id = ?');
        $t->execute([(int)($_POST['item_id'] ?? 0), $id]);
        if ($row = $t->fetch()) {
            @unlink(__DIR__ . '/' . $row['file_path']);
            getDB()->prepare('DELETE FROM audio_tracks WHERE id = ? AND user_id = ?')->execute([(int)($_POST['item_id'] ?? 0), $id]);
        }
    } elseif ($action === 'delete_event') {
        getDB()->prepare('DELETE FROM events WHERE id = ? AND user_id = ?')->execute([(int)($_POST['item_id'] ?? 0), $id]);
    } elseif ($action === 'delete_post') {
        getDB()->prepare('DELETE FROM blog_posts WHERE id = ? AND user_id = ?')->execute([(int)($_POST['item_id'] ?? 0), $id]);
    }
    header('Location: /admin_user_detail.php?id=' . $id);
    exit;
}

$stmt = getDB()->prepare('SELECT u.*, p.display_name, p.bio, p.avatar_path
                          FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
$stmt->execute([$id]);
$u = $stmt->fetch();

if (!$u) {
    http_response_code(404);
    exit('Utente non trovato.');
}
$pageTitle = $u['display_name'];

$contacts = getDB()->prepare('SELECT * FROM contact_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 20');
$contacts->execute([$id]);
$contacts = $contacts->fetchAll();

$links = getDB()->prepare('SELECT * FROM links WHERE user_id=? ORDER BY sort_order ASC, id ASC');
$links->execute([$id]);
$links = $links->fetchAll();

$tracks = getDB()->prepare('SELECT * FROM audio_tracks WHERE user_id=? ORDER BY sort_order ASC, id DESC');
$tracks->execute([$id]);
$tracks = $tracks->fetchAll();

$events = getDB()->prepare('SELECT * FROM events WHERE user_id=? ORDER BY event_date DESC');
$events->execute([$id]);
$events = $events->fetchAll();

$posts = getDB()->prepare('SELECT * FROM blog_posts WHERE user_id=? ORDER BY published_at DESC');
$posts->execute([$id]);
$posts = $posts->fetchAll();

include __DIR__ . '/_admin_header.php';
?>
  <p><a href="/admin_users.php">← Tutti gli utenti</a></p>

  <div class="card">
    <h2 style="margin-top:0;"><?= e($u['display_name']) ?></h2>
    <p style="color:var(--text-muted)">
      Email: <?= e($u['email']) ?><br>
      Pagina pubblica: <a href="/<?= e($u['slug']) ?>" target="_blank"><?= e(siteName()) ?>/<?= e($u['slug']) ?></a><br>
      Iscritto il: <?= date('d/m/Y H:i', strtotime($u['created_at'])) ?><br>
      Stato: <?= $u['is_active'] ? 'Attivo' : 'Disattivato' ?><?= $u['is_admin'] ? ' · Amministratore' : '' ?>
      <?= !$u['email_verified'] ? ' · Email da verificare' : '' ?>
    </p>
    <?php if ($u['bio']): ?><p><em><?= nl2br(e($u['bio'])) ?></em></p><?php endif; ?>

    <?php if ($u['id'] != $admin['id']): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <form method="post" onsubmit="return confirm('<?= $u['is_active'] ? 'Disattivare' : 'Riattivare' ?> questo account?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_active">
        <button class="btn <?= $u['is_active'] ? 'danger' : '' ?>" type="submit">
          <?= $u['is_active'] ? 'Disattiva account' : 'Riattiva account' ?>
        </button>
      </form>
      <form method="post" onsubmit="return confirm('<?= $u['is_admin'] ? 'Rimuovere i permessi da amministratore?' : 'Rendere questo utente amministratore?' ?>');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_admin">
        <button class="btn secondary" type="submit">
          <?= $u['is_admin'] ? 'Rimuovi permessi admin' : 'Rendi amministratore' ?>
        </button>
      </form>
      <?php if (!$u['email_verified']): ?>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="verify_manually">
        <button class="btn secondary" type="submit">Verifica email manualmente</button>
      </form>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('Eliminare DEFINITIVAMENTE questo account e tutti i suoi contenuti? Azione irreversibile.');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_user">
        <button class="btn danger" type="submit">Elimina account</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="section-title">Link (<?= count($links) ?>)</div>
  <?php if (!$links): ?><div class="card">Nessun link creato.</div><?php endif; ?>
  <?php foreach ($links as $l): ?>
    <div class="link-item">
      <div><strong><?= e($l['label']) ?></strong><br><small style="color:var(--text-muted)"><?= e($l['url']) ?></small></div>
      <form method="post" onsubmit="return confirm('Eliminare questo link?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_link">
        <input type="hidden" name="item_id" value="<?= (int)$l['id'] ?>">
        <button class="btn small danger" type="submit">Elimina</button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="section-title">Brani (<?= count($tracks) ?>)</div>
  <?php if (!$tracks): ?><div class="card">Nessun brano caricato.</div><?php endif; ?>
  <?php foreach ($tracks as $t): ?>
    <div class="card">
      <strong><?= e($t['title']) ?></strong>
      <form method="post" onsubmit="return confirm('Eliminare questo brano?');" style="display:inline-block;margin-left:10px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_track">
        <input type="hidden" name="item_id" value="<?= (int)$t['id'] ?>">
        <button class="btn small danger" type="submit">Elimina</button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="section-title">Eventi (<?= count($events) ?>)</div>
  <?php if (!$events): ?><div class="card">Nessun evento creato.</div><?php endif; ?>
  <?php foreach ($events as $ev): ?>
    <div class="event-item">
      <div class="date"><?= date('d/m/Y H:i', strtotime($ev['event_date'])) ?></div>
      <strong><?= e($ev['title']) ?></strong>
      <form method="post" onsubmit="return confirm('Eliminare questo evento?');" style="margin-top:6px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_event">
        <input type="hidden" name="item_id" value="<?= (int)$ev['id'] ?>">
        <button class="btn small danger" type="submit">Elimina</button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="section-title">Articoli blog (<?= count($posts) ?>)</div>
  <?php if (!$posts): ?><div class="card">Nessun articolo pubblicato.</div><?php endif; ?>
  <?php foreach ($posts as $p): ?>
    <div class="blog-item">
      <strong><?= e($p['title']) ?></strong>
      <form method="post" onsubmit="return confirm('Eliminare questo articolo?');" style="margin-top:6px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_post">
        <input type="hidden" name="item_id" value="<?= (int)$p['id'] ?>">
        <button class="btn small danger" type="submit">Elimina</button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="section-title">Richieste di contatto ricevute (<?= count($contacts) ?>)</div>
  <?php if (!$contacts): ?>
    <div class="card">Nessuna richiesta ricevuta.</div>
  <?php endif; ?>
  <?php foreach ($contacts as $c): ?>
    <div class="card">
      <strong><?= e($c['sender_name']) ?></strong>
      <small style="color:var(--text-muted)"> &lt;<?= e($c['sender_email']) ?>&gt; · <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></small>
      <p><?= nl2br(e($c['message'])) ?></p>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
