<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/theme_access.php';
$admin = requireAdmin();
$activeAdminTab = 'themes';
$pageTitle = 'Temi grafici';
$success = null;
$error = null;
$warnings = [];

// Utenti indicati per slug o email -> id. Ritorna [ids, non_trovati].
function themeResolveUsers(string $raw): array {
    $tokens = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', mb_strtolower($raw))), fn ($t) => $t !== '')));
    $ids = [];
    $missing = [];
    $stmt = getDB()->prepare('SELECT id FROM users WHERE LOWER(slug) = ? OR LOWER(email) = ? LIMIT 1');
    foreach (array_slice($tokens, 0, 200) as $t) {
        $stmt->execute([ltrim($t, '@'), $t]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $ids[] = (int) $id;
        } else {
            $missing[] = $t;
        }
    }
    return [$ids, $missing];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    try {
        $changed = 0;
        foreach (PAGE_THEMES as $key => $theme) {
            $mode = $_POST['mode'][$key] ?? 'all';
            if (!array_key_exists($mode, THEME_VISIBILITY_MODES)) {
                $mode = 'all';
            }
            $users = [];
            if ($mode === 'selected') {
                [$users, $missing] = themeResolveUsers((string) ($_POST['users'][$key] ?? ''));
                foreach ($missing as $m) {
                    $warnings[] = $theme['label'] . ': utente "' . $m . '" non trovato (ignorato).';
                }
            }
            $before = themeVisibilityRules()[$key] ?? ['mode' => 'all', 'users' => []];
            $sameUsers = $mode !== 'selected' || (array_diff($users, $before['users']) === [] && array_diff($before['users'], $users) === []);
            if ($before['mode'] === $mode && $sameUsers) {
                continue;
            }
            themeVisibilitySave($key, $mode, $users);
            $changed++;
        }
        themeVisibilityRules(true);
        $success = $changed ? 'Visibilità aggiornata per ' . $changed . ' temi.' : 'Nessuna modifica.';
    } catch (Throwable $e) {
        error_log('admin_themes: ' . $e->getMessage());
        $error = 'Non è stato possibile salvare. Le tabelle potrebbero mancare: applicare la migrazione 2026_11_23_theme_visibility.sql.';
    }
}

$rules = themeVisibilityRules(true);
// Quanti profili usano ciascun tema, e gli slug degli utenti ammessi.
$usage = [];
try {
    foreach (getDB()->query('SELECT page_theme, COUNT(*) n FROM profiles GROUP BY page_theme')->fetchAll() as $r) {
        $usage[$r['page_theme'] ?: 'colorful'] = (int) $r['n'];
    }
} catch (Throwable $e) {
}
$allowedSlugs = [];
try {
    foreach (getDB()->query('SELECT v.theme_key, u.slug FROM theme_visibility_users v JOIN users u ON u.id = v.user_id ORDER BY u.slug')->fetchAll() as $r) {
        $allowedSlugs[$r['theme_key']][] = $r['slug'];
    }
} catch (Throwable $e) {
}
$limited = 0;
foreach ($rules as $r) {
    $limited += $r['mode'] !== 'all' ? 1 : 0;
}

include __DIR__ . '/_admin_header.php';
?>
  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php foreach ($warnings as $w): ?><div class="alert error"><?= e($w) ?></div><?php endforeach; ?>

  <div class="card">
    <strong>Come funziona</strong>
    <p class="text-muted" style="margin:.5rem 0 0;">
      Per ogni tema grafico decidi <strong>chi può sceglierlo</strong> dalla propria pagina "Tema grafico":
      <strong>tutti</strong> (predefinito), <strong>solo amministratori</strong>, oppure <strong>amministratori + utenti scelti</strong>
      (scrivi gli utenti per <em>slug</em> o <em>email</em>, uno per riga o separati da virgola). Gli amministratori possono usare
      sempre tutti i temi. Un limite riguarda solo la <em>scelta</em>: chi sta già usando un tema lo mantiene e la sua pagina pubblica
      non cambia. Vale per tutti i siti che condividono questo codice (chifacosa e myband), ciascuno con il proprio database.
    </p>
  </div>

  <form method="post">
    <?= csrfField() ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:.75rem;">
      <input type="search" id="theme-q" class="form-control" style="max-width:260px;" placeholder="Cerca un tema…" aria-label="Cerca un tema">
      <label style="margin:0;font-weight:400;"><input type="checkbox" id="theme-only-limited"> Solo temi limitati (<?= (int) $limited ?>)</label>
      <button type="submit" class="btn" style="margin-left:auto;">Salva visibilità</button>
    </div>

    <div class="card" style="padding:0;overflow-x:auto;">
      <table class="table" style="margin:0;">
        <thead><tr><th>Tema</th><th style="width:70px;" title="Profili che lo usano adesso">Usato da</th><th style="width:290px;">Chi può usarlo</th><th>Utenti scelti</th></tr></thead>
        <tbody>
        <?php foreach (PAGE_THEMES as $key => $theme):
          $rule = $rules[$key] ?? ['mode' => 'all', 'users' => []];
        ?>
          <tr data-theme-row data-name="<?= e(mb_strtolower($theme['label'] . ' ' . $key)) ?>" data-limited="<?= $rule['mode'] !== 'all' ? '1' : '0' ?>">
            <td>
              <strong><?= e($theme['label']) ?></strong> <code style="font-size:11px;"><?= e($key) ?></code>
              <div class="text-muted" style="font-size:12px;max-width:420px;"><?= e(mb_strimwidth($theme['description'], 0, 110, '…')) ?></div>
            </td>
            <td><?= (int) ($usage[$key] ?? 0) ?></td>
            <td>
              <select name="mode[<?= e($key) ?>]" class="form-control theme-mode">
                <?php foreach (THEME_VISIBILITY_MODES as $m => $lbl): ?>
                  <option value="<?= e($m) ?>"<?= $rule['mode'] === $m ? ' selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <textarea name="users[<?= e($key) ?>]" rows="2" class="form-control theme-users" placeholder="slug o email, uno per riga" style="min-width:200px;<?= $rule['mode'] === 'selected' ? '' : 'display:none;' ?>"><?= e(implode("\n", $allowedSlugs[$key] ?? [])) ?></textarea>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="submit" class="btn">Salva visibilità</button>
  </form>

  <script>
  (function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-theme-row]'));
    var q = document.getElementById('theme-q'), only = document.getElementById('theme-only-limited');
    function filter() {
      var t = q.value.trim().toLowerCase(), lim = only.checked;
      rows.forEach(function (r) {
        var show = (!t || r.getAttribute('data-name').indexOf(t) !== -1) && (!lim || r.getAttribute('data-limited') === '1');
        r.style.display = show ? '' : 'none';
      });
    }
    q.addEventListener('input', filter); only.addEventListener('change', filter);
    rows.forEach(function (r) {
      var sel = r.querySelector('.theme-mode'), ta = r.querySelector('.theme-users');
      sel.addEventListener('change', function () { ta.style.display = sel.value === 'selected' ? '' : 'none'; });
    });
  })();
  </script>
<?php include __DIR__ . '/_admin_footer.php'; ?>
