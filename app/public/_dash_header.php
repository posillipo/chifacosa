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
// Anche le sezioni disattivate dall'amministratore per tutta l'installazione (Area Admin →
// Funzioni del sito) spariscono dalla barra della dashboard, non solo dal menu pubblico —
// altrimenti resterebbe gestibile un modulo che nessun visitatore potrà mai vedere.
$siteDisabledKeys = getSiteDisabledNavKeys();
if ($siteDisabledKeys) {
    foreach (PUBLIC_NAV_ITEM_KEYS as $navName => $navKey) {
        if (in_array($navKey, $siteDisabledKeys, true)) {
            $navVisibility[$navName] = 0;
        }
    }
}

// Ordine delle schede in dashboard: lo stesso scelto in "Menu di Navigazione" (Dashboard →
// menù hamburger, trascinando le voci o con "Ripristina l'ordine predefinito"), così le due
// barre restano coerenti anche nell'ordine, non solo in quali schede mostrare. "Feed" non ha un
// equivalente lì (è un'aggregazione, non una sezione della pagina pubblica) e resta sempre la
// prima scheda, fissa.
// Un solo tab "Che Amo" al posto di uno a modulo (Band/Attori/Film/Libri che amo, Viaggi, Brani
// che amo, e i futuri Playlist/Album che amo): porta a dashboard_che_amo.php, una vetrina con
// una card per modulo — visibile se almeno uno dei moduli non è stato nascosto da "Menu di
// Navigazione". Ogni modulo resta gestito nella sua pagina di sempre, invariata.
// "Che Amo" già forzato a nascosto sopra se disattivato da Area Admin → Funzioni del sito:
// se così, non serve nemmeno guardare i singoli moduli.
$hasVisibleCheAmoModule = false;
if ($navVisibility['Che Amo'] ?? 1) {
    foreach (CHE_AMO_MODULES as $m) {
        if ($navVisibility[$m['label']] ?? 1) {
            $hasVisibleCheAmoModule = true;
            break;
        }
    }
}
// Elenco "piatto" (niente più sotto-voci annidate come "Richieste"/"Prenotazioni"): ogni tasto
// mostrato nella barra è una voce a sé, riordinabile indipendentemente dalle altre — vedi
// DASHBOARD_TAB_KEYS/getDashboardTabOrder() in functions.php, e la sezione di riordino in
// dashboard_nav_menu.php.
$dashTabs = [];
$dashTabs['feed'] = ['label' => 'Feed', 'url' => '/dashboard_timeline.php', 'active' => 'timeline', 'visible' => true];
$dashTabs['timeline'] = ['label' => 'Timeline', 'url' => '/dashboard_post.php', 'active' => 'post', 'visible' => $navVisibility['Timeline'] ?? 1];
$dashTabs['link'] = ['label' => 'Link', 'url' => '/dashboard_links.php', 'active' => 'links', 'visible' => $navVisibility['Link'] ?? 1];
// Non corrisponde a nessuna voce del "Menu di Navigazione" pubblico (non è una sezione del sito,
// è uno strumento di gestione): sempre visibile, non gated su $navVisibility come le altre.
$dashTabs['featured'] = ['label' => 'Primo Piano', 'url' => '/dashboard_featured.php', 'active' => 'featured', 'visible' => true];
$dashTabs['cheamo'] = ['label' => 'Che Amo', 'url' => '/dashboard_che_amo.php', 'active' => 'che_amo', 'visible' => $hasVisibleCheAmoModule];
$dashTabs['blog'] = ['label' => 'Blog', 'url' => '/dashboard_blog.php', 'active' => 'blog', 'visible' => $navVisibility['Blog'] ?? 1];
$dashTabs['menu'] = ['label' => 'Menù', 'url' => '/dashboard_menu.php', 'active' => 'menu', 'visible' => $navVisibility['Menù'] ?? 1];
$dashTabs['offerte'] = ['label' => 'Offerte', 'url' => '/dashboard_offers.php', 'active' => 'offers', 'visible' => $navVisibility['Offerte'] ?? 1];
$dashTabs['foto'] = ['label' => 'Album', 'url' => '/dashboard_albums.php', 'active' => 'albums', 'visible' => $navVisibility['Foto'] ?? 1];
$dashTabs['video'] = ['label' => 'Video', 'url' => '/dashboard_videos.php', 'active' => 'videos', 'visible' => $navVisibility['Video'] ?? 1];
// Contest: modulo di profilo, visibile solo se l'amministratore lo ha autorizzato per questo profilo
// (e il modulo non è spento per tutto il sito).
$dashTabs['contest'] = ['label' => 'Contest', 'url' => '/dashboard_contest.php', 'active' => 'contest', 'visible' => contestModuleEnabled((int) $countsForId) && ($navVisibility['Contest'] ?? 1)];
$dashTabs['servizi'] = ['label' => 'Servizi', 'url' => '/dashboard_services.php', 'active' => 'services', 'visible' => $navVisibility['Servizi'] ?? 1];
$dashTabs['service_inquiries'] = ['label' => 'Richieste', 'url' => '/dashboard_service_inquiries.php', 'active' => 'service_inquiries', 'visible' => $navVisibility['Servizi'] ?? 1];
$dashTabs['eventi'] = ['label' => 'Eventi', 'url' => '/dashboard_events.php', 'active' => 'events', 'visible' => $isBandOrLabel && ($navVisibility['Eventi'] ?? 1)];
$dashTabs['reservations'] = ['label' => 'Prenotazioni', 'url' => '/dashboard_reservations.php', 'active' => 'reservations', 'visible' => $isBandOrLabel && ($navVisibility['Eventi'] ?? 1)];
$dashTabs['segui'] = ['label' => 'Follower', 'url' => '/dashboard_followers.php', 'active' => 'followers', 'visible' => $navVisibility['Segui'] ?? 1];
$dashTabs['contatti'] = ['label' => 'Contatti', 'url' => '/dashboard_contacts.php', 'active' => 'contacts', 'visible' => $navVisibility['Contatti'] ?? 1];
// Gestione token per l'API pubblica /api/v1/social-posts/*: strumento di gestione, non una
// sezione del sito pubblico — sempre visibile, non legato al "Menu di Navigazione" come "Primo Piano".
$dashTabs['api'] = ['label' => 'API', 'url' => '/dashboard_api_tokens.php', 'active' => 'api_tokens', 'visible' => true];
// Come "Primo Piano"/"API": strumento di gestione trasversale a tutti i moduli, non una sezione
// del sito pubblico — sempre visibile, non legato al "Menu di Navigazione".
$dashTabs['schedule'] = ['label' => 'Programmati', 'url' => '/dashboard_schedule.php', 'active' => 'schedule', 'visible' => true];
$dashTabs['schedule_admin'] = ['label' => 'Calendario', 'url' => '/dashboard_schedule_admin.php', 'active' => 'schedule_admin', 'visible' => !empty($user['is_admin'])];
// Interfaccia umana sulla bacheca condivisa con le AI (board_messages) — finora accessibile solo
// via API/MCP con firma. Scrive sempre come "direttore" (autenticazione = sessione già loggata,
// più forte di qualunque firma), senza passare dall'endpoint pubblico. Come API/Programmati:
// strumento di gestione trasversale, sempre visibile.
$dashTabs['board'] = ['label' => 'Bacheca', 'url' => '/dashboard_board.php', 'active' => 'board', 'visible' => true];

