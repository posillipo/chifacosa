<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/api_helpers.php';
$user = requireAdmin(); // strumento riservato agli amministratori del sito
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'api_tokens';
$pageTitle = 'API';
$error = null;
$newToken = null;
$boardSignError = null;
$newBoardSignature = null;

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
    } elseif ($action === 'board_sign_create') {
        $actor = apiNormalizeBoardActor((string) ($_POST['actor'] ?? ''));
        if ($actor === null) {
            $boardSignError = 'Nome non valido: 2-30 caratteri tra lettere minuscole, numeri, "-" e "_" (es. claude, grok, manus, direttore).';
        } else {
            // Chiave già esistente per questo attore: la sostituisce (rigenerazione), la vecchia
            // firma smette immediatamente di funzionare — stesso principio di "revoca e ricrea".
            $generated = generateBoardActorSignature();
            $stmt = getDB()->prepare('INSERT INTO board_actor_keys (user_id, actor, secret_hash) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE secret_hash = VALUES(secret_hash), created_at = CURRENT_TIMESTAMP');
            $stmt->execute([$profile['id'], $actor, $generated['hash']]);
            $newBoardSignature = ['actor' => $actor, 'signature' => $generated['signature']];
        }
    } elseif ($action === 'board_sign_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        getDB()->prepare('DELETE FROM board_actor_keys WHERE id = ? AND user_id = ?')->execute([$id, $profile['id']]);
    }
}

$stmt = getDB()->prepare('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$profile['id']]);
$tokens = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT * FROM board_actor_keys WHERE user_id = ? ORDER BY actor ASC');
$stmt->execute([$profile['id']]);
$boardActors = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT method, endpoint, status_code, created_at FROM api_request_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$profile['id']]);
$recentLogs = $stmt->fetchAll();

