<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/telegram.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
requireBandOrLabel($profile);
$activeTab = 'telegram';
$pageTitle = 'Telegram';
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'link') {
        $input = trim($_POST['chat_id'] ?? '');
        if ($input === '') {
            $error = 'Indica il canale o il gruppo Telegram (es. @nomecanale o il link t.me/nomecanale).';
        } elseif (!getTelegramBotToken()) {
            $error = 'Telegram non è ancora configurato. Contatta l\'amministratore del sito.';
        } else {
            $chat = telegramResolveChat($input);
            if (!$chat) {
                $error = 'Non riesco ad accedere a questo canale/gruppo. Assicurati che il bot sia stato aggiunto come amministratore e riprova.';
            } else {
                $chatId = (string) $chat['id'];
                $chatTitle = $chat['title'] ?? $chat['username'] ?? $chatId;
                $chatUsername = $chat['username'] ?? null;
                $stmt = getDB()->prepare('UPDATE profiles SET telegram_chat_id=?, telegram_chat_title=? WHERE user_id=?');
                $stmt->execute([$chatId, $chatTitle, $profile['id']]);
                header('Location: /dashboard_telegram.php');
                exit;
            }
        }
    } elseif ($action === 'unlink') {
        $stmt = getDB()->prepare('UPDATE profiles SET telegram_chat_id=NULL, telegram_chat_title=NULL, telegram_auto_publish=0 WHERE user_id=?');
        $stmt->execute([$profile['id']]);
        header('Location: /dashboard_telegram.php');
        exit;
    } elseif ($action === 'toggle_auto') {
        $newVal = empty($profile['telegram_auto_publish']) ? 1 : 0;
        $stmt = getDB()->prepare('UPDATE profiles SET telegram_auto_publish=? WHERE user_id=?');
        $stmt->execute([$newVal, $profile['id']]);
        header('Location: /dashboard_telegram.php');
        exit;
    } elseif ($action === 'test_message') {
        if (empty($profile['telegram_chat_id'])) {
            $error = 'Collega prima un canale Telegram.';
        } else {
            $siteName = getSiteSetting('site_name') ?: 'CHI FA COSA';
            $result = telegramSendMessage(
                $profile['telegram_chat_id'],
                '✅ Test da <b>' . telegramEscapeHtml($profile['display_name']) . '</b> su ' . telegramEscapeHtml($siteName) . ' — la connessione funziona!'
            );
            if ($result) {
                $success = 'Messaggio di test inviato sul canale Telegram!';
            } else {
                $error = 'Invio fallito. Verifica che il bot sia ancora amministratore del canale.';
            }
        }
    }
}

$user = currentUser();
$profile = getActingProfile($user);

include __DIR__ . '/_dash_header.php';
?>
  <details class="help-box">
    <summary>ℹ️ Come funziona</summary>
    <p style="color:var(--text-muted)">
      Collega un canale o gruppo Telegram: ogni volta che pubblichi un post Timeline o un
      articolo Blog, il contenuto verrà inviato automaticamente anche lì — con immagine,
      anteprima e link alla pagina pubblica.
    </p>
    <p style="color:var(--text-muted)">
      <strong>Requisiti:</strong> il bot di <?= e(getSiteSetting('site_name') ?: 'CHI FA COSA') ?>
      deve essere aggiunto come <strong>amministratore</strong> nel canale/gruppo.
      L'amministratore del sito fornisce le istruzioni per il bot.
    </p>
  </details>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <?php if (!getTelegramBotToken()): ?>
    <div class="alert error">
      L'integrazione Telegram non è ancora attiva. L'amministratore del sito deve configurare
      il Bot Token nella sezione Admin → Telegram.
    </div>
  <?php elseif (!empty($profile['telegram_chat_id'])): ?>
    <div class="card">
      <strong>Canale collegato:</strong> <?= e($profile['telegram_chat_title'] ?: $profile['telegram_chat_id']) ?>
      <?php if ($profile['telegram_chat_id'] && preg_match('/^@/', $profile['telegram_chat_id'])): ?>
        <br><a href="https://t.me/<?= e(ltrim($profile['telegram_chat_id'], '@')) ?>" target="_blank">Apri su Telegram ↗</a>
      <?php endif; ?>
      <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle_auto">
          <button class="btn <?= empty($profile['telegram_auto_publish']) ? '' : 'secondary' ?>" type="submit">
            <?= empty($profile['telegram_auto_publish']) ? '🔔 Attiva pubblicazione automatica' : '🔕 Disattiva pubblicazione automatica' ?>
          </button>
        </form>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="test_message">
          <button class="btn secondary" type="submit">📩 Invia messaggio di test</button>
        </form>
        <form method="post" onsubmit="return confirm('Scollegare il canale Telegram? La pubblicazione automatica verrà disattivata.');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="unlink">
          <button class="btn danger" type="submit">Scollega canale</button>
        </form>
      </div>
    </div>

    <div class="card">
      <strong>Stato pubblicazione automatica:</strong>
      <?php if (!empty($profile['telegram_auto_publish'])): ?>
        <span style="color:#27ae60;font-weight:600;">Attiva</span> — i nuovi post Timeline e articoli Blog
        verranno inviati automaticamente al canale Telegram.
      <?php else: ?>
        <span style="color:var(--text-muted);font-weight:600;">Disattivata</span> — i contenuti non verranno
        inviati su Telegram. Puoi comunque inviare manualmente dalla pagina di modifica del post.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="link">
    <label><?= empty($profile['telegram_chat_id']) ? 'Collega il tuo canale Telegram' : 'Cambia canale collegato' ?></label>
    <input type="text" name="chat_id" placeholder="@nomecanale, t.me/nomecanale oppure ID numerico" required>
    <p style="color:var(--text-muted);font-size:13px;margin-top:-8px;">
      Inserisci l'username del canale (es. <code>@nomecanale</code>), il link (es. <code>t.me/nomecanale</code>)
      oppure l'ID numerico del gruppo/canale.
    </p>
    <button type="submit" class="btn">Collega canale</button>
  </form>
<?php include __DIR__ . '/_dash_footer.php'; ?>