$dashTabs = array_filter($dashTabs, fn ($t) => $t['visible']);

$dashTabOrder = getDashboardTabOrder($countsForId);
$dashKeysInOrder = array_keys($dashTabs);
uksort($dashTabs, function ($a, $b) use ($dashTabOrder, $dashKeysInOrder) {
    $posA = $dashTabOrder[$a] ?? (1000 + array_search($a, $dashKeysInOrder, true));
    $posB = $dashTabOrder[$b] ?? (1000 + array_search($b, $dashKeysInOrder, true));
    return $posA <=> $posB;
});

// ===== Barra laterale della dashboard (voci OPERATIVE, a gruppi, ordine fisso) =====
// Le voci vengono dalle stesse regole di visibilità di $dashTabs (tipo di profilo, Menu di
// Navigazione, funzioni del sito, ruolo amministratore), calcolate qui lato server: una voce non
// permessa non finisce nemmeno nell'HTML. Il riordino personale è stato tolto di proposito (ordine
// fisso per gruppo). Voci che prima stavano solo nel menu dell'account e ora sono operative:
// Cinema, Catalogo prodotti, Seguiti, Invita (stessa visibilità di prima: sempre mostrate).
$dashSideExtra = [
    'cinema'    => ['label' => 'Cinema',            'url' => '/dashboard_cinema.php',    'active' => 'cinema'],
    'products'  => ['label' => 'Catalogo prodotti', 'url' => '/dashboard_products.php',  'active' => 'products'],
    'following' => ['label' => 'Seguiti',           'url' => '/dashboard_following.php', 'active' => 'following'],
    'invite'    => ['label' => 'Invita',            'url' => '/dashboard_invite.php',    'active' => 'invite'],
];
$dashSideAll = $dashTabs + $dashSideExtra;
// [chiave voce => icona Font Awesome]: valori fissi nel codice, mai da input dell'utente.
$dashSideGroups = [
    'Contenuti e pubblicazione' => [
        'feed' => 'fa-list', 'timeline' => 'fa-clock', 'blog' => 'fa-newspaper', 'video' => 'fa-circle-play',
        'foto' => 'fa-images', 'link' => 'fa-link', 'featured' => 'fa-star', 'cheamo' => 'fa-heart',
        'contest' => 'fa-trophy', 'schedule' => 'fa-hourglass-half', 'schedule_admin' => 'fa-calendar-days', 'board' => 'fa-thumbtack',
    ],
    'Eventi e prenotazioni' => [
        'eventi' => 'fa-ticket', 'reservations' => 'fa-calendar-check', 'cinema' => 'fa-film',
    ],
    'Business e vendite' => [
        'products' => 'fa-tags', 'menu' => 'fa-utensils', 'offerte' => 'fa-percent', 'servizi' => 'fa-briefcase',
        'service_inquiries' => 'fa-inbox', 'contatti' => 'fa-address-book',
    ],
    'Community' => [
        'segui' => 'fa-users', 'following' => 'fa-user-check', 'invite' => 'fa-user-plus',
    ],
];

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
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6C5CE7">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@800;900&display=swap">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<script>if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js'); }); }</script>
</head>
<body class="<?= e($dashTheme) ?> has-dash-side has-dash-right">
<div class="navbar navbar-dash-sticky">
  <div style="display:flex;align-items:center;gap:14px;">
    <button type="button" id="dash-side-toggle" class="dash-side-toggle" title="Menu" aria-label="Apri il menu" aria-controls="dash-side" aria-expanded="false">
      <i class="fa-solid fa-bars"></i>
    </button>
    <button type="button" id="account-menu-toggle" class="dash-account-toggle" title="Account e impostazioni"
            style="background:none;border:none;cursor:pointer;font-size:20px;color:inherit;padding:4px;">
      <i class="fa-solid fa-bars"></i>
    </button>
    <div class="brand brand-dash"><a href="/"><?= siteBrandHtml() ?></a></div>
  </div>
  <nav class="dash-icons-nav">
    <button type="button" id="dash-right-toggle" class="dash-right-toggle" title="Attività" aria-label="Apri le attività" aria-controls="dash-right" aria-expanded="false">
      <i class="fa-solid fa-chart-simple"></i>
    </button>
    <?php if (!empty($user['is_admin'])): ?>
      <a href="/admin_dashboard.php" class="dash-hide-sm" title="Area Admin" style="font-size:17px;"><i class="fa-solid fa-shield-halved"></i></a>
    <?php endif; ?>
    <?php if (!empty(($profile ?? $user)['metricool_auto_social'])): ?>
      <a href="/dashboard_social_queue.php" class="dash-hide-sm" title="Coda Social" style="font-size:17px;<?= ($activeTab ?? '') === 'social_queue' ? 'color:var(--accent)' : '' ?>">
        <i class="fa-solid fa-share-nodes"></i>
      </a>
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
        <summary style="list-style:none;cursor:pointer;display:inline-flex;position:relative;align-items:center;gap:10px;">
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
          <span class="dash-chip-text"><b><?= e($barAvatarName) ?></b><small>@<?= e($barSlug) ?></small></span>
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
      <a href="/<?= e($barSlug) ?>" target="_blank" rel="noopener" class="dash-hide-sm" title="Vedi la pagina pubblica del profilo attivo" style="font-size:17px;">
        <i class="fa-solid fa-eye"></i>
      </a>
    <?php else: ?>
      <a href="/<?= e($user['slug']) ?>" target="_blank" rel="noopener" title="Vedi pagina pubblica" style="display:inline-flex;align-items:center;gap:10px;">
        <?php if (!empty($user['avatar_path'])): ?>
          <img src="/<?= e($user['avatar_path']) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <span style="width:28px;height:28px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;">
            <?= e(mb_strtoupper(mb_substr($user['display_name'] ?? '?', 0, 1))) ?>
          </span>
        <?php endif; ?>
        <span class="dash-chip-text"><b><?= e($user['display_name'] ?? '') ?></b><small>@<?= e($user['slug']) ?></small></span>
      </a>
    <?php endif; ?>
    <button type="button" id="dash-chip-more" class="dash-chip-more dash-hide-sm" title="Account e impostazioni" aria-label="Account e impostazioni">&middot;&middot;&middot;</button>
    <a href="/logout.php" class="dash-hide-sm">Esci</a>
  </nav>
