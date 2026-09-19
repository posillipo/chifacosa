<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/api_helpers.php'; // generateApiToken()
$admin = requireAdmin();
$activeAdminTab = 'mcp';
$pageTitle = 'Server MCP';
$error = null;
$success = null;

// Chiamata generica al server MCP (endpoint /admin/profiles) — server-to-server via cURL, mai
// dal browser: il token MCP_ACCESS_TOKEN e i token dei singoli profili non passano mai per il
// client di chi sta usando questa pagina, restano sempre tra questo server e quello MCP.
function mcpServerRequest(string $method, string $path, ?array $body = null): array {
    $baseUrl = rtrim(getSiteSetting('mcp_server_url') ?: '', '/');
    $token = getSiteSetting('mcp_access_token') ?: '';
    if ($baseUrl === '' || $token === '') {
        return ['ok' => false, 'error' => 'Server MCP non ancora configurato (URL o token mancante qui sopra).'];
    }
    $headers = ['Authorization: Bearer ' . $token];
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Errore di connessione al server MCP: ' . $curlErr];
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => "Risposta non valida dal server MCP (HTTP {$status})."];
    }
    if ($status >= 400 || ($data['success'] ?? true) === false) {
        return ['ok' => false, 'error' => $data['error'] ?? "Errore HTTP {$status} dal server MCP."];
    }
    return ['ok' => true, 'data' => $data];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $url = trim($_POST['mcp_server_url'] ?? '');
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $error = 'L\'URL del server MCP non è valido.';
        } else {
            setSiteSetting('mcp_server_url', rtrim($url, '/'));
            $newToken = trim($_POST['mcp_access_token'] ?? '');
            if ($newToken !== '') {
                setSiteSetting('mcp_access_token', $newToken);
            }
            $success = 'Configurazione salvata.';
        }
    } elseif ($action === 'link_profile') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $mcpName = trim($_POST['mcp_name'] ?? '');

        $stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ? AND u.is_active = 1');
        $stmt->execute([$targetUserId]);
        $targetProfile = $stmt->fetch();

        if (!$targetProfile) {
            $error = 'Profilo non valido.';
        } elseif ($mcpName === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $mcpName)) {
            $error = 'Il nome per MCP può contenere solo lettere, numeri, trattini e underscore (niente spazi).';
        } else {
            $stmt = getDB()->prepare('SELECT id FROM api_tokens WHERE mcp_name = ? AND is_active = 1');
            $stmt->execute([$mcpName]);
            if ($stmt->fetch()) {
                $error = "Il nome \"{$mcpName}\" è già usato da un altro collegamento MCP attivo — scegline un altro.";
            } else {
                $generated = generateApiToken($targetProfile['slug']);
                $pushResult = mcpServerRequest('POST', '/admin/profiles', ['name' => $mcpName, 'token' => $generated['token']]);
                if (!$pushResult['ok']) {
                    $error = 'Non sono riuscito a registrare il profilo sul server MCP: ' . $pushResult['error'];
                } else {
                    $stmt = getDB()->prepare('INSERT INTO api_tokens (user_id, label, token_hash, token_prefix, mcp_name) VALUES (?,?,?,?,?)');
                    $stmt->execute([$targetProfile['id'], 'MCP: ' . $mcpName, $generated['hash'], $generated['prefix'], $mcpName]);
                    $success = "Profilo \"{$targetProfile['display_name']}\" collegato a MCP con il nome \"{$mcpName}\".";
                }
            }
        }
    } elseif ($action === 'unlink') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT mcp_name FROM api_tokens WHERE id = ? AND mcp_name IS NOT NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            // Best-effort: anche se il server MCP non risponde (es. già spento/irraggiungibile),
            // rimuoviamo comunque il collegamento da questa parte — non deve restare bloccato
            // qui solo perché l'altro lato non risponde.
            $mcpResult = mcpServerRequest('DELETE', '/admin/profiles/' . rawurlencode($row['mcp_name']));
            getDB()->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$id]);
            $success = 'Collegamento rimosso' . (!$mcpResult['ok'] ? ' (il server MCP non ha confermato: ' . $mcpResult['error'] . ' — se il server è raggiungibile controlla manualmente con GET /admin/profiles)' : '.');
        }
    }
}

$configuredUrl = getSiteSetting('mcp_server_url') ?: '';
$configuredToken = getSiteSetting('mcp_access_token') ?: '';

