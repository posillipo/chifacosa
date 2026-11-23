<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: logica identica alla pagina originale, cambia solo la parte visiva */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/telegram.php';
$user = requireAdmin(); // strumento riservato agli amministratori del sito
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
            telegramGenerateLinkCode((int) $profile['id']);
            header('Location: /dashboard_telegram.php');
            exit;
        }
    } elseif ($action === 'unlink_user') {
        $stmt = getDB()->prepare('UPDATE profiles SET telegram_user_id=NULL WHERE user_id=?');
        $stmt->execute([$profile['id']]);
        $success = 'Account Telegram scollegato.';
        $user = currentUser(true);
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

$user = currentUser(true);
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

include __DIR__ . '/../_dash_header.php';
?>
  <div class="tlx tlx-narrow">
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if (!getTelegramBotToken()): ?>
      <div class="tlx-empty"><i class="fa-brands fa-telegram"></i><b>Telegram non è ancora attivo</b><span>L'amministratore del sito deve configurare il Bot Token in Admin → Telegram.</span></div>
    <?php else: ?>
      <div class="tlx-listhead"><h2>Pubblica da Telegram</h2></div>
      <?php if (!empty($profile['telegram_user_id'])): ?>
        <div class="box">
          <h3><i class="fa-solid fa-circle-check"></i> Account Telegram collegato</h3>
          <p>Invia una foto<?php if ($botUsername): ?> a <a href="https://t.me/<?= e($botUsername) ?>" target="_blank" rel="noopener">@<?= e($botUsername) ?></a><?php endif; ?> in chat privata. Il bot ti chiederà dove pubblicarla.</p>
          <form method="post" data-confirm="Scollegare il tuo account Telegram personale?"><?= csrfField() ?><input type="hidden" name="action" value="unlink_user"><button class="stc-act danger" type="submit"><i class="fa-solid fa-link-slash"></i> <span>Scollega account</span></button></form>
        </div>
      <?php else: ?>
        <?php $linkCode = $profile['telegram_link_code'] ?? null; $linkExpires = $profile['telegram_link_expires'] ?? null; $codeValid = $linkCode && $linkExpires && strtotime($linkExpires) > time(); ?>
        <div class="box">
          <?php if ($codeValid): ?>
            <h3>Il tuo codice di collegamento</h3>
            <div class="tgx-code" data-copy="/start <?= e($linkCode) ?>"><code><?= e($linkCode) ?></code></div>
            <p>1. Apri Telegram e cerca <?php if ($botUsername): ?><a href="https://t.me/<?= e($botUsername) ?>" target="_blank" rel="noopener">@<?= e($botUsername) ?></a><?php else: ?>il bot<?php endif; ?><br>2. Invia al bot questo messaggio: <code>/start <?= e($linkCode) ?></code></p>
            <p class="tlx-hint">Il codice scade tra 10 minuti.</p>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="generate_code"><button class="stc-act" type="submit"><i class="fa-solid fa-rotate"></i> <span>Genera nuovo codice</span></button></form>
          <?php else: ?>
            <h3><i class="fa-brands fa-telegram"></i> Collega il tuo account</h3>
            <p>Così puoi pubblicare foto e testi inviandoli al bot.</p>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="generate_code"><button class="tlx-submit cmp-done" type="submit">Genera codice di collegamento</button></form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="tlx-listhead"><h2>Canale per la pubblicazione automatica</h2></div>
      <div class="box"><p>Collega un canale o gruppo Telegram: ogni post Timeline o articolo Blog che pubblichi dal sito verrà inviato automaticamente anche lì.</p></div>
      <?php if (!empty($profile['telegram_chat_id'])): ?>
        <div class="box">
          <h3><i class="fa-solid fa-bullhorn"></i> <?= e($profile['telegram_chat_title'] ?: $profile['telegram_chat_id']) ?></h3>
          <p>Pubblicazione automatica: <b><?= !empty($profile['telegram_auto_publish']) ? 'attiva' : 'disattivata' ?></b>.<?= !empty($profile['telegram_auto_publish']) ? ' I nuovi post e articoli vanno anche sul canale.' : ' Puoi comunque inviare a mano dalla pagina di modifica del post.' ?></p>
          <div class="box-row">
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="toggle_auto"><button class="stc-act primary" type="submit"><i class="fa-solid <?= empty($profile['telegram_auto_publish']) ? 'fa-bell' : 'fa-bell-slash' ?>"></i> <span><?= empty($profile['telegram_auto_publish']) ? 'Attiva automatica' : 'Disattiva automatica' ?></span></button></form>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="test_message"><button class="stc-act" type="submit"><i class="fa-regular fa-paper-plane"></i> <span>Messaggio di test</span></button></form>
            <form method="post" data-confirm="Scollegare il canale Telegram? La pubblicazione automatica verrà disattivata."><?= csrfField() ?><input type="hidden" name="action" value="unlink"><button class="stc-act danger" type="submit"><i class="fa-solid fa-link-slash"></i> <span>Scollega</span></button></form>
          </div>
        </div>
      <?php endif; ?>
      <form method="post" class="box">
        <?= csrfField() ?><input type="hidden" name="action" value="link">
        <h3><?= empty($profile['telegram_chat_id']) ? 'Collega un canale Telegram' : 'Cambia canale collegato' ?></h3>
        <input type="text" name="chat_id" placeholder="@nomecanale, t.me/nomecanale oppure ID numerico" required>
        <p class="tlx-hint">Il bot deve essere aggiunto come <b>amministratore</b> nel canale prima di collegarlo.</p>
        <button type="submit" class="tlx-submit cmp-done">Collega canale</button>
      </form>
    <?php endif; ?>
  </div>
<script src="/studio/assets/studio-kit.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