</div>
<script>
// La barra è "fixed" (vedi CSS .navbar-dash-sticky), quindi esce dal flusso normale della
// pagina: senza compensare, il contenuto sotto salterebbe su e ci finirebbe parzialmente
// nascosto. Riserviamo lo spazio giusto con un padding-top sul body, ricalcolato ogni volta
// che l'altezza della barra può cambiare (es. va su due righe ruotando lo schermo, o si
// ridimensiona la finestra) — non un valore fisso, perché su schermi stretti la barra può
// occupare una o due righe a seconda di quante icone/notifiche mostra.
(function () {
  var nav = document.querySelector('.navbar-dash-sticky');
  if (!nav) return;
  function applyOffset() {
    document.body.style.paddingTop = nav.offsetHeight + 'px';
    // Anche la barra laterale parte sotto la barra in alto: stessa altezza, via variabile CSS.
    document.documentElement.style.setProperty('--dash-nav-h', nav.offsetHeight + 'px');
  }
  applyOffset();
  window.addEventListener('resize', applyOffset);
})();
</script>
<?php if ($managedProfiles): ?>
<script>
document.addEventListener('click', function (e) {
  document.querySelectorAll('.profile-switcher[open]').forEach(function (el) {
    if (!el.contains(e.target)) el.removeAttribute('open');
  });
});