$stmt = getDB()->prepare('SELECT at.id, at.label, at.token_prefix, at.mcp_name, at.created_at, at.last_used_at, u.slug, p.display_name
                          FROM api_tokens at JOIN users u ON u.id = at.user_id JOIN profiles p ON p.user_id = u.id
                          WHERE at.mcp_name IS NOT NULL AND at.is_active = 1
                          ORDER BY at.created_at DESC');
$stmt->execute();
$linkedProfiles = $stmt->fetchAll();

$stmt = getDB()->prepare('SELECT u.id, u.slug, p.display_name FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.is_active = 1 ORDER BY p.display_name ASC');
$stmt->execute();
$allProfiles = $stmt->fetchAll();

// Elenco "live" direttamente dal server MCP, se configurato — utile per accorgersi di
// disallineamenti (es. un profilo rimosso a mano da terminale, o un DELETE fallito qui sopra
// che ha comunque cancellato la riga locale).
$liveProfiles = null;
if ($configuredUrl !== '' && $configuredToken !== '') {
    $liveResult = mcpServerRequest('GET', '/admin/profiles');
    if ($liveResult['ok']) {
        $liveProfiles = $liveResult['data']['profiles'] ?? [];
    }
}

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

  <div class="card">
    <div class="card-body">
      <strong>Come funziona</strong>
      <p class="text-secondary mb-0">
        Il server MCP (deployato separatamente — vedi <code>mcp-server/DEPLOY_MCP.md</code> nel
        repository) permette a Claude di pubblicare/gestire contenuti su uno o più profili di
        questo sito. Da questa pagina colleghi un profilo qualsiasi (non serve accedere con il
        suo account) scegliendogli un nome: il token viene generato e inviato al server MCP
        automaticamente, senza bisogno di terminale.
      </p>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Configurazione server MCP</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_config">
        <div class="form-group">
          <label>URL del server MCP</label>
          <input type="text" name="mcp_server_url" class="form-control" value="<?= e($configuredUrl) ?>" placeholder="https://mcp.chifacosa.it">
        </div>
        <div class="form-group">
          <label>Token di accesso al server MCP (MCP_ACCESS_TOKEN)</label>
          <input type="password" name="mcp_access_token" class="form-control" placeholder="<?= $configuredToken !== '' ? '••••••••  (lascia vuoto per non modificarlo)' : 'incolla qui il token' ?>">
        </div>
        <button type="submit" class="btn btn-primary">Salva configurazione</button>
      </form>
    </div>
  </div>

  <?php if ($configuredUrl !== '' && $configuredToken !== ''): ?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Collega un profilo</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="link_profile">
        <div class="form-group">
          <label>Profilo</label>
          <select name="user_id" class="form-control" required>
            <option value="">— scegli un profilo —</option>
            <?php foreach ($allProfiles as $p): ?>
              <option value="<?= (int) $p['id'] ?>"><?= e($p['display_name']) ?> (@<?= e($p['slug']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Nome per MCP (solo lettere/numeri/trattini, es. lo slug del profilo)</label>
          <input type="text" name="mcp_name" class="form-control" placeholder="es. bandmarione" required pattern="[a-zA-Z0-9_-]+">
        </div>
        <button type="submit" class="btn btn-primary">Crea token e collega</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Profili collegati (<?= count($linkedProfiles) ?>)</h3></div>
    <div class="card-body p-0">
      <?php if (!$linkedProfiles): ?>
        <p class="text-secondary p-3 mb-0">Nessun profilo collegato ancora.</p>
      <?php else: ?>
        <table class="table mb-0">
          <thead><tr><th>Nome MCP</th><th>Profilo</th><th>Token</th><th>Creato</th><th>Ultimo uso</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($linkedProfiles as $lp): ?>
            <tr>
              <td><code><?= e($lp['mcp_name']) ?></code></td>
              <td><?= e($lp['display_name']) ?> (@<?= e($lp['slug']) ?>)</td>
              <td><small class="text-secondary"><?= e($lp['token_prefix']) ?></small></td>
              <td><?= date('d/m/Y', strtotime($lp['created_at'])) ?></td>
              <td><?= $lp['last_used_at'] ? date('d/m/Y H:i', strtotime($lp['last_used_at'])) : 'mai usato' ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Rimuovere questo collegamento? Chi lo usa via MCP non potrà più agire su questo profilo.');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="unlink">
                  <input type="hidden" name="id" value="<?= (int) $lp['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Rimuovi</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($liveProfiles !== null): ?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Stato reale sul server MCP</h3></div>
    <div class="card-body">
      <p class="text-secondary">Elenco letto ora direttamente dal server (<code>GET /admin/profiles</code>) — utile per accorgersi di eventuali disallineamenti con la tabella qui sopra.</p>
      <?php if (!$liveProfiles): ?>
        <p class="mb-0">Nessun profilo registrato sul server.</p>
      <?php else: ?>
        <p class="mb-0"><?php foreach ($liveProfiles as $i => $n): ?><code><?= e($n) ?></code><?= $i < count($liveProfiles) - 1 ? ', ' : '' ?><?php endforeach; ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php include __DIR__ . '/_admin_footer.php'; ?>
