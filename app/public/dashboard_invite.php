<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
$user = requireLogin();
$profile = getActingProfile($user);
$activeTab = 'invite';
$pageTitle = 'Invita';

$inviteLink = siteUrl('/register.php?ref=' . $profile['slug']);

$stmt = getDB()->prepare("SELECT COUNT(*) c FROM access_requests WHERE referrer_user_id = ? AND invite_used = 1");
$stmt->execute([$profile['id']]);
$totalJoined = (int) $stmt->fetch()['c'];

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Condividi il tuo link personale con chi vuoi invitare su <?= e(siteName()) ?> — chi lo apre
      può registrarsi subito, nessuna approvazione necessaria. Appena completa la registrazione,
      inizierete a seguirvi a vicenda automaticamente.
    </p>
  </details>

  <div class="card">
    <strong>Il tuo link di invito</strong>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
      <input type="text" readonly value="<?= e($inviteLink) ?>" id="invite-link-input" style="flex:1;min-width:200px;">
      <button type="button" class="btn small" onclick="navigator.clipboard.writeText(document.getElementById('invite-link-input').value); this.textContent='Copiato!'; setTimeout(() => this.textContent='Copia', 1500);">Copia</button>
    </div>
  </div>

  <div class="card">
    <strong><?= $totalJoined ?></strong> persone si sono iscritte grazie al tuo link
  </div>
<?php include __DIR__ . '/_dash_footer.php'; ?>
