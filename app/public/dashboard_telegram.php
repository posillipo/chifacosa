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

    if ($action === 'generate_code') {
        if (!getTelegramBotToken()) {
            $error = 'Telegram non è ancora configurato. Contatta l\'amministratore del sito.';
        } else {
            $code = telegramGenerateLinkCode((int) $profile['id']);
            // Ricarica il profilo per mostrare il codice
            $user = currentUser();
            $profile = getActingProfile($user);
        }
    } elseif ($action === 'unlink_user') {
        $stmt = getDB()->prepare('UPDATE profiles SET telegram_user_id=NULL WHERE user_id=?');
        $stmt->execute([$profile['id']]);
        $success = 'Account Telegram scollegato.';
        $user = currentUser();
        $profile = getActingProfile($user);
    } elseif ($action === 'link') {
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

// Recupera il bot username per il link
$botUsername = null;
try {
    if (getTelegramBotToken()) {
        $me = telegramGetMe();
        $botUsername = $me['username'] ?? null;
    }
} catch (\Throwable $e) {
    // Ignora errori API
}

include __DIR__ . '/_dash_header.php';
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>

  <?php if (!getTelegramBotToken()): ?>
    <div class="alert error">
      L'integrazione Telegram non è ancora attiva. L'amministratore del sito deve configurare
      il Bot Token nella sezione Admin → Telegram.
    </div>
  <?php else: ?>

    <!-- ═══ SEZIONE 1: Pubblica da Telegram ═══ -->
    <div class="section-title">📲 Pubblica da Telegram</div>

    <details class="help-box">
      <summary>ℹ️ Come funziona</summary>
      <p style="color:var(--text-muted)">
        Collega il tuo account Telegram al profilo: dopo potrai inviare una foto (con o senza testo)
        direttamente al bot in chat privata. Il bot ti chiederà se pubblicarla sulla
        <strong>Timeline</strong> o sul <strong>Blog</strong>, e creerà il post automaticamente.
      </p>
    </details>

    <?php if (!empty($profile['telegram_user_id'])): ?>
      <div class="card">
        <strong>✅ Account Telegram collegato</strong>
        <p style="color:var(--text-muted)">
          Invia una foto<?php if ($botUsername): ?> a <a href="https://t.me/<?= e($botUsername) ?>" target="_blank">@<?= e($botUsername) ?></a><?php endif; ?>
          in chat privata. Il bot ti chiederà dove pubblicarla.
        </p>
        <form method="post" onsubmit="return confirm('Scollegare il tuo account Telegram personale?');" style="margin-top:8px;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="unlink_user">
          <button class="btn danger small" type="submit">Scollega account</button>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <?php
        $linkCode = $profile['telegram_link_code'] ?? null;
        $linkExpires = $profile['telegram_link_expires'] ?? null;
        $codeValid = $linkCode && $linkExpires && strtotime($linkExpires) > time();
        ?>
        <?php if ($codeValid): ?>
          <strong>Il tuo codice di collegamento:</strong>
          <div style="margin:12px 0;padding:14px;background:var(--bg-alt,#f0f0f0);border-radius:8px;text-align:center;">
            <code style="font-size:1.4em;letter-spacing:2px;font-weight:bold;"><?= e($linkCode) ?></code>
          </div>
          <p style="color:var(--text-muted)">
            1. Apri Telegram e cerca <?php if ($botUsername): ?><a href="https://t.me/<?= e($botUsername) ?>" target="_blank">@<?= e($botUsername) ?></a><?php else: ?>il bot<?php endif; ?><br>
            2. Invia questo messaggio al bot:<br>
            <code>/start <?= e($linkCode) ?></code>
          </p>
          <p style="color:var(--text-muted);font-size:12.5px;">Il codice scade tra 10 minuti.</p>
          <form method="post" style="margin-top:6px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="generate_code">
            <button class="btn secondary small" type="submit">Genera nuovo codice</button>
          </form>
        <?php else: ?>
          <p>Collega il tuo account Telegram per poter pubblicare foto e testi inviandoli al bot.</p>
          <form method="post" style="margin-top:8px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="generate_code">
            <button class="btn" type="submit">Genera codice di collegamento</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- ═══ SEZIONE 2: Canale per pubblicazione automatica ═══ -->
    <div class="section-title" style="margin-top:28px;">📢 Canale Telegram (pubblicazione automatica)</div>

    <details class="help-box">
      <summary>ℹ️ Come funziona</summary>
      <p style="color:var(--text-muted)">
        Collega un canale o gruppo Telegram: ogni volta che pubblichi un post Timeline o un
        articolo Blog dal sito, il contenuto verrà inviato automaticamente anche lì.
      </p>
    </details>

    <?php if (!empty($profile['telegram_chat_id'])): ?>
      <div class="card">
        <strong>Canale collegato:</strong> <?= e($profile['telegram_chat_title'] ?: $profile['telegram_chat_id']) ?>
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
      <label><?= empty($profile['telegram_chat_id']) ? 'Collega un canale Telegram' : 'Cambia canale collegato' ?></label>
      <input type="text" name="chat_id" placeholder="@nomecanale, t.me/nomecanale oppure ID numerico" required>
      <p style="color:var(--text-muted);font-size:13px;margin-top:-8px;">
        Il bot deve essere aggiunto come <strong>amministratore</strong> nel canale prima di collegarlo.
      </p>
      <button type="submit" class="btn">Collega canale</button>
    </form>

  <?php endif; ?>
<?php include __DIR__ . '/_dash_footer.php'; ?>