// Su schermi stretti il pannello diventa "fixed" rispetto a tutta la pagina (vedi CSS) invece
// che ancorato all'avatar, per non rischiare di partire fuori dallo schermo — ma la barra in
// alto può andare su una o due righe a seconda del contenuto, quindi il punto esatto sotto cui
// posizionarlo va calcolato qui, non indovinato in CSS.
var switcherEl = document.querySelector('.profile-switcher');
var navbarEl = document.querySelector('.navbar');
if (switcherEl && navbarEl) {
  switcherEl.addEventListener('toggle', function () {
    if (!switcherEl.open || window.innerWidth > 480) return;
    var panel = switcherEl.querySelector('.profile-switcher-panel');
    if (panel) panel.style.top = (navbarEl.getBoundingClientRect().bottom + 8) + 'px';
  });
}
</script>
<?php endif; ?>

<!-- Barra laterale della dashboard: voci operative a gruppi (fissa su schermi larghi, a scomparsa su telefono/tablet) -->
<div id="dash-side-overlay" class="dash-side-overlay"></div>
<aside id="dash-side" class="dash-side" aria-label="Menu principale">
  <div class="dash-side-head">
    <strong>Menu</strong>
    <button type="button" id="dash-side-close" class="dash-side-close" aria-label="Chiudi il menu">&times;</button>
  </div>
  <nav>
  <?php foreach ($dashSideGroups as $groupTitle => $groupItems): ?>
    <?php $visibleItems = array_filter($groupItems, fn ($k) => isset($dashSideAll[$k]), ARRAY_FILTER_USE_KEY); ?>
    <?php if (!$visibleItems) continue; ?>
    <div class="dash-side-title"><?= e($groupTitle) ?></div>
    <?php foreach ($visibleItems as $itemKey => $itemIcon): ?>
      <?php $it = $dashSideAll[$itemKey]; ?>
      <a href="<?= e($it['url']) ?>" class="dash-side-link<?= ($activeTab ?? '') === $it['active'] ? ' active' : '' ?>" title="<?= e($it['label']) ?>"<?= ($activeTab ?? '') === $it['active'] ? ' aria-current="page"' : '' ?>>
        <i class="fa-solid <?= e($itemIcon) ?>" aria-hidden="true"></i><span><?= e($it['label']) ?></span>
      </a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </nav>
  <div class="dash-side-foot">
    <?php // Voci che su telefono/tablet escono dalla barra in alto (troppo affollata): stesse regole di visibilità. ?>
    <a class="dash-side-link" href="/<?= e($barSlug) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-eye" aria-hidden="true"></i><span>Vedi il sito</span></a>
    <?php if (!empty(($profile ?? $user)['metricool_auto_social'])): ?>
    <a class="dash-side-link" href="/dashboard_social_queue.php"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i><span>Coda Social</span></a>
    <?php endif; ?>
    <?php if (!empty($user['is_admin'])): ?>
    <a class="dash-side-link" href="/admin_dashboard.php"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>Area Admin</span></a>
    <?php endif; ?>
    <button type="button" id="dash-side-account" class="dash-side-link dash-side-account-btn">
      <i class="fa-solid fa-gear" aria-hidden="true"></i><span>Account e impostazioni</span>
    </button>
    <a class="dash-side-link" href="/logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Esci</span></a>
  </div>
