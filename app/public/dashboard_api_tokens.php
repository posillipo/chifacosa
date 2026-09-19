<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'api_tokens';
$pageTitle = 'API';
$error = null;
$newToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        if ($label === '') {
            $error = 'Dai un nome al token (es. "Script pubblicazione automatica").';
        } else {
            $generated = generateApiToken($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO api_tokens (user_id, label, token_hash, token_prefix) VALUES (?,?,?,?)');
            $stmt->execute([$profile['id'], $label, $generated['hash'], $generated['prefix']]);
            $newToken = $generated['token'];
        }
    } elseif ($action === 'revoke') {
        $id = (int) ($_POST['id'] ?? 0);
        getDB()->prepare('UPDATE api_tokens SET is_active = 0 WHERE id = ? AND user_id = ?')->execute([$id, $profile['id']]);
    } elseif ($action === 'reactivate') {
        $id = (int) ($_POST['id'] ?? 0);
        getDB()->prepare('UPDATE api_tokens SET is_active = 1 WHERE id = ? AND user_id = ?')->execute([$id, $profile['id']]);
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        getDB()->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?')->execute([$id, $profile['id']]);
    }
}

$stmt = getDB()->prepare('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$profile['id']]);
$tokens = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT method, endpoint, status_code, created_at FROM api_request_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$profile['id']]);
$recentLogs = $stmt->fetchAll();

$apiBaseUrl = siteUrl('/api/v1/social-posts');

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Crea un token per permettere a uno script o strumento esterno di pubblicare automaticamente
      sulla tua Timeline (come un aggiornamento creato da qui, con titolo, testo, immagine,
      hashtag e data di programmazione). Il token va mostrato solo una volta: copialo subito dopo
      averlo creato, perché non potrai più rivederlo — se lo perdi, creane uno nuovo e revoca il
      vecchio.
    </p>
    <p style="color:var(--text-muted)">
      Limite: 100 richieste/ora per token. Endpoint disponibili:
    </p>
    <ul style="color:var(--text-muted);font-size:13.5px;">
      <li><code>POST <?= e($apiBaseUrl) ?>/create</code></li>
      <li><code>GET <?= e($apiBaseUrl) ?>/list</code> (parametri opzionali: <code>status</code>, <code>from</code>, <code>to</code>, <code>page</code>, <code>per_page</code>)</li>
      <li><code>GET <?= e($apiBaseUrl) ?>/{id}</code></li>
      <li><code>PUT <?= e($apiBaseUrl) ?>/{id}</code> (solo su post ancora in bozza o programmati)</li>
      <li><code>DELETE <?= e($apiBaseUrl) ?>/{id}</code></li>
    </ul>
    <p style="color:var(--text-muted)">
      Ogni richiesta va autenticata con l'header <code>Authorization: Bearer IL_TUO_TOKEN</code>.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($newToken): ?>
    <div class="alert success">
      <strong>Token creato!</strong> Copialo ora, non verrà mostrato di nuovo:
      <div style="background:rgba(0,0,0,0.3);padding:10px;border-radius:8px;margin-top:8px;font-family:monospace;font-size:13px;word-break:break-all;user-select:all;"><?= e($newToken) ?></div>
    </div>
  <?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <label>Nome del token (per riconoscerlo tu, es. "n8n annunci settimanali")</label>
    <div style="display:flex;gap:8px;">
      <input type="text" name="label" required style="flex:1;margin-bottom:0;">
      <button type="submit" class="btn" style="width:auto;">Crea token</button>
    </div>
  </form>

  <div class="section-title">Token attivi (<?= count($tokens) ?>)</div>
  <?php if (!$tokens): ?>
    <div class="card">Nessun token creato ancora.</div>
  <?php endif; ?>
  <?php foreach ($tokens as $t): ?>
    <div class="link-item">
      <div>
        <strong><?= e($t['label']) ?></strong>
        <?php if (!$t['is_active']): ?><span style="color:#ff8a8a;font-size:12px;"> · revocato</span><?php endif; ?>
        <br><small style="color:var(--text-muted);font-family:monospace;"><?= e($t['token_prefix']) ?></small>
        <br><small style="color:var(--text-muted)">
          creato il <?= date('d/m/Y', strtotime($t['created_at'])) ?>
          <?= $t['last_used_at'] ? ' · ultimo uso ' . date('d/m/Y H:i', strtotime($t['last_used_at'])) : ' · mai usato' ?>
        </small>
      </div>
      <div class="icon-btn-group">
        <?php if ($t['is_active']): ?>
          <form method="post" onsubmit="return confirm('Revocare questo token? Chi lo usa smetterà immediatamente di poter pubblicare.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn small danger" type="submit">Revoca</button>
          </form>
        <?php else: ?>
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn small" type="submit">Riattiva</button>
          </form>
          <form method="post" onsubmit="return confirm('Eliminare definitivamente questo token?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn small danger" type="submit">Elimina</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="section-title">Ultime richieste ricevute</div>
  <?php if (!$recentLogs): ?>
    <div class="card">Nessuna richiesta ricevuta ancora.</div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($recentLogs as $log): ?>
        <div style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.08);font-size:13px;font-family:monospace;">
          <span><?= e($log['method']) ?> <?= e($log['endpoint']) ?></span>
          <span style="color:<?= $log['status_code'] < 300 ? '#5cb85c' : '#ff8a8a' ?>;"><?= (int) $log['status_code'] ?></span>
          <span style="color:var(--text-muted)"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
