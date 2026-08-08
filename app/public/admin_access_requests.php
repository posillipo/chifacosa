<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/mailer.php';
$admin = requireAdmin();
$activeAdminTab = 'access_requests';
$pageTitle = 'Richieste di accesso';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = getDB()->prepare('SELECT * FROM access_requests WHERE id = ?');
    $stmt->execute([$id]);
    $req = $stmt->fetch();

    if ($req && $action === 'approve') {
        $token = bin2hex(random_bytes(24));
        $stmt = getDB()->prepare("UPDATE access_requests SET status='approved', invite_token=?, decided_at=NOW() WHERE id=?");
        $stmt->execute([$token, $id]);

        $cfg = getSmtpConfig();
        if ($cfg['host']) {
            $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);
            $link = siteUrl('/register.php?invite=' . $token);
            $body = "Ciao {$req['name']},\n\nLa tua richiesta di accesso a " . siteName() . " è stata approvata!\n\nCompleta la registrazione qui: {$link}\n\nA presto,\n" . siteName();
            $mailer->send($cfg['from'], $cfg['fromName'], $req['email'], $req['name'], 'Il tuo invito per ' . siteName(), $body);
        }
    } elseif ($req && $action === 'reject') {
        $stmt = getDB()->prepare("UPDATE access_requests SET status='rejected', decided_at=NOW() WHERE id=?");
        $stmt->execute([$id]);
    }
}

$requests = getDB()->query("SELECT * FROM access_requests ORDER BY (status='pending') DESC, created_at DESC LIMIT 300")->fetchAll();

include __DIR__ . '/_admin_header.php';
?>
  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      La registrazione a <?= e(siteName()) ?> è solo su invito. Le richieste ricevute dalla landing page
      compaiono qui: approvandole, invii automaticamente un'email con un link di registrazione
      valido una sola volta.
    </p>
  </div>

  <?php foreach ($requests as $r): ?>
    <div class="card" style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;">
      <div>
        <strong><?= e($r['name']) ?></strong>
        <?php if ($r['status'] === 'pending'): ?>
          <span style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px;">In attesa</span>
        <?php elseif ($r['status'] === 'approved'): ?>
          <span style="background:#1DB954;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px;">
            Approvata<?= $r['invite_used'] ? ' · invito usato' : ' · in attesa di registrazione' ?>
          </span>
        <?php else: ?>
          <span style="background:#6c757d;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px;">Rifiutata</span>
        <?php endif; ?>
        <br>
        <small style="color:var(--text-muted)"><?= e($r['email']) ?><?= $r['band_name'] ? ' · ' . e($r['band_name']) : '' ?></small>
        <?php if ($r['message']): ?><p style="margin:8px 0 0;"><?= nl2br(e($r['message'])) ?></p><?php endif; ?>
        <small style="color:var(--text-muted)">Richiesta il <?= e($r['created_at']) ?></small>
      </div>
      <?php if ($r['status'] === 'pending'): ?>
        <div style="display:flex;gap:8px;flex-shrink:0;">
          <form method="post" onsubmit="return confirm('Approvare e inviare l\'invito via email?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-primary" type="submit">Approva</button>
          </form>
          <form method="post" onsubmit="return confirm('Rifiutare questa richiesta?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit">Rifiuta</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