</aside>
<script>
(function () {
  var side = document.getElementById('dash-side');
  var overlay = document.getElementById('dash-side-overlay');
  var toggle = document.getElementById('dash-side-toggle');
  var closeBtn = document.getElementById('dash-side-close');
  if (!side || !toggle) return;
  function setOpen(open) {
    side.classList.toggle('open', open);
    overlay.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('dash-side-locked', open);
  }
  toggle.addEventListener('click', function () { setOpen(!side.classList.contains('open')); });
  overlay.addEventListener('click', function () { setOpen(false); });
  if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
  // Su telefono l'hamburger è uno solo: dal pannello si raggiunge anche il menu dell'account.
  var accountBtn = document.getElementById('dash-side-account');
  var accountToggle = document.getElementById('account-menu-toggle');
  if (accountBtn && accountToggle) {
    accountBtn.addEventListener('click', function () { setOpen(false); accountToggle.click(); });
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  // Tornando a uno schermo largo il pannello torna fisso: niente stato "aperto" residuo.
  window.addEventListener('resize', function () { if (window.innerWidth >= 992) setOpen(false); });
})();
</script>

<?php
// Colonna destra "Attività": dati del solo profilo attivo, calcolati lato server.
require_once __DIR__ . '/../src/dash_right.php';
echo renderDashRight([
    'profile_id'       => (int) $countsForId,
    'is_band_or_label' => $isBandOrLabel,
    'nav_visibility'   => $navVisibility,
    'queue_on'         => !empty(($profile ?? $user)['metricool_auto_social']),
    'is_admin'         => !empty($user['is_admin']),
    'unread_contacts'  => $unreadMessages,
    'unread_messages'  => $unreadDirectMessages,
    'tz_profile'       => ($profile ?? $user),
    // Integrazioni (prima nel menu account): solo band/etichette, come prima. Il pallino verde = collegata.
    'integrations'     => !$isBandOrLabel ? [] : [
        ['label' => 'Account Spotify',  'url' => '/dashboard_spotify.php',  'tab' => 'spotify',  'icon' => 'fa-brands fa-spotify',   'on' => !empty($user['spotify_artist_id'])],
        ['label' => 'Account Podcast',  'url' => '/dashboard_podcast.php',  'tab' => 'podcast',  'icon' => 'fa-solid fa-microphone', 'on' => !empty($user['spotify_show_id'])],
        ['label' => 'Account YouTube',  'url' => '/dashboard_youtube.php',  'tab' => 'youtube',  'icon' => 'fa-brands fa-youtube',   'on' => !empty($user['youtube_channel_id'])],
        ['label' => 'Canale Telegram',  'url' => '/dashboard_telegram.php', 'tab' => 'telegram', 'icon' => 'fa-brands fa-telegram',  'on' => !empty($user['telegram_chat_id'])],
        ['label' => 'Social Automatico', 'url' => '/dashboard_metricool.php', 'tab' => 'metricool', 'icon' => 'fa-solid fa-share-nodes', 'on' => !empty(($profile ?? $user)['metricool_auto_social'])],
    ],
    'active_tab'       => $activeTab ?? '',
]);
?>
<script>
(function () {
  var right = document.getElementById('dash-right');
  var overlay = document.getElementById('dash-right-overlay');
  var toggle = document.getElementById('dash-right-toggle');
  var closeBtn = document.getElementById('dash-right-close');
  if (!right || !toggle) return;
  function setOpen(open) {
    right.classList.toggle('open', open);
    overlay.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('dash-side-locked', open);
    // Un solo pannello aperto alla volta: aprendo questo si chiude quello delle voci.
    if (open) { var sc = document.getElementById('dash-side-close'); if (sc && document.getElementById('dash-side').classList.contains('open')) sc.click(); }
  }
  toggle.addEventListener('click', function () { setOpen(!right.classList.contains('open')); });
  overlay.addEventListener('click', function () { setOpen(false); });
  if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  window.addEventListener('resize', function () { if (window.innerWidth >= 1200) setOpen(false); });
  // Aprendo il pannello delle voci si chiude questo.
  var sideToggle = document.getElementById('dash-side-toggle');
  if (sideToggle) sideToggle.addEventListener('click', function () { if (right.classList.contains('open')) setOpen(false); });
})();
</script>

<!-- Pannello laterale "Account e impostazioni": Profilo, password, integrazioni esterne -->
<div id="account-menu-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.35);z-index:700;"></div>
<div id="account-menu-sidebar" style="display:none;position:fixed;top:0;left:0;bottom:0;width:260px;max-width:82vw;background:#fff;z-index:701;box-shadow:2px 0 20px rgba(0,0,0,0.2);overflow-y:auto;">
  <div style="padding:18px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
    <strong>Account</strong>
    <button type="button" id="account-menu-close" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
  </div>
  <div style="padding:8px 0;">
    <div style="padding:6px 18px 4px;font-size:11.5px;text-transform:uppercase;color:var(--text-muted);">Il mio profilo</div>
    <a href="/dashboard_profile.php" class="account-sidebar-link <?= $activeTab==='profile'?'active':'' ?>">
      <i class="fa-solid fa-id-card"></i> Profilo e anagrafica
    </a>
    <a href="/dashboard_profiles.php" class="account-sidebar-link <?= $activeTab==='profiles'?'active':'' ?>">
      <i class="fa-solid fa-layer-group"></i> I tuoi profili
    </a>
    <a href="/dashboard_theme.php" class="account-sidebar-link <?= $activeTab==='theme'?'active':'' ?>">
      <i class="fa-solid fa-palette"></i> Tema grafico
    </a>
    <a href="/dashboard_password.php" class="account-sidebar-link <?= $activeTab==='password'?'active':'' ?>">
      <i class="fa-solid fa-lock"></i> Cambia password
    </a>
    <div style="padding:14px 18px 4px;font-size:11.5px;text-transform:uppercase;color:var(--text-muted);">Impostazioni</div>
    <a href="/dashboard_nav_menu.php" class="account-sidebar-link <?= $activeTab==='nav_menu'?'active':'' ?>">
      <i class="fa-solid fa-bars"></i> Menu di Navigazione
    </a>
    <a href="/dashboard_privacy_tracking.php" class="account-sidebar-link <?= $activeTab==='privacy_tracking'?'active':'' ?>">
      <i class="fa-solid fa-shield-halved"></i> Privacy e Tracking
    </a>
    <?php if ($isBandOrLabel): ?>
      <a href="/dashboard_team.php" class="account-sidebar-link <?= $activeTab==='team'?'active':'' ?>">
        <i class="fa-solid fa-people-group"></i> Team e co-admin
      </a>
      <a href="/dashboard_log.php" class="account-sidebar-link <?= $activeTab==='log'?'active':'' ?>">
        <i class="fa-solid fa-clock-rotate-left"></i> Log
      </a>
    <?php endif; ?>
    <a href="/dashboard_api_tokens.php" class="account-sidebar-link <?= $activeTab==='api_tokens'?'active':'' ?>">
      <i class="fa-solid fa-code"></i> API
    </a>
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
  var chipMore = document.getElementById('dash-chip-more');
  if (chipMore) chipMore.addEventListener('click', open);
  overlay.addEventListener('click', close);
  closeBtn.addEventListener('click', close);
})();
</script>

