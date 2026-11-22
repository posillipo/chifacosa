<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'service_inquiries';
$pageTitle = 'Richieste sui servizi';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'mark_read') {
        $stmt = getDB()->prepare('UPDATE service_inquiries SET is_read=1 WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'delete') {
        $stmt = getDB()->prepare('DELETE FROM service_inquiries WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    }
    header('Location: /dashboard_service_inquiries.php');
    exit;
}

$stmt = getDB()->prepare('SELECT si.*, sv.title AS service_title FROM service_inquiries si
    JOIN services sv ON sv.id = si.service_id
    WHERE si.user_id=? ORDER BY si.created_at DESC');
$stmt->execute([$profile['id']]);
$inquiries = $stmt->fetchAll();

include __DIR__ . '/../_dash_header.php';
$unread = count(array_filter($inquiries, fn ($r) => !$r['is_read']));
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($inquiries) ?></b><span>Richieste ricevute</span></div>
      <div class="tlx-kpi"><b><?= $unread ?></b><span>Da leggere</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_services.php"><b><i class="fa-solid fa-hand-holding-heart"></i></b><span>I tuoi servizi</span></a>
      <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>/servizi" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>Pagina pubblica</span></a>
    </div>
    <div class="tlx-listhead"><h2>Richieste di informazioni</h2>
      <div class="tlx-filters" data-filters="#ibx-list"><button type="button" class="on" data-f="all">Tutte</button><button type="button" data-f="unread">Da leggere</button><button type="button" data-f="read">Lette</button></div>
    </div>
    <?php if (!$inquiries): ?>
      <div class="tlx-empty"><i class="fa-regular fa-envelope-open"></i><b>Nessuna richiesta ancora</b><span>Le richieste fatte dal pulsante "Richiedi informazioni" dei tuoi servizi arrivano qui.</span></div>
    <?php endif; ?>
    <div class="tlx-empty" data-filter-empty hidden><i class="fa-regular fa-circle-check"></i><b>Niente da mostrare con questo filtro</b></div>
    <div id="ibx-list">
    <?php foreach ($inquiries as $r):
      $digits = preg_replace('~\D+~', '', (string) $r['guest_phone']);
    ?>
      <article class="ibx kit-item<?= !$r['is_read'] ? ' is-new' : '' ?>" data-state="<?= $r['is_read'] ? 'read' : 'unread' ?>">
        <header class="ibx-h">
          <span class="ibx-av"><?= e(mb_strtoupper(mb_substr($r['guest_name'], 0, 1))) ?></span>
          <div class="ibx-who"><b><?= e($r['guest_name']) ?></b><a href="mailto:<?= e($r['guest_email']) ?>"><?= e($r['guest_email']) ?></a></div>
          <div class="ibx-meta">
            <?php if (!$r['is_read']): ?><span class="stc-status stc-s-warn"><i class="fa-solid fa-circle"></i> Nuova</span><?php endif; ?>
            <time><?= e(formatLocalDateTime($r['created_at'], $profile)) ?></time>
          </div>
        </header>
        <div class="ibx-svc"><i class="fa-solid fa-hand-holding-heart"></i> Servizio: <b><?= e($r['service_title']) ?></b></div>
        <?php if ($r['message']): ?><p class="ibx-msg"><?= nl2br(e($r['message'])) ?></p><?php endif; ?>
        <div class="stc-actions ibx-act">
          <a class="stc-act primary" href="mailto:<?= e($r['guest_email']) ?>?subject=<?= rawurlencode('Re: ' . $r['service_title']) ?>"><i class="fa-solid fa-reply"></i> <span>Rispondi per email</span></a>
          <?php if ($r['guest_phone']): ?>
            <a class="stc-act" href="tel:<?= e($r['guest_phone']) ?>"><i class="fa-solid fa-phone"></i> <span><?= e($r['guest_phone']) ?></span></a>
            <?php if (strlen($digits) >= 8): ?><a class="stc-act" href="https://wa.me/<?= e($digits) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> <span>WhatsApp</span></a><?php endif; ?>
          <?php endif; ?>
          <?php if (!$r['is_read']): ?>
            <form method="post" class="ibx-inline"><?= csrfField() ?><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="stc-act" type="submit"><i class="fa-solid fa-check"></i> <span>Segna come letta</span></button></form>
          <?php endif; ?>
          <form method="post" class="ibx-inline" data-confirm="Eliminare questa richiesta?"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="stc-act danger" type="submit"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button></form>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
