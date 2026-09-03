<?php
// Incluso da tutte le pagine dashboard_*. Richiede $user già caricato e $activeTab impostato.
// Nota: il tema è sempre "chiaro" per scelta di prodotto attuale. La colonna dashboard_theme
// resta nel database per un'eventuale reintroduzione futura della scelta, ma non viene più
// letta qui.
$dashTheme = 'light-theme';
// Usa il profilo su cui si sta agendo quando la pagina lo espone già ($profile), altrimenti
// ricade sul proprio account — non tutte le pagine dashboard_*.php calcolano $profile (solo
// quelle di gestione contenuti), quindi non possiamo darlo per scontato qui.
$isBandOrLabel = in_array(($profile ?? $user)['account_type'] ?? 'band', ['band', 'label'], true);

// Profili che questo utente co-gestisce (oltre al proprio) — se ce ne sono, mostriamo un
// selettore per scegliere su quale si sta agendo in questo momento.
syncActingProfileFromRequest((int) $user['id']);
$managedProfiles = getManagedProfiles((int) $user['id']);
$actingAsId = $_SESSION['acting_as_user_id'] ?? null;

// Come $isBandOrLabel sopra: usa il profilo attivo quando la pagina lo espone già, altrimenti
// ricade sul proprio account — altrimenti il badge mostrerebbe sempre i conteggi del tuo
// account reale anche mentre agisci su un altro profilo.
$countsForId = (int) ($profile ?? $user)['id'];

$stmt = getDB()->prepare('SELECT COUNT(*) c FROM contact_requests WHERE user_id = ? AND is_read = 0');
$stmt->execute([$countsForId]);
$unreadMessages = (int) $stmt->fetch()['c'];

$stmt = getDB()->prepare('SELECT COUNT(*) c FROM direct_messages WHERE recipient_id = ? AND read_at IS NULL');
$stmt->execute([$countsForId]);
$unreadDirectMessages = (int) $stmt->fetch()['c'];

// Voci del Menu di Navigazione (dashboard_nav_menu.php) per questo profilo, indicizzate per
// nome: usate per nascondere dalla barra della dashboard le schede corrispondenti a sezioni che
// l'utente ha disattivato dal menu pubblico (es. "Blog" disattivato → scheda "Blog" nascosta
// anche qui), così i due posti restano sempre coerenti tra loro.
$navVisibility = array_column(
    getAllProfileNavigationMenu((int) ($profile ?? $user)['id'], ($profile ?? $user)['slug']),
    'is_visible',
    'name'
);

// Ordine delle schede in dashboard: lo stesso scelto in "Menu di Navigazione" (Dashboard →
// menù hamburger, trascinando le voci o con "Ripristina l'ordine predefinito"), così le due
// barre restano coerenti anche nell'ordine, non solo in quali schede mostrare. "Feed" non ha un
// equivalente lì (è un'aggregazione, non una sezione della pagina pubblica) e resta sempre la
// prima scheda, fissa.
$dashTabs = [];
$dashTabs['timeline'] = ['label' => 'Timeline', 'url' => '/dashboard_post.php', 'active' => 'post', 'visible' => $navVisibility['Timeline'] ?? 1];
$dashTabs['link'] = ['label' => 'Link', 'url' => '/dashboard_links.php', 'active' => 'links', 'visible' => $navVisibility['Link'] ?? 1];
$dashTabs['bandcheamo'] = ['label' => 'Band che amo', 'url' => '/dashboard_fan_bands.php', 'active' => 'fan_bands', 'visible' => $navVisibility['Band che amo'] ?? 1];
$dashTabs['attorichamo'] = ['label' => 'Attori che amo', 'url' => '/dashboard_fan_actors.php', 'active' => 'fan_actors', 'visible' => $navVisibility['Attori che amo'] ?? 1];
$dashTabs['filmcheamo'] = ['label' => 'Film che amo', 'url' => '/dashboard_fan_movies.php', 'active' => 'fan_movies', 'visible' => $navVisibility['Film che amo'] ?? 1];
$dashTabs['libricheamo'] = ['label' => 'Libri che amo', 'url' => '/dashboard_fan_books.php', 'active' => 'fan_books', 'visible' => $navVisibility['Libri che amo'] ?? 1];
$dashTabs['viaggi'] = ['label' => 'Viaggi', 'url' => '/dashboard_fan_trips.php', 'active' => 'fan_trips', 'visible' => $navVisibility['Viaggi'] ?? 1];
$dashTabs['blog'] = ['label' => 'Blog', 'url' => '/dashboard_blog.php', 'active' => 'blog', 'visible' => $navVisibility['Blog'] ?? 1];
$dashTabs['brani'] = ['label' => 'Brani che amo', 'url' => '/dashboard_audio.php', 'active' => 'audio', 'visible' => $navVisibility['Brani che amo'] ?? 1];
$dashTabs['menu'] = ['label' => 'Menù', 'url' => '/dashboard_menu.php', 'active' => 'menu', 'visible' => $navVisibility['Menù'] ?? 1];
$dashTabs['eventi'] = [
    'label' => 'Eventi', 'url' => '/dashboard_events.php', 'active' => 'events',
    'visible' => $isBandOrLabel && ($navVisibility['Eventi'] ?? 1),
    'extra' => ['label' => 'Prenotazioni', 'url' => '/dashboard_reservations.php', 'active' => 'reservations'],
];
$dashTabs['segui'] = ['label' => 'Follower', 'url' => '/dashboard_followers.php', 'active' => 'followers', 'visible' => $navVisibility['Segui'] ?? 1];
$dashTabs['contatti'] = ['label' => 'Contatti', 'url' => '/dashboard_contacts.php', 'active' => 'contacts', 'visible' => $navVisibility['Contatti'] ?? 1];

