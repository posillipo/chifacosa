<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'schedule';
$pageTitle = 'Programmati';

$items = getScheduledContentForUser((int) $profile['id'], $profile['slug']);

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Tutti i contenuti che hai programmato per una data futura — Timeline, Blog, ogni modulo
      Che Amo, Offerte, Album, Servizi — raccolti qui in un unico calendario, dal più vicino al
      più lontano. Da "Modifica" apri direttamente la pagina di gestione di quell'elemento per
      cambiare testo o data. Gli Eventi non compaiono: la loro data è quando si terranno, non
      una programmazione di pubblicazione.
    </p>
    <p style="color:var(--text-muted)">
      Con "Copia link anteprima" ottieni un link riservato alla pagina pubblica di quel contenuto,
      valido anche se non è ancora pubblico — utile per controllarlo prima della pubblicazione o
      per incollarlo nel <a href="https://developers.facebook.com/tools/debug/" target="_blank" rel="noopener">Tool di Meta per le Anteprime social (Sharing Debugger)</a>.
    </p>
  </details>

  <div class="section-title">Contenuti programmati (<?= count($items) ?>)</div>
  <?php if (!$items): ?>
    <div class="alert error">Nessun contenuto programmato al momento.</div>
  <?php else: ?>
    <?php foreach ($items as $it): ?>
      <div class="card" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;border:1px solid #f0ad4e;">
        <?php if ($it['cover']): ?>
          <img src="/<?= e($it['cover']) ?>" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;">
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:4px;">
            <span style="background:#f0ad4e;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;white-space:nowrap;">
              ⏰ <?= e(formatLocalDateTime($it['scheduled_for'], $profile)) ?>
            </span>
            <span style="color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.3px;white-space:nowrap;"><?= e($it['label']) ?></span>
          </div>
          <p style="margin:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e(textExcerpt($it['title'], 120)) ?></p>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0;margin-top:4px;">
          <?php if ($it['preview_url']): ?>
            <button type="button" class="btn small secondary schedule-preview-copy" data-url="<?= e($it['preview_url']) ?>">🔗 Copia link anteprima</button>
          <?php endif; ?>
          <a href="<?= e($it['edit_url']) ?>" class="btn small secondary">Modifica</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<script>
document.querySelectorAll('.schedule-preview-copy').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var url = btn.getAttribute('data-url');
    navigator.clipboard.writeText(url).then(function () {
      var original = btn.textContent;
      btn.textContent = '✅ Copiato!';
      setTimeout(function () { btn.textContent = original; }, 1800);
    }).catch(function () {
      window.prompt('Copia questo link:', url);
    });
  });
});
</script>
<?php include __DIR__ . '/_dash_footer.php'; ?>
