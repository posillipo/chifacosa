<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
$user = requireLogin();
if (!siteToolEnabled('programmati')) {
    http_response_code(404);
    exit('Questa funzione non è attiva su questo sito.');
}
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'schedule';
$pageTitle = 'Programmati';

$items = getScheduledContentForUser((int) $profile['id'], $profile['slug']);

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx">
    <div class="tlx-kpis">
      <div class="tlx-kpi"><b><?= count($items) ?></b><span>Contenuti programmati</span></div>
      <?php $next = $items ? $items[0] : null; ?>
      <div class="tlx-kpi"><b><?= $next ? e(formatLocalDateTime($next['scheduled_for'], $profile, 'd/m H:i')) : '—' ?></b><span>Prossima uscita</span></div>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_post.php"><b><i class="fa-solid fa-plus"></i></b><span>Nuovo aggiornamento</span></a>
      <a class="tlx-kpi tlx-kpi-link" href="/dashboard_social_queue.php"><b><i class="fa-solid fa-paper-plane"></i></b><span>Coda social</span></a>
    </div>
    <div class="tlx-listhead"><h2>Contenuti programmati <small>(<?= count($items) ?>)</small></h2></div>
    <?php if (!$items): ?><div class="tlx-empty"><i class="fa-regular fa-clock"></i><b>Nessun contenuto programmato</b><span>Quando imposti una data di pubblicazione nel futuro, il contenuto compare qui.</span></div><?php endif; ?>
    <?php foreach ($items as $it): ?>
      <article class="stc stc-list">
        <a class="stc-thumb" href="<?= e($it['edit_url']) ?>"><?php if ($it['cover']): ?><img src="/<?= e($it['cover']) ?>" alt="" loading="lazy"><?php else: ?><span class="stc-ph"><i class="fa-regular fa-clock"></i></span><?php endif; ?></a>
        <div class="stc-body">
          <div class="stc-meta1"><span class="stc-type stc-violet"><?= e($it['label']) ?></span><span class="stc-status stc-s-warn"><i class="fa-regular fa-clock"></i> <?= e(formatLocalDateTime($it['scheduled_for'], $profile)) ?></span></div>
          <h3 class="stc-title"><a href="<?= e($it['edit_url']) ?>"><?= e(textExcerpt($it['title'], 120)) ?></a></h3>
          <div class="stc-actions">
            <a class="stc-act primary" href="<?= e($it['edit_url']) ?>"><i class="fa-solid fa-pen"></i> <span>Modifica</span></a>
            <?php if ($it['preview_url']): ?>
              <a class="stc-act" href="<?= e($it['preview_url']) ?>" target="_blank" rel="noopener"><i class="fa-regular fa-eye"></i> <span>Anteprima</span></a>
              <button type="button" class="stc-act" data-copy="<?= e($it['preview_url']) ?>"><i class="fa-regular fa-copy"></i> <span>Copia link</span></button>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