<?php if (!empty(($profile ?? $user)['metricool_auto_social'])): ?>
<!-- ── Modale condivisione social ───────────────────────────────────────────── -->
<dialog id="sq-share-dialog" style="border:none;border-radius:14px;padding:0;width:min(420px,94vw);box-shadow:0 8px 40px rgba(0,0,0,0.18);">
  <form method="dialog" style="padding:22px 22px 18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <strong style="font-size:16px;">📤 Condividi sui social</strong>
      <button type="submit" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">&times;</button>
    </div>
    <p id="sq-share-title" style="margin:0 0 14px;font-size:14px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></p>
    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Programma per (opzionale)</label>
    <input type="datetime-local" id="sq-share-date" style="width:100%;margin-bottom:18px;padding:8px 10px;border:1px solid #d0d0d8;border-radius:7px;font-size:14px;">
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button type="submit" class="btn secondary" style="min-width:90px;">Annulla</button>
      <button type="button" id="sq-share-confirm" class="btn" style="min-width:120px;">Aggiungi in coda</button>
    </div>
    <p id="sq-share-status" style="margin:10px 0 0;font-size:13px;text-align:center;color:var(--text-muted);min-height:18px;"></p>
  </form>
</dialog>
<script>
(function () {
  var dialog = document.getElementById('sq-share-dialog');
  var confirmBtn = document.getElementById('sq-share-confirm');
  var dateInput = document.getElementById('sq-share-date');
  var titleEl = document.getElementById('sq-share-title');
  var statusEl = document.getElementById('sq-share-status');
  var _pending = null;

  window.sqSuggest = function (sq) {
    if (!sq || !sq.suggest) return;
    _pending = sq;
    titleEl.textContent = sq.title || sq.type;
    dateInput.value = '';
    statusEl.textContent = '';
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Aggiungi in coda';
    dialog.showModal();
  };

  confirmBtn.addEventListener('click', function () {
    if (!_pending) return;
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Invio...';
    statusEl.textContent = '';
    fetch('/api_social_queue_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'enqueue',
        type: _pending.type,
        content_id: _pending.id,
        title: _pending.title,
        img: _pending.img,
        url: _pending.url,
        scheduled_for: dateInput.value || null,
      }),
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.ok) {
        statusEl.style.color = '#27ae60';
        statusEl.textContent = data.updated ? 'Già in coda — data aggiornata.' : '✓ Aggiunto in coda!';
        setTimeout(function () { dialog.close(); }, 1400);
      } else {
        statusEl.style.color = '#e74c3c';
        statusEl.textContent = data.error || 'Errore';
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Aggiungi in coda';
      }
    }).catch(function () {
      statusEl.style.color = '#e74c3c';
      statusEl.textContent = 'Errore di connessione';
      confirmBtn.disabled = false;
      confirmBtn.textContent = 'Aggiungi in coda';
    });
  });

  // Intercetta tutte le risposte fetch con sq.suggest
  (function () {
    var origFetch = window.fetch;
    window.fetch = function () {
      var args = arguments;
      return origFetch.apply(this, args).then(function (response) {
        var ct = response.headers.get('Content-Type') || '';
        if (ct.indexOf('application/json') === -1) return response;
        var clone = response.clone();
        clone.json().then(function (data) {
          if (data && data.sq && data.sq.suggest) {
            window.sqSuggest(data.sq);
          }
        }).catch(function () {});
        return response;
      });
    };
  })();
})();
</script>
<?php endif; ?>

<div class="container">
