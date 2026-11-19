<?php
// Admin → Guide delle pagine: modifica i testi del popup "?" (breve) e dell'approfondimento di ogni pagina.
session_start();
require_once __DIR__ . '/../src/functions.php';
$admin = requireAdmin();
$activeAdminTab = 'page_help';
$pageTitle = 'Guide delle pagine';
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? 'save';
    $key = trim((string) ($_POST['page_key'] ?? ''));
    if (!preg_match('/^[a-z0-9_]{2,80}$/', $key)) {
        $error = 'Chiave pagina non valida: usa lettere minuscole, numeri e _ (es. dashboard_videos).';
    } elseif ($action === 'delete') {
        getDB()->prepare('DELETE FROM page_help WHERE page_key = ?')->execute([$key]);
        $success = 'Guida eliminata.';
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $more = trim((string) ($_POST['more'] ?? ''));
        $doc = trim((string) ($_POST['doc_url'] ?? ''));
        if ($title === '' || $body === '') {
            $error = 'Titolo e testo breve sono obbligatori.';
        } elseif ($doc !== '' && !preg_match('#^https?://#i', $doc) && !preg_match('#^/[^/]#', $doc)) {
            $error = 'Il link "Approfondisci" deve iniziare con https:// oppure con /.';
        } else {
            getDB()->prepare('INSERT INTO page_help (page_key, title, body, more, doc_url) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), more = VALUES(more), doc_url = VALUES(doc_url)')
                ->execute([$key, $title, $body, $more !== '' ? $more : null, $doc !== '' ? $doc : null]);
            $success = 'Guida salvata.';
        }
    }
}

$rows = pageHelpAll();
$edit = null;
if (isset($_GET['edit'])) {
    foreach ($rows as $r) {
        if ($r['page_key'] === $_GET['edit']) $edit = $r;
    }
}
include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p style="color:var(--text-muted)">
      Ogni pagina della dashboard mostra un <strong>?</strong> accanto al titolo: il <em>testo breve</em> compare nel popup.
      Se c'è un <em>approfondimento</em> (o un link esterno) il popup mostra anche "Approfondisci", che apre la pagina Guide.
      Nel testo: riga vuota = nuovo paragrafo, <code>**grassetto**</code>, <code>`codice`</code>.
      Segnaposto: <code>{site_name}</code>, <code>{slug}</code>, <code>{album_max_photos}</code>, <code>{service_max_photos}</code>, <code>{max_video_mb}</code>.
      La chiave è il nome del file della pagina senza .php (es. <code>dashboard_videos</code>).
    </p>
  </div>

  <form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <strong><?= $edit ? 'Modifica: ' . e($edit['title']) : 'Nuova guida' ?></strong>
    <label>Chiave pagina</label>
    <input type="text" name="page_key" value="<?= e($edit['page_key'] ?? '') ?>" <?= $edit ? 'readonly' : '' ?> placeholder="es. dashboard_videos" required>
    <label>Titolo</label>
    <input type="text" name="title" value="<?= e($edit['title'] ?? '') ?>" maxlength="160" required>
    <label>Testo breve (popup)</label>
    <textarea name="body" rows="5" required><?= e($edit['body'] ?? '') ?></textarea>
    <label>Approfondimento (pagina Guide, opzionale)</label>
    <textarea name="more" rows="6"><?= e($edit['more'] ?? '') ?></textarea>
    <label>Link "Approfondisci" esterno (opzionale, al posto della pagina Guide)</label>
    <input type="text" name="doc_url" value="<?= e($edit['doc_url'] ?? '') ?>" maxlength="255" placeholder="https://…">
    <button type="submit" class="btn">Salva</button>
    <?php if ($edit): ?><a href="/admin_page_help.php" class="btn secondary" style="margin-left:8px;">Nuova guida</a><?php endif; ?>
  </form>

  <div class="card">
    <strong>Guide esistenti (<?= count($rows) ?>)</strong>
    <table style="width:100%;border-collapse:collapse;margin-top:8px;">
      <?php foreach ($rows as $r): ?>
        <tr style="border-top:1px solid rgba(128,128,128,.25);">
          <td style="padding:6px 4px;"><a href="?edit=<?= e(rawurlencode($r['page_key'])) ?>"><strong><?= e($r['title']) ?></strong></a><br><small style="color:var(--text-muted)"><?= e($r['page_key']) ?><?= trim((string) $r['more']) !== '' || $r['doc_url'] ? ' · con approfondimento' : '' ?></small></td>
          <td style="text-align:right;white-space:nowrap;">
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare questa guida?');">
              <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="page_key" value="<?= e($r['page_key']) ?>">
              <button type="submit" class="btn secondary" style="padding:4px 10px;">Elimina</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php include __DIR__ . '/_admin_footer.php'; ?>