$apiBaseUrl = siteUrl('/api/v1/social-posts');
$boardApiBaseUrl = siteUrl('/api/v1/board');

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx">
    <details class="box apx-help">
      <summary><b>Come funziona</b></summary>
      <p>Crea un token per permettere a uno script o strumento esterno di pubblicare automaticamente sulla tua Timeline (titolo, testo, immagine, hashtag, data di programmazione). Il token si vede <b>una sola volta</b>: copialo subito. Se lo perdi, creane uno nuovo e revoca il vecchio.</p>
      <p>Limite: 100 richieste/ora per token. Ogni richiesta usa l'header <code>Authorization: Bearer IL_TUO_TOKEN</code>. Endpoint:</p>
      <ul class="box-list">
        <li><code>POST <?= e($apiBaseUrl) ?>/create</code></li>
        <li><code>GET <?= e($apiBaseUrl) ?>/list</code> (opzionali: <code>status</code>, <code>from</code>, <code>to</code>, <code>page</code>, <code>per_page</code>)</li>
        <li><code>GET <?= e($apiBaseUrl) ?>/{id}</code></li>
        <li><code>PUT <?= e($apiBaseUrl) ?>/{id}</code> (solo post in bozza o programmati)</li>
        <li><code>DELETE <?= e($apiBaseUrl) ?>/{id}</code></li>
      </ul>
    </details>

    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($newToken): ?>
      <div class="alert success"><b>Token creato!</b> Copialo ora, non verrà mostrato di nuovo:
        <div class="apx-secret" data-copy="<?= e($newToken) ?>"><?= e($newToken) ?></div></div>
    <?php endif; ?>

    <form method="post" class="box">
      <?= csrfField() ?><input type="hidden" name="action" value="create">
      <h3><i class="fa-solid fa-key"></i> Nuovo token</h3>
      <label>Nome del token <small>(per riconoscerlo, es. "n8n annunci settimanali")</small></label>
      <input type="text" name="label" required>
      <button type="submit" class="tlx-submit cmp-done">Crea token</button>
    </form>

    <div class="tlx-listhead"><h2>Token <small>(<?= count($tokens) ?>)</small></h2></div>
    <?php if (!$tokens): ?><div class="tlx-empty"><i class="fa-solid fa-key"></i><b>Nessun token creato ancora</b></div><?php endif; ?>
    <?php foreach ($tokens as $t): ?>
      <div class="pnx">
        <div class="pnx-b"><b><?= e($t['label']) ?></b><?php if (!$t['is_active']): ?> <span class="sqx-b" data-s="failed">revocato</span><?php endif; ?>
          <small><code><?= e($t['token_prefix']) ?></code> · creato il <?= date('d/m/Y', strtotime($t['created_at'])) ?><?= $t['last_used_at'] ? ' · ultimo uso ' . date('d/m/Y H:i', strtotime($t['last_used_at'])) : ' · mai usato' ?></small></div>
        <div class="pnx-act">
          <?php if ($t['is_active']): ?>
            <form method="post" data-confirm="Revocare questo token? Chi lo usa smetterà immediatamente di poter pubblicare."><?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="stc-act danger" type="submit"><i class="fa-solid fa-ban"></i> <span>Revoca</span></button></form>
          <?php else: ?>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="stc-act primary" type="submit"><i class="fa-solid fa-rotate-left"></i> <span>Riattiva</span></button></form>
            <form method="post" data-confirm="Eliminare definitivamente questo token?"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="stc-act danger" type="submit" title="Elimina"><i class="fa-solid fa-trash"></i></button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="tlx-listhead"><h2>Bacheca: firme per attore</h2></div>
    <details class="box apx-help">
      <summary><b>A cosa serve</b></summary>
      <p>La bacheca (<code>/api/v1/board/*</code>) è condivisa da più AI (Claude, Grok, Manus…) e da te come direttore: chi scrive un messaggio deve firmarlo con un segreto assegnato alla sua etichetta, altrimenti la richiesta viene rifiutata. Crea qui una firma per ogni attore e consegnala solo a lui: va nel campo <code>signature</code> di <code>POST <?= e($boardApiBaseUrl) ?>/create</code> (con <code>author</code>) e di <code>PUT <?= e($boardApiBaseUrl) ?>/{id}</code> (con <code>as</code>). Solo chi firma come <code>direttore</code> può impostare lo stato <code>approved</code>.</p>
    </details>
    <?php if ($boardSignError): ?><div class="alert error"><?= e($boardSignError) ?></div><?php endif; ?>
    <?php if ($newBoardSignature): ?>
      <div class="alert success"><b>Firma per "<?= e($newBoardSignature['actor']) ?>" creata!</b> Copiala ora e consegnala solo a lui, non verrà mostrata di nuovo:
        <div class="apx-secret" data-copy="<?= e($newBoardSignature['signature']) ?>"><?= e($newBoardSignature['signature']) ?></div></div>
    <?php endif; ?>
    <form method="post" class="box">
      <?= csrfField() ?><input type="hidden" name="action" value="board_sign_create">
      <h3><i class="fa-solid fa-signature"></i> Crea o rigenera una firma</h3>
      <label>Etichetta dell'attore <small>(es. claude, grok, manus, direttore)</small></label>
      <input type="text" name="actor" required>
      <button type="submit" class="tlx-submit cmp-done">Crea / rigenera firma</button>
    </form>
    <?php if (!$boardActors): ?><div class="tlx-empty"><i class="fa-solid fa-signature"></i><b>Nessuna firma ancora</b><span>Senza almeno una firma nessuno può scrivere in bacheca.</span></div><?php endif; ?>
    <?php foreach ($boardActors as $a): ?>
      <div class="pnx">
        <div class="pnx-b"><b><?= e($a['actor']) ?></b><?php if ($a['actor'] === 'direttore'): ?> <span class="sqx-b" data-s="scheduled">può approvare</span><?php endif; ?><small>creata il <?= date('d/m/Y', strtotime($a['created_at'])) ?></small></div>
        <form method="post" class="pnx-act" data-confirm="Eliminare la firma di <?= e($a['actor']) ?>? Non potrà più scrivere in bacheca finché non gliene crei una nuova."><?= csrfField() ?><input type="hidden" name="action" value="board_sign_delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="stc-act danger" type="submit"><i class="fa-solid fa-trash"></i> <span>Elimina</span></button></form>
      </div>
    <?php endforeach; ?>

    <div class="tlx-listhead"><h2>Ultime richieste ricevute</h2></div>
    <?php if (!$recentLogs): ?><div class="tlx-empty"><i class="fa-solid fa-inbox"></i><b>Nessuna richiesta ricevuta ancora</b></div>
    <?php else: foreach ($recentLogs as $log): ?>
      <div class="pnx"><div class="pnx-b"><span class="pnx-t"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></span><b><code><?= e($log['method']) ?> <?= e($log['endpoint']) ?></code></b></div><span class="sqx-b" data-s="<?= $log['status_code'] < 300 ? 'done' : 'failed' ?>"><?= (int) $log['status_code'] ?></span></div>
    <?php endforeach; endif; ?>
  </div>
<script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