$dashTabs = array_filter($dashTabs, fn ($t) => $t['visible']);

$dashNavOrder = getNavItemOrder($countsForId);
$dashKeysInOrder = array_keys($dashTabs);
uksort($dashTabs, function ($a, $b) use ($dashNavOrder, $dashKeysInOrder) {
    $posA = $dashNavOrder[$a] ?? (1000 + array_search($a, $dashKeysInOrder, true));
    $posB = $dashNavOrder[$b] ?? (1000 + array_search($b, $dashKeysInOrder, true));
    return $posA <=> $posB;
});

// Avatar mostrato nella barra in alto: il proprio, a meno che non si stia gestendo un altro
// profilo — in quel caso mostriamo l'avatar DI QUEL profilo, così è sempre chiaro a colpo
// d'occhio su chi si sta agendo, senza dover aprire il menu.
$barAvatarPath = $user['avatar_path'] ?? null;
$barAvatarName = $user['display_name'] ?? '?';
$barSlug = $user['slug'];
if ($actingAsId) {
    foreach ($managedProfiles as $mp) {
        if ($mp['id'] == $actingAsId) {
            $barAvatarPath = $mp['avatar_path'];
            $barAvatarName = $mp['display_name'];
            $barSlug = $mp['slug'];
            break;
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Dashboard') ?> — <?= e(siteName()) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@800;900&display=swap">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
</head>
<body class="<?= e($dashTheme) ?>">
<div class="navbar">
  <div style="display:flex;align-items:center;gap:14px;">
    <button type="button" id="account-menu-toggle" title="Account e impostazioni"
            style="background:none;border:none;cursor:pointer;font-size:20px;color:inherit;padding:4px;">
      <i class="fa-solid fa-bars"></i>
    </button>
    <div class="brand brand-dash"><a href="/">CHI<strong>FA</strong>COSA</a></div>
  </div>
  <nav class="dash-icons-nav">
    <?php if (!empty($user['is_admin'])): ?>
      <a href="/admin_dashboard.php" title="Area Admin" style="font-size:17px;"><i class="fa-solid fa-shield-halved"></i></a>
    <?php endif; ?>
    <a href="/dashboard_contacts.php" title="Contatti" style="position:relative;font-size:17px;">
      <i class="fa-solid fa-bell"></i>
      <?php if ($unreadMessages > 0): ?>
        <span style="position:absolute;top:-7px;right:-9px;background:#e74c3c;color:#fff;border-radius:999px;font-size:10.5px;font-weight:700;padding:1px 5px;line-height:1.3;min-width:16px;text-align:center;">
          <?= $unreadMessages > 9 ? '9+' : $unreadMessages ?>
        </span>
      <?php endif; ?>
    </a>
    <a href="/dashboard_messages.php" title="Messaggi" style="position:relative;font-size:17px;">
      <i class="fa-solid fa-comment-dots"></i>
      <?php if ($unreadDirectMessages > 0): ?>
        <span style="position:absolute;top:-7px;right:-9px;background:#e74c3c;color:#fff;border-radius:999px;font-size:10.5px;font-weight:700;padding:1px 5px;line-height:1.3;min-width:16px;text-align:center;">
          <?= $unreadDirectMessages > 9 ? '9+' : $unreadDirectMessages ?>
        </span>
      <?php endif; ?>
    </a>
    <?php if ($managedProfiles): ?>
      <details class="profile-switcher">
        <summary style="list-style:none;cursor:pointer;display:inline-flex;position:relative;">
          <?php if ($barAvatarPath): ?>
            <img src="/<?= e($barAvatarPath) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
          <?php else: ?>
            <span style="width:28px;height:28px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;">
              <?= e(mb_strtoupper(mb_substr($barAvatarName, 0, 1))) ?>
            </span>
          <?php endif; ?>
          <?php if ($actingAsId): ?>
            <span style="position:absolute;bottom:-3px;right:-3px;width:13px;height:13px;border-radius:50%;background:var(--accent);border:2px solid #fff;" title="Stai gestendo un profilo diverso dal tuo"></span>
          <?php endif; ?>
        </summary>
        <div class="profile-switcher-panel">
          <div style="padding:10px 16px 6px;color:var(--text-muted);font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">Stai gestendo</div>
          <a href="?acting_as=<?= (int) $user['id'] ?>" class="profile-switcher-item <?= !$actingAsId ? 'active' : '' ?>">
            <?php if (!empty($user['avatar_path'])): ?>
              <img src="/<?= e($user['avatar_path']) ?>">
            <?php else: ?>
              <span class="fallback-avatar"><?= e(mb_strtoupper(mb_substr($user['display_name'] ?? '?', 0, 1))) ?></span>
            <?php endif; ?>
            <span>Il tuo profilo</span>
          </a>
          <?php foreach ($managedProfiles as $mp): ?>
            <a href="?acting_as=<?= (int) $mp['id'] ?>" class="profile-switcher-item <?= $actingAsId == $mp['id'] ? 'active' : '' ?>">
              <?php if (!empty($mp['avatar_path'])): ?>
                <img src="/<?= e($mp['avatar_path']) ?>">
              <?php else: ?>
                <span class="fallback-avatar"><?= e(mb_strtoupper(mb_substr($mp['display_name'] ?? '?', 0, 1))) ?></span>
              <?php endif; ?>
              <span><?= e($mp['display_name']) ?></span>
            </a>
          <?php endforeach; ?>
          <div class="profile-switcher-divider"></div>
          <a href="/dashboard_profiles.php" class="profile-switcher-item">
            <i class="fa-solid fa-plus" style="width:26px;text-align:center;color:var(--text-muted);"></i>
            <span>Crea nuovo profilo</span>
          </a>
          <a href="/<?= e($barSlug) ?>" target="_blank" class="profile-switcher-item">
            <i class="fa-solid fa-arrow-up-right-from-square" style="width:26px;text-align:center;color:var(--text-muted);"></i>
            <span>Vedi la tua pagina pubblica</span>
          </a>
        </div>
      </details>
      <a href="/<?= e($barSlug) ?>" target="_blank" title="Vedi la pagina pubblica del profilo attivo" style="font-size:17px;">
        <i class="fa-solid fa-eye"></i>
      </a>
    <?php else: ?>
      <a href="/<?= e($user['slug']) ?>" target="_blank" title="Vedi pagina pubblica" style="display:inline-flex;">
        <?php if (!empty($user['avatar_path'])): ?>
          <img src="/<?= e($user['avatar_path']) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <span style="width:28px;height:28px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;">
            <?= e(mb_strtoupper(mb_substr($user['display_name'] ?? '?', 0, 1))) ?>
          </span>
        <?php endif; ?>
      </a>
    <?php endif; ?>
    <a href="/logout.php">Esci</a>
  </nav>
</div>
<?php if ($managedProfiles): ?>
<script>
document.addEventListener('click', function (e) {
  document.querySelectorAll('.profile-switcher[open]').forEach(function (el) {
    if (!el.contains(e.target)) el.removeAttribute('open');
  });
});
</script>
<?php endif; ?>

<!-- Pannello laterale "Account e impostazioni": Profilo, password, integrazioni esterne -->
<div id="account-menu-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.35);z-index:300;"></div>
<div id="account-menu-sidebar" style="display:none;position:fixed;top:0;left:0;bottom:0;width:260px;max-width:82vw;background:#fff;z-index:301;box-shadow:2px 0 20px rgba(0,0,0,0.2);overflow-y:auto;">
  <div style="padding:18px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
    <strong>Account</strong>
    <button type="button" id="account-menu-close" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
  </div>
  <div style="padding:8px 0;">
    <a href="/dashboard_profile.php" class="account-sidebar-link <?= $activeTab==='profile'?'active':'' ?>">
      <i class="fa-solid fa-id-card"></i> Profilo e anagrafica
    </a>
    <a href="/dashboard_profiles.php" class="account-sidebar-link <?= $activeTab==='profiles'?'active':'' ?>">
      <i class="fa-solid fa-layer-group"></i> I tuoi profili
    </a>
    <a href="/dashboard_password.php" class="account-sidebar-link <?= $activeTab==='password'?'active':'' ?>">
      <i class="fa-solid fa-lock"></i> Cambia password
    </a>
    <a href="/dashboard_theme.php" class="account-sidebar-link <?= $activeTab==='theme'?'active':'' ?>">
      <i class="fa-solid fa-palette"></i> Tema grafico
    </a>
    <a href="/dashboard_nav_menu.php" class="account-sidebar-link <?= $activeTab==='nav_menu'?'active':'' ?>">
      <i class="fa-solid fa-bars"></i> Menu di Navigazione
    </a>
    <a href="/dashboard_privacy_tracking.php" class="account-sidebar-link <?= $activeTab==='privacy_tracking'?'active':'' ?>">
      <i class="fa-solid fa-shield-halved"></i> Privacy e Tracking
    </a>
    <a href="/dashboard_cinema.php" class="account-sidebar-link <?= $activeTab==='cinema'?'active':'' ?>">
      <i class="fa-solid fa-film"></i> Cinema
    </a>
    <a href="/dashboard_invite.php" class="account-sidebar-link <?= $activeTab==='invite'?'active':'' ?>">
      <i class="fa-solid fa-user-plus"></i> Invita
    </a>
    <a href="/dashboard_following.php" class="account-sidebar-link <?= $activeTab==='following'?'active':'' ?>">
      <i class="fa-solid fa-heart"></i> Seguiti
    </a>
    <?php if ($isBandOrLabel): ?>
      <a href="/dashboard_team.php" class="account-sidebar-link <?= $activeTab==='team'?'active':'' ?>">
        <i class="fa-solid fa-people-group"></i> Team e co-admin
      </a>
      <a href="/dashboard_log.php" class="account-sidebar-link <?= $activeTab==='log'?'active':'' ?>">
        <i class="fa-solid fa-clock-rotate-left"></i> Log
      </a>
    <?php endif; ?>
    <?php if ($isBandOrLabel): ?>
      <div style="padding:14px 18px 4px;font-size:11.5px;text-transform:uppercase;color:var(--text-muted);">Integrazioni</div>
      <a href="/dashboard_spotify.php" class="account-sidebar-link <?= $activeTab==='spotify'?'active':'' ?>">
        <i class="fa-brands fa-spotify"></i> Account Spotify
        <?php if (!empty($user['spotify_artist_id'])): ?><span class="account-sidebar-dot"></span><?php endif; ?>
      </a>
      <a href="/dashboard_podcast.php" class="account-sidebar-link <?= $activeTab==='podcast'?'active':'' ?>">
        <i class="fa-solid fa-microphone"></i> Account Podcast
        <?php if (!empty($user['spotify_show_id'])): ?><span class="account-sidebar-dot"></span><?php endif; ?>
      </a>
      <a href="/dashboard_youtube.php" class="account-sidebar-link <?= $activeTab==='youtube'?'active':'' ?>">
        <i class="fa-brands fa-youtube"></i> Account YouTube
        <?php if (!empty($user['youtube_channel_id'])): ?><span class="account-sidebar-dot"></span><?php endif; ?>
      </a>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var toggle = document.getElementById('account-menu-toggle');
  var overlay = document.getElementById('account-menu-overlay');
  var sidebar = document.getElementById('account-menu-sidebar');
  var closeBtn = document.getElementById('account-menu-close');
  function open() { overlay.style.display = 'block'; sidebar.style.display = 'block'; }
  function close() { overlay.style.display = 'none'; sidebar.style.display = 'none'; }
  toggle.addEventListener('click', open);
  overlay.addEventListener('click', close);
  closeBtn.addEventListener('click', close);
})();
</script>

<div class="container">
  <div class="tabs-wrap">
  <div class="tabs" id="dash-tabs">
    <a href="/dashboard_timeline.php" class="<?= $activeTab==='timeline'?'active':'' ?>">Feed</a>
    <?php foreach ($dashTabs as $t): ?>
    <a href="<?= e($t['url']) ?>" class="<?= $activeTab===$t['active']?'active':'' ?>"><?= e($t['label']) ?></a>
    <?php if (!empty($t['extra'])): ?>
    <a href="<?= e($t['extra']['url']) ?>" class="<?= $activeTab===$t['extra']['active']?'active':'' ?>"><?= e($t['extra']['label']) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
  </div>
