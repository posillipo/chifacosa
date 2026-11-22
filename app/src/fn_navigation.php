<?php
// Navigazione e visibilità delle sezioni, link del profilo, intestazioni e piè di pagina.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Riconosce la piattaforma social da un URL, tra quelle mostrate come icona in cima alla pagina
// pubblica. Ogni voce ha una "key" univoca usata per la deduplicazione (un solo link per
// piattaforma viene mostrato come icona) e una classe Font Awesome completa per l'icona.
// Elenco dominio => piattaforma (unica fonte: usata da detectPlatform e dalla dashboard Studio).
function platformMap(): array {
    return [
        'spotify.com'      => ['key' => 'spotify',    'icon_class' => 'fa-brands fa-spotify',    'label' => 'Spotify'],
        'music.apple.com'  => ['key' => 'apple_music','icon_class' => 'fa-brands fa-apple',      'label' => 'Apple Music'],
        'instagram.com'    => ['key' => 'instagram',  'icon_class' => 'fa-brands fa-instagram',  'label' => 'Instagram'],
        'facebook.com'     => ['key' => 'facebook',   'icon_class' => 'fa-brands fa-facebook-f', 'label' => 'Facebook'],
        'fb.com'           => ['key' => 'facebook',   'icon_class' => 'fa-brands fa-facebook-f', 'label' => 'Facebook'],
        'tiktok.com'       => ['key' => 'tiktok',     'icon_class' => 'fa-brands fa-tiktok',     'label' => 'TikTok'],
        'youtube.com'      => ['key' => 'youtube',    'icon_class' => 'fa-brands fa-youtube',    'label' => 'YouTube'],
        'youtu.be'         => ['key' => 'youtube',    'icon_class' => 'fa-brands fa-youtube',    'label' => 'YouTube'],
        'linkedin.com'     => ['key' => 'linkedin',   'icon_class' => 'fa-brands fa-linkedin-in','label' => 'LinkedIn'],
        'soundcloud.com'   => ['key' => 'soundcloud', 'icon_class' => 'fa-brands fa-soundcloud', 'label' => 'SoundCloud'],
        'whatsapp.com'     => ['key' => 'whatsapp',   'icon_class' => 'fa-brands fa-whatsapp',   'label' => 'WhatsApp'],
        'wa.me'            => ['key' => 'whatsapp',   'icon_class' => 'fa-brands fa-whatsapp',   'label' => 'WhatsApp'],
        'x.com'            => ['key' => 'x',          'icon_class' => 'fa-brands fa-x-twitter',  'label' => 'X'],
        'twitter.com'      => ['key' => 'x',          'icon_class' => 'fa-brands fa-x-twitter',  'label' => 'X'],
        'pinterest.com'    => ['key' => 'pinterest',  'icon_class' => 'fa-brands fa-pinterest',  'label' => 'Pinterest'],
        'pin.it'           => ['key' => 'pinterest',  'icon_class' => 'fa-brands fa-pinterest',  'label' => 'Pinterest'],
    ];
}

function detectPlatform(string $url): ?array {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $map = platformMap();
    foreach ($map as $domain => $info) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return $info;
        }
    }
    return null;
}


// Separa i link di un utente in: icone social (una sola per piattaforma, la PRIMA incontrata
// scorrendo l'elenco così come ordinato dal band manager nella propria dashboard) e pulsanti
// azione (tutto il resto: ripetizioni della stessa piattaforma, e link non riconosciuti).
// Un link marcato manualmente come "sito web personale" diventa sempre un'icona (globo),
// indipendentemente dal dominio, perché un sito personale non è riconoscibile automaticamente.
function splitSocialAndActionLinks(array $links): array {
    $socialLinks = [];
    $actionLinks = [];
    $seenKeys = [];
    foreach ($links as $l) {
        if (!empty($l['is_website_icon']) && !isset($seenKeys['website'])) {
            $socialLinks[] = $l + ['platform' => ['key' => 'website', 'icon_class' => 'fa-solid fa-globe', 'label' => 'Sito web']];
            $seenKeys['website'] = true;
            continue;
        }
        $platform = detectPlatform($l['url']);
        if ($platform && !isset($seenKeys[$platform['key']])) {
            $socialLinks[] = $l + ['platform' => $platform];
            $seenKeys[$platform['key']] = true;
        } else {
            $actionLinks[] = $l;
        }
    }
    return [$socialLinks, $actionLinks];
}


// Elenco ordinato (stesso ordine e stesse condizioni di publicNav()) delle sezioni del sito che
// QUESTO profilo ha davvero usato e popolato con contenuti — usato dal "Tasto Speciale" del
// modulo Link per generare un pulsante per ciascuna, invece di un elenco statico di tutte le
// funzioni esistenti sul sito. Esclude di proposito Home (è la pagina stessa), Segui (un'azione,
// non una sezione con contenuti) e Contatti (pagina sempre disponibile, non "popolata" nel senso
// di contenuti pubblicati) — e, a differenza di publicNav(), richiede contenuti anche per
// Timeline, Blog ed Eventi (lì compaiono comunque, qui servono davvero popolati).
function getPopulatedProfileSectionLinks(array $profile): array {
    $userId = (int) $profile['id'];
    $slug = $profile['slug'];
    $isBandOrLabel = in_array($profile['account_type'] ?? 'band', ['band', 'label'], true);
    $hiddenKeys = getHiddenNavKeys($userId);

    $sections = [];
    if (!in_array('timeline', $hiddenKeys, true) && hasVisibleTimelinePosts($userId)) {
        $sections[] = ['ref' => 'timeline', 'label' => 'Timeline', 'url' => '/' . $slug . '/timeline'];
    }
    if (!in_array('cheamo', $hiddenKeys, true) && hasAnyVisibleCheAmo($userId, $hiddenKeys)) {
        $sections[] = ['ref' => 'cheamo', 'label' => 'Che Amo', 'url' => '/' . $slug . '/che-amo'];
    }
    if ($isBandOrLabel && !in_array('spotify', $hiddenKeys, true) && !empty($profile['spotify_artist_id'])) {
        $sections[] = ['ref' => 'spotify', 'label' => 'Spotify', 'url' => '/' . $slug . '/spotify'];
    }
    if ($isBandOrLabel && !in_array('podcast', $hiddenKeys, true) && !empty($profile['spotify_show_id'])) {
        $sections[] = ['ref' => 'podcast', 'label' => 'Podcast', 'url' => '/' . $slug . '/podcast'];
    }
    if ($isBandOrLabel && !in_array('youtube', $hiddenKeys, true) && !empty($profile['youtube_channel_id'])) {
        $sections[] = ['ref' => 'youtube', 'label' => 'YouTube', 'url' => '/' . $slug . '/youtube'];
    }
    if (!in_array('video', $hiddenKeys, true) && hasPublicVideos($userId)) {
        $sections[] = ['ref' => 'video', 'label' => 'Video', 'url' => '/' . $slug . '/video'];
    }
    if (!in_array('blog', $hiddenKeys, true) && hasPublishedBlogPosts($userId)) {
        $sections[] = ['ref' => 'blog', 'label' => 'Blog', 'url' => '/' . $slug . '/blog'];
    }
    if (!in_array('menu', $hiddenKeys, true) && menuHasItems($userId)) {
        $sections[] = ['ref' => 'menu', 'label' => 'Menù', 'url' => '/' . $slug . '/menu'];
    }
    if (!in_array('offerte', $hiddenKeys, true) && hasActiveOffers($userId)) {
        $sections[] = ['ref' => 'offerte', 'label' => 'Offerte', 'url' => '/' . $slug . '/offerte'];
    }
    if (!in_array('foto', $hiddenKeys, true) && hasPublicPhotoContent($userId)) {
        $sections[] = ['ref' => 'foto', 'label' => 'Foto', 'url' => '/' . $slug . '/foto'];
    }
    if (!in_array('servizi', $hiddenKeys, true) && hasVisibleServices($userId)) {
        $sections[] = ['ref' => 'servizi', 'label' => 'Servizi', 'url' => '/' . $slug . '/servizi'];
    }
    if ($isBandOrLabel && !in_array('eventi', $hiddenKeys, true) && hasAnyEvents($userId)) {
        $sections[] = ['ref' => 'eventi', 'label' => 'Eventi', 'url' => '/' . $slug . '/eventi'];
    }
    return $sections;
}


// Sincronizza (aggiunge/aggiorna/rimuove — stesso principio di syncCinemaFilms()) i pulsanti Link
// delle sezioni del sito davvero popolate, dietro un separatore fisso "Sezioni del sito" creato
// una sola volta e mai più spostato, per non disturbare l'ordine scelto a mano dal profilo per
// gli altri link. Ogni pulsante generato porta un external_ref (es. "section_blog"): permette di
// ri-eseguire la sincronizzazione quante volte si vuole senza mai duplicare nulla, e fa sparire
// da solo il pulsante di una sezione che torna senza contenuti (e il separatore stesso, se non
// resta più nessuna sezione generata).
function syncSectionLinksForProfile(array $profile): array {
    $db = getDB();
    $userId = (int) $profile['id'];
    $sections = getPopulatedProfileSectionLinks($profile);

    $stmt = $db->prepare('SELECT id, external_ref, label, url FROM links WHERE user_id=? AND external_ref IS NOT NULL');
    $stmt->execute([$userId]);
    $existing = [];
    foreach ($stmt->fetchAll() as $row) {
        if (str_starts_with((string) $row['external_ref'], 'section_')) {
            $existing[$row['external_ref']] = $row;
        }
    }

    if (!$sections && !$existing) {
        return ['ok' => false, 'error' => 'Nessuna sezione del sito ha ancora contenuti pubblicati da collegare.'];
    }

    if ($sections && !isset($existing['section_divider'])) {
        $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order),0) AS m FROM links WHERE user_id=?');
        $stmt->execute([$userId]);
        $dividerSort = (int) $stmt->fetch()['m'] + 1;
        $stmt = $db->prepare("INSERT INTO links (user_id, label, url, link_type, sort_order, external_ref) VALUES (?, 'Sezioni del sito', '', 'divider', ?, 'section_divider')");
        $stmt->execute([$userId, $dividerSort]);
    }

    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order),0) AS m FROM links WHERE user_id=?');
    $stmt->execute([$userId]);
    $nextSort = (int) $stmt->fetch()['m'] + 1;

    $seenRefs = ['section_divider' => true];
    $added = 0;
    $updated = 0;
    foreach ($sections as $section) {
        $ref = 'section_' . $section['ref'];
        $seenRefs[$ref] = true;
        if (isset($existing[$ref])) {
            $row = $existing[$ref];
            if ($row['label'] !== $section['label'] || $row['url'] !== $section['url']) {
                $db->prepare('UPDATE links SET label=?, url=? WHERE id=? AND user_id=?')
                   ->execute([$section['label'], $section['url'], $row['id'], $userId]);
                $updated++;
            }
        } else {
            $db->prepare("INSERT INTO links (user_id, label, url, link_type, sort_order, external_ref) VALUES (?,?,?,'link',?,?)")
               ->execute([$userId, $section['label'], $section['url'], $nextSort, $ref]);
            $nextSort++;
            $added++;
        }
    }

    $removed = 0;
    foreach ($existing as $ref => $row) {
        if (isset($seenRefs[$ref])) {
            continue;
        }
        $db->prepare('DELETE FROM links WHERE id=? AND user_id=?')->execute([$row['id'], $userId]);
        $removed++;
    }
    // Nessuna sezione popolata rimasta: anche il separatore perde senso.
    if (!$sections && isset($existing['section_divider'])) {
        $db->prepare('DELETE FROM links WHERE id=? AND user_id=?')->execute([$existing['section_divider']['id'], $userId]);
    }

    return ['ok' => true, 'added' => $added, 'updated' => $updated, 'removed' => $removed, 'total' => count($sections)];
}


// Converte la stringa "1,4,7" salvata nel database in un elenco di numeri validi (1-14),
// scartando eventuali valori corrotti o fuori range.
function parseMenuAllergens(?string $csv): array {
    if (!$csv) return [];
    $ids = array_filter(array_map('intval', explode(',', $csv)));
    return array_values(array_intersect($ids, array_keys(MENU_ALLERGENS)));
}


// Usata dall'header pubblico per decidere se mostrare il tab "Menù" — un solo COUNT leggero,
// non richiede di modificare le query di ogni singola pagina pubblica per portarsi dietro il dato.
function menuHasItems(int $userId): bool {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM menu_items WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


// Vero se il profilo ha almeno un'offerta attualmente valida (attiva E, se ha una validità
// impostata, dentro l'intervallo) — usata per decidere se mostrare il tab "Offerte" nel menu
// pubblico, stesso criterio di menuHasItems() per il Menù.
function hasActiveOffers(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM special_offers
        WHERE user_id = ? AND is_active = 1
          AND (valid_from IS NULL OR valid_from <= NOW())
          AND (valid_until IS NULL OR valid_until >= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


// Le tre condizioni "popolate" che publicNav() non copre già con i suoi parametri hasMenu/
// hasOffers/ecc. (Timeline, Blog ed Eventi compaiono lì sempre, indipendentemente dal
// contenuto) — usate dal "Tasto Speciale" del modulo Link per capire quali sezioni del sito
// generare davvero come pulsanti, non solo quali tab sarebbero visibili.
function hasVisibleTimelinePosts(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM timeline_posts WHERE user_id = ? AND visibility = 'public' AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasPublishedBlogPosts(int $userId): bool {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM blog_posts WHERE user_id = ? AND published_at <= NOW()');
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasAnyEvents(int $userId): bool {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM events WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


// Tutte queste hasFanFavoriteX() decidono se il relativo tab/modulo "che amo" compare nel menu
// pubblico e nella vetrina — devono quindi contare solo gli elementi davvero visibili a un
// visitatore (Pubblico e non ancora programmati per il futuro), altrimenti un profilo con solo
// elementi "Solo io" mostrerebbe comunque il tab, portando a una vetrina vuota.
function hasFanFavoriteBands(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_bands WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoriteActors(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_actors WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoriteMovies(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_movies WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoriteBooks(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_books WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoriteTrips(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_trips WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoritePlaylists(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_playlists WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoriteAlbums(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_albums WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoriteRecipes(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_recipes WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoriteTeams(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_teams WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoritePlayers(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_players WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoriteMatches(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_matches WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


function hasFanFavoritePublications(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM fan_favorite_publications WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


// True se almeno un modulo "che amo" non nascosto ($hiddenKeys, da getHiddenNavKeys()) ha
// contenuto — decide se mostrare il tab riepilogativo "Che Amo" nel menu pubblico. "cheamo"
// stesso tra le chiavi nascoste (es. disattivato per tutta l'installazione da Area Admin →
// Funzioni del sito) spegne l'intera vetrina a prescindere dai singoli moduli.
function hasAnyVisibleCheAmo(int $userId, array $hiddenKeys = []): bool {
    if (in_array('cheamo', $hiddenKeys, true)) {
        return false;
    }
    foreach (CHE_AMO_MODULES as $key => $m) {
        if (in_array($key, $hiddenKeys, true)) {
            continue;
        }
        if ($m['check'] === null || $m['check']($userId)) {
            return true;
        }
    }
    return false;
}


// Menu di navigazione condiviso tra tutte le pagine pubbliche di un artista (Home | Blog | Brani | Eventi | Contatti)
// Il tab "Spotify" compare solo se l'artista ha collegato un profilo Spotify dalla dashboard.
function publicNav(string $slug, string $active, bool $hasSpotify = false, bool $hasYoutube = false, bool $hasPodcast = false, string $accountType = 'band', ?int $ownerId = null, bool $hasMenu = false, bool $hasOffers = false, bool $hasPhotos = false, bool $hasServices = false): string {
    $isBandOrLabel = in_array($accountType, ['band', 'label'], true);
    // Tab che il profilo ha esplicitamente nascosto da "Menu di Navigazione" in dashboard —
    // copre anche le integrazioni e Segui, non solo i tab "di contenuto".
    $hiddenKeys = $ownerId ? getHiddenNavKeys($ownerId) : [];

    $viewerId = publicActingUserId();
    $seguiLabel = '✨ Segui';
    if ($viewerId && $ownerId && (int) $viewerId !== (int) $ownerId) {
        $seguiLabel = isFollowingAccount((int) $viewerId, (int) $ownerId) ? '✓ Segui già' : '✨ Segui';
    }
    $canFollow = !$viewerId || !$ownerId || (int) $viewerId !== (int) $ownerId;

    // Stesso ordine di PUBLIC_NAV_ITEM_KEYS/createDefaultProfileNavMenu(), allineato a quello
    // della barra di gestione in dashboard (_dash_header.php), per restare coerenti anche tra
    // parte pubblica e dashboard, non solo con la checklist di dashboard_nav_menu.php.
    $tabs = [];
    $tabs['home'] = ['label' => 'Home', 'url' => '/' . $slug, 'icon' => 'fas fa-house'];
    $tabs['timeline'] = ['label' => 'Timeline', 'url' => '/' . $slug . '/timeline', 'icon' => 'fas fa-stream'];
    // Un solo tab per tutti i moduli "che amo" (Band/Attori/Film/Libri, Viaggi, Brani che amo, e
    // i futuri Playlist/Album che amo) — porta a una vetrina con una card per modulo, invece di
    // un tab a testa: la lista dei moduli sarebbe altrimenti cresciuta indefinitamente. Ogni
    // modulo resta gestito e mostrato esattamente come prima, cambia solo come ci si arriva.
    if ($ownerId && hasAnyVisibleCheAmo($ownerId, $hiddenKeys)) {
        $tabs['cheamo'] = ['label' => 'Che Amo', 'url' => '/' . $slug . '/che-amo', 'icon' => 'fas fa-heart'];
    }
    if ($hasSpotify && $isBandOrLabel) {
        $tabs['spotify'] = ['label' => 'Spotify', 'url' => '/' . $slug . '/spotify', 'icon' => 'fa-brands fa-spotify'];
    }
    if ($hasPodcast && $isBandOrLabel) {
        $tabs['podcast'] = ['label' => 'Podcast', 'url' => '/' . $slug . '/podcast', 'icon' => 'fas fa-microphone'];
    }
    if ($hasYoutube && $isBandOrLabel) {
        $tabs['youtube'] = ['label' => 'YouTube', 'url' => '/' . $slug . '/youtube', 'icon' => 'fa-brands fa-youtube'];
    }
    if ($ownerId && hasPublicVideos($ownerId)) {
        $tabs['video'] = ['label' => 'Video', 'url' => '/' . $slug . '/video', 'icon' => 'fas fa-video'];
    }
    if ($ownerId && hasPublicContests($ownerId)) {
        $tabs['contest'] = ['label' => 'Contest', 'url' => '/' . $slug . '/contest', 'icon' => 'fas fa-trophy'];
    }
    $tabs['blog'] = ['label' => 'Blog', 'url' => '/' . $slug . '/blog', 'icon' => 'fas fa-newspaper'];
    if ($hasMenu) {
        $tabs['menu'] = ['label' => 'Menù', 'url' => '/' . $slug . '/menu', 'icon' => 'fas fa-utensils'];
    }
    if ($hasOffers) {
        $tabs['offerte'] = ['label' => 'Offerte', 'url' => '/' . $slug . '/offerte', 'icon' => 'fas fa-tags'];
    }
    if ($hasPhotos) {
        $tabs['foto'] = ['label' => 'Foto', 'url' => '/' . $slug . '/foto', 'icon' => 'fas fa-images'];
    }
    if ($hasServices) {
        $tabs['servizi'] = ['label' => 'Servizi', 'url' => '/' . $slug . '/servizi', 'icon' => 'fas fa-briefcase'];
    }
    if ($isBandOrLabel) {
        $tabs['eventi'] = ['label' => 'Eventi', 'url' => '/' . $slug . '/eventi', 'icon' => 'fas fa-calendar'];
    }
    if ($canFollow) {
        $tabs['segui'] = ['label' => $seguiLabel, 'url' => '/' . $slug . '#segui-widget', 'class' => 'nav-segui-tab'];
    }
    $tabs['contatti'] = ['label' => 'Contatti', 'url' => '/' . $slug . '/contatti', 'icon' => 'fas fa-envelope'];

    // "Nascosto" vale sempre, anche per la pagina su cui ci si trova in quel momento: se il
    // profilo ha disattivato una voce, non deve comparire nel menu neppure arrivandoci tramite
    // link diretto.
    foreach ($hiddenKeys as $hk) {
        unset($tabs[$hk]);
    }

    // Ordine personalizzato dal profilo (trascinamento in dashboard_nav_menu.php) — se non
    // ancora impostato per una voce, resta nell'ordine con cui è stata costruita sopra.
    if ($ownerId) {
        $order = getNavItemOrder($ownerId);
        $keysInOrder = array_keys($tabs);
        uksort($tabs, function ($a, $b) use ($order, $keysInOrder) {
            $posA = $order[$a] ?? (1000 + array_search($a, $keysInOrder, true));
            $posB = $order[$b] ?? (1000 + array_search($b, $keysInOrder, true));
            return $posA <=> $posB;
        });
    }

    // "Home" resta sempre per prima e "Segui" sempre per ultima, qualunque sia l'ordine
    // personalizzato scelto dal profilo o lo spostamento della voce attiva qui sotto — le uniche
    // due il cui posto non deve mai cambiare (Home è il punto fisso da cui ripartire, Segui il
    // pulsante di richiamo che deve restare sempre raggiungibile in fondo a destra).
    $pinHomeAndSegui = function (array $t): array {
        $first = [];
        $last = [];
        if (isset($t['home'])) {
            $first['home'] = $t['home'];
            unset($t['home']);
        }
        if (isset($t['segui'])) {
            $last['segui'] = $t['segui'];
            unset($t['segui']);
        }
        return $first + $t + $last;
    };

    // Ordine "naturale" (quello scelto dal profilo in Menu di Navigazione, con Home/Segui
    // pinnati) prima dello spostamento della voce attiva qui sotto — usato solo su desktop (vedi
    // @media (min-width:761px) su ".colorful-nav a" in style.css) per tenere ferma ogni pillola al
    // proprio posto: lì il menu si vede quasi sempre per intero senza scorrere, e lo spostamento
    // risulterebbe solo un salto innaturale invece che un aiuto.
    $naturalOrder = array_flip(array_keys($pinHomeAndSegui($tabs)));

    // La voce attiva passa al secondo posto (subito dopo la prima) SOLO nella resa di questa
    // pagina, e solo su mobile (l'ordine "naturale" qui sopra vince su desktop via CSS) — l'ordine
    // salvato in Menu di Navigazione resta quello scelto dal proprietario, invariato. Aiuta chi
    // naviga fra tante voci su una barra che scorre in orizzontale a ritrovare sempre subito in
    // vista la sezione in cui si trova, senza dover scorrere il menu ogni volta.
    if (isset($tabs[$active])) {
        $activeEntry = [$active => $tabs[$active]];
        unset($tabs[$active]);
        $tabs = array_slice($tabs, 0, 1, true) + $activeEntry + array_slice($tabs, 1, null, true);
    }
    $tabs = $pinHomeAndSegui($tabs);

    $parts = [];
    foreach ($tabs as $key => $t) {
        $classes = trim(($t['class'] ?? '') . ($key === $active ? ' nav-active-tab' : ''));
        $classAttr = $classes !== '' ? ' class="' . e($classes) . '"' : '';
        $icon = !empty($t['icon']) ? '<i class="' . e($t['icon']) . '"></i> ' : '';
        $orderStyle = ' style="--nav-order:' . (int) ($naturalOrder[$key] ?? 0) . ';"';
        $parts[] = '<a href="' . e($t['url']) . '"' . $classAttr . $orderStyle . '>' . $icon . e($t['label']) . '</a>';
    }
    return '<div class="colorful-nav-wrap">'
        . '<nav class="colorful-nav">' . implode('', $parts) . '</nav>'
        . '<button type="button" class="colorful-nav-arrow" aria-label="Scorri il menu">' . '<i class="fa-solid fa-chevron-right"></i></button>'
        . '</div>'
        . '<script src="' . assetUrl('/assets/js/nav-scroll-hint.js') . '" defer></script>';
}


// Blocco identità condiviso (avatar + nome + eventuale bio + menu) stampato in cima ad ogni
// pagina pubblica dell'artista (home, blog, brani, eventi, contatti, spotify), per un aspetto
// coerente. La bio, quando presente, è mostrata come vignetta al passaggio del mouse
// sull'avatar (non più come testo sempre visibile), per un profilo più compatto.
function publicProfileHeader(array $artist, string $active, bool $showBio = false): string {
    $isElectric = ($artist['page_theme'] ?? 'colorful') === 'electric';
    $electricClass = $isElectric ? ' electric-border' : '';
    $electricStyle = $isElectric ? ' style="--electric-border-color:' . e($artist['theme_color'] ?: '#6C5CE7') . ';"' : '';
    // "Backstage Pass": avatar+nome+slug si presentano come un pass da concerto laminato — serve
    // un contenitore proprio (vedi .backstage-pass in style.css) per il laccetto/foro sopra e la
    // striscia "ALL ACCESS" in diagonale, senza che il dondolio del pass coinvolga anche il menu
    // sotto. Stesso approccio minimale/isolato già usato qui sopra per il tema "electric".
    $isBackstage = ($artist['page_theme'] ?? 'colorful') === 'backstage';
    $ownerId = isset($artist['id']) ? (int) $artist['id'] : null;
    // Il "+" accanto al nome utente è una scorciatoia al pulsante "Segui" del menu — stesso link
    // (l'ancora #segui-widget della Home, come già fa la voce "Segui" del menu pubblico), nessuna
    // logica nuova da mantenere. Non ha senso mostrarlo sul proprio stesso profilo.
    $viewerIdForFollow = $_SESSION['user_id'] ?? null;
    $canShowFollowPlus = $ownerId && (!$viewerIdForFollow || (int) $viewerIdForFollow !== $ownerId);
    $html = '<div class="profile-header' . $electricClass . '"' . $electricStyle . '>';
    if ($isBackstage) {
        $html .= '<div class="backstage-pass">';
    }
    if (!empty($artist['avatar_path'])) {
        $html .= '<div class="avatar-wrap">';
        $html .= '<img class="avatar" src="/' . e($artist['avatar_path']) . '" alt="' . e($artist['display_name']) . '">';
        if ($showBio && !empty($artist['bio'])) {
            $html .= '<div class="avatar-bio-tooltip">' . nl2br(e($artist['bio'])) . '</div>';
        }
        $html .= '</div>';
    } elseif ($showBio && !empty($artist['bio'])) {
        // Senza avatar non c'è nulla su cui fare hover: la bio resta visibile come testo normale
        $html .= '<p>' . nl2br(e($artist['bio'])) . '</p>';
    }
    $html .= '<h1>' . e($artist['display_name']) . '</h1>';
    $html .= '<p class="profile-meta">@' . e($artist['slug']);
    if ($canShowFollowPlus) {
        $html .= ' <a href="/' . e($artist['slug']) . '#segui-widget" class="profile-follow-plus" aria-label="Segui ' . e($artist['display_name']) . '"><i class="fa-solid fa-plus"></i></a>';
    }
    if (!empty($artist['genere'])) {
        $html .= '<span> · </span>' . e($artist['genere']);
    }
    $html .= '</p>';
    if ($isBackstage) {
        $html .= '</div>';
    }
    $hasMenu = $ownerId ? menuHasItems($ownerId) : false;
    $hasOffers = $ownerId ? hasActiveOffers($ownerId) : false;
    $hasPhotos = $ownerId ? hasPublicPhotoContent($ownerId) : false;
    $hasServices = $ownerId ? hasVisibleServices($ownerId) : false;
    $html .= publicNav($artist['slug'], $active, !empty($artist['spotify_artist_id']), !empty($artist['youtube_channel_id']), !empty($artist['spotify_show_id']), $artist['account_type'] ?? 'band', $ownerId, $hasMenu, $hasOffers, $hasPhotos, $hasServices);
    $html .= '</div>';
    if ($isElectric) {
        $html .= '<script src="' . assetUrl('/assets/js/electric-border.js') . '" defer></script>';
    }
    return $html;
}


// Barra fissa in fondo alla pagina che invita alla registrazione, presente su tutte le pagine
// pubbliche del sito.
// Footer di tutte le pagine pubbliche: pulsante promozionale "CHI FA COSA/tu" (sopra) + link
// Cookie/Privacy/CHI FA COSA-o-Dashboard (sotto). È un blocco normale nel flusso della pagina (non
// più "fixed"), quindi non copre mai il contenuto — resta comunque sempre visibile in fondo
// alla pagina anche a contenuto vuoto, grazie al layout flessibile di body.colorful-page.
// Pulsanti flottanti condivisi su tutte le pagine pubbliche: "torna su" (compare scrollando
// molto verso il basso) e, se l'utente è loggato, un'iconcina che riporta alla dashboard.
function renderFloatingButtons(): string {
    $dashboardBtn = '';
    if (!empty($_SESSION['user_id'])) {
        $dashboardBtn = '<a href="/dashboard.php" id="to-dashboard-btn" class="floating-btn" title="Vai alla dashboard">
            <i class="fa-solid fa-gauge"></i>
        </a>';
    }

    return $dashboardBtn . '
    <button type="button" id="back-to-top-btn" class="floating-btn" title="Torna su" aria-label="Torna su">
        <i class="fa-solid fa-arrow-up"></i>
    </button>
    <script>
    (function () {
        var btn = document.getElementById("back-to-top-btn");
        if (!btn) return;
        window.addEventListener("scroll", function () {
            btn.style.display = window.scrollY > 400 ? "flex" : "none";
        });
        btn.addEventListener("click", function () {
            window.scrollTo({ top: 0, behavior: "smooth" });
        });
    })();
    </script>';
}


function renderSiteFooterBar(?array $profile = null): string {
    $privacyUrl = trim(getProfileTracking($profile)['privacy_policy_url'] ?? '');
    if ($privacyUrl === '') {
        $privacyUrl = getSiteSetting('privacy_policy_url') ?: '';
    }
    $parts = [];
    // CookieYes intercetta automaticamente qualsiasi elemento con questa classe per riaprire
    // il pannello delle preferenze cookie — non serve nessuna chiamata JavaScript esplicita.
    $parts[] = '<a href="#" class="cky-banner-element">Preferenze Cookie</a>';
    if ($privacyUrl !== '') {
        $parts[] = '<a href="' . e($privacyUrl) . '" target="_blank" rel="noopener">Privacy</a>';
    } else {
        $parts[] = '<a href="/">Privacy</a>';
    }
    // L'ultimo link cambia in base a chi sta navigando: un visitatore qualsiasi vede il nome
    // del sito (torna alla home), chi è già loggato vede "Dashboard" (va alla propria area privata).
    if (!empty($_SESSION['user_id'])) {
        $parts[] = '<a href="/dashboard_profile.php">Dashboard</a>';
    } else {
        $parts[] = '<a href="/">' . e(siteName()) . '</a>';
    }
    $parts[] = '<a href="/credits.php">Crediti</a>';
    $linksRow = '<div class="footer-links">' . implode('<span> · </span>', $parts) . '</div>';
    return '<div class="site-footer-fixed">' . $linksRow . '</div>';
}


// Vero se il profilo ha almeno un servizio pubblico e valido — usata per decidere se mostrare il
// tab "Servizi" nel menu pubblico, stesso criterio di menuHasItems()/hasActiveOffers().
function hasVisibleServices(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM services
        WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


// Vero se il profilo ha almeno una foto pubblica da mostrare nella sezione "Foto" — un post
// Timeline pubblico con almeno una foto, o un album pubblico con almeno una foto — usata per
// decidere se mostrare il tab "Foto" nel menu pubblico, stesso criterio di menuHasItems()/
// hasActiveOffers() per gli altri moduli.
function hasPublicPhotoContent(int $userId): bool {
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM timeline_posts
        WHERE user_id = ? AND visibility = 'public' AND (publish_at IS NULL OR publish_at <= NOW()) AND image_path IS NOT NULL");
    $stmt->execute([$userId]);
    if ((int) $stmt->fetch()['c'] > 0) {
        return true;
    }
    $stmt = getDB()->prepare("SELECT COUNT(*) c FROM photo_albums
        WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW()) AND cover_path IS NOT NULL");
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'] > 0;
}


// True se il profilo ha almeno un video caricato, pubblico e già pubblicato (modulo "Video").
// Il modulo Contest è di un profilo: serve l'autorizzazione dell'amministratore (profiles.contest_enabled)
// e il modulo non deve essere spento per tutto il sito (Area Admin -> Funzioni del sito).
function contestModuleEnabled(int $userId): bool {
    static $cache = [];
    if (!isset($cache[$userId])) {
        try {
            $stmt = getDB()->prepare('SELECT contest_enabled FROM profiles WHERE user_id = ?');
            $stmt->execute([$userId]);
            $cache[$userId] = (int) $stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            $cache[$userId] = false; // migrazione non ancora eseguita
        }
    }
    return $cache[$userId] && !in_array('contest', getSiteDisabledNavKeys(), true);
}


// Il profilo ha almeno un'edizione pubblica (non in bozza): decide se compare la voce di menu.
function hasPublicContests(int $userId): bool {
    if (!contestModuleEnabled($userId)) {
        return false;
    }
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM contests WHERE user_id = ? AND status <> 'draft'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}


function hasPublicVideos(int $userId): bool {
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) c FROM videos WHERE user_id = ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())");
        $stmt->execute([$userId]);
        return (int) $stmt->fetch()['c'] > 0;
    } catch (Throwable $e) {
        // Tabella non ancora creata (migrazione non eseguita): il modulo semplicemente non compare.
        return false;
    }
}


/**
 * Crea le voci di menu di default per un profilo, con URL basati sul suo slug reale
 * (idempotente: INSERT IGNORE, non duplica se già esistenti). "Link" resta una sezione dentro
 * Home, senza un tab proprio (l'URL indicato è solo quello della pagina su cui vive, per
 * riferimento nella checklist) — "Band che amo", "Attori che amo", "Film che amo" e "Libri che
 * amo" invece non hanno più un tab proprio nel menu pubblico (sono raccolti dentro "Che Amo"),
 * ma hanno comunque ciascuno una pagina elenco dedicata e raggiungibile direttamente
 * (/slug/band-che-amo ecc., vedi CHE_AMO_MODULES/.htaccess): l'URL qui sotto punta lì, non alla
 * home, così la checklist di dashboard_nav_menu.php mostra il link vero della card.
 */
function createDefaultProfileNavMenu(int $userId, string $slug): bool {
    $defaults = [
        ['Home', 'fas fa-home', '/' . $slug, 1],
        ['Timeline', 'fas fa-stream', '/' . $slug . '/timeline', 2],
        ['Link', 'fas fa-link', '/' . $slug, 3],
        ['Che Amo', 'fas fa-heart', '/' . $slug . '/che-amo', 4],
        ['Band che amo', 'fas fa-heart-circle-check', '/' . $slug . '/band-che-amo', 5],
        ['Attori che amo', 'fas fa-clapperboard', '/' . $slug . '/attori-che-amo', 6],
        ['Film che amo', 'fas fa-film', '/' . $slug . '/film-che-amo', 7],
        ['Libri che amo', 'fas fa-book', '/' . $slug . '/libri-che-amo', 8],
        ['Viaggi', 'fas fa-plane', '/' . $slug . '/viaggi', 9],
        ['Spotify', 'fa-brands fa-spotify', '/' . $slug . '/spotify', 10],
        ['Podcast', 'fas fa-microphone', '/' . $slug . '/podcast', 11],
        ['YouTube', 'fa-brands fa-youtube', '/' . $slug . '/youtube', 12],
        ['Blog', 'fas fa-newspaper', '/' . $slug . '/blog', 13],
        ['Brani che amo', 'fas fa-music', '/' . $slug . '/brani', 14],
        ['Playlist che amo', 'fas fa-list-ul', '/' . $slug . '/playlist-che-amo', 15],
        ['Album che amo', 'fas fa-compact-disc', '/' . $slug . '/album-che-amo', 16],
        ['Menù', 'fas fa-utensils', '/' . $slug . '/menu', 17],
        ['Eventi', 'fas fa-calendar', '/' . $slug . '/eventi', 18],
        ['Segui', 'fas fa-heart', '/' . $slug . '#segui-widget', 19],
        ['Contatti', 'fas fa-envelope', '/' . $slug . '/contatti', 20],
        ['Offerte', 'fas fa-tags', '/' . $slug . '/offerte', 21],
        ['Foto', 'fas fa-images', '/' . $slug . '/foto', 22],
        ['Servizi', 'fas fa-briefcase', '/' . $slug . '/servizi', 23],
        ['Ricette che amo', 'fas fa-bowl-food', '/' . $slug . '/ricette-che-amo', 24],
        ['Squadre che amo', 'fas fa-futbol', '/' . $slug . '/squadre-che-amo', 25],
        ['Calciatori che amo', 'fas fa-shirt', '/' . $slug . '/calciatori-che-amo', 26],
        ['Partite che amo', 'fas fa-calendar-check', '/' . $slug . '/partite-che-amo', 27],
        ['Pubblicazioni che amo', 'fas fa-microscope', '/' . $slug . '/pubblicazioni-che-amo', 28],
        ['Video', 'fas fa-video', '/' . $slug . '/video', 29],
        ['Contest', 'fas fa-trophy', '/' . $slug . '/contest', 30],
    ];

    foreach ($defaults as [$name, $icon, $url, $order]) {
        $stmt = getDB()->prepare('
            INSERT IGNORE INTO profile_navigation_menu (user_id, name, icon, url, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ');
        if (!$stmt->execute([$userId, $name, $icon, $url, $order])) {
            return false;
        }
    }
    return true;
}


/**
 * Ottiene TUTTE le voci di menu per un profilo (incluse nascoste), creando i default mancanti al
 * primo accesso — sia per un profilo che non ne ha ancora nessuna, sia per un profilo creato
 * prima dell'introduzione di una voce più recente (es. Spotify/Podcast/Video/Segui, aggiunte
 * dopo Home/Timeline/Blog/Brani/Menù/Eventi/Contatti): in quel caso ne mancano solo alcune, non
 * tutte, ma vanno comunque completate.
 */
function getAllProfileNavigationMenu(int $userId, string $slug): array {
    $stmt = getDB()->prepare('
        SELECT id, name, icon, url, is_visible, sort_order
        FROM profile_navigation_menu
        WHERE user_id = ?
        ORDER BY sort_order ASC
    ');
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll() ?: [];
    if (count($items) < count(PUBLIC_NAV_ITEM_KEYS)) {
        createDefaultProfileNavMenu($userId, $slug);
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll() ?: [];
    }
    return $items;
}


/**
 * Aggiorna la visibilità di una voce di menu.
 */
function updateProfileNavMenuVisibility(int $userId, string $name, bool $isVisible): bool {
    $stmt = getDB()->prepare('
        UPDATE profile_navigation_menu
        SET is_visible = ?
        WHERE user_id = ? AND name = ?
    ');
    return $stmt->execute([$isVisible ? 1 : 0, $userId, $name]);
}


// Chiavi dei tab standard che questo profilo ha esplicitamente nascosto — usata da
// publicProfileHeader()/publicNav() per filtrare il menu pubblico. Un profilo che non ha mai
// aperto "Menu di Navigazione" in dashboard non ha righe in tabella: nessuna riga nascosta,
// nessun filtro, comportamento identico a prima di questa funzionalità (nessun bisogno di
// seedare i default solo per calcolare questo elenco).
// Voci di menu disattivate dall'amministratore per TUTTA l'installazione (Area Admin → Funzioni
// del sito), non da un singolo profilo — pensato per chi installa "Chi Fa Cosa" per un tipo di
// attività che non ha bisogno di certi moduli (es. un ristorante non ha bisogno di "Squadre che
// amo"): invece di doverlo disattivare profilo per profilo, un solo interruttore vale per tutti,
// anche quelli creati in futuro. Salvate come lista JSON di chiavi interne (le stesse di
// PUBLIC_NAV_ITEM_KEYS) in un'unica riga di site_settings, stesso principio già usato per le
// impostazioni di Privacy/Tracking di profilo.
function getSiteDisabledNavKeys(): array {
    $raw = getSiteSetting('disabled_nav_keys');
    if (!$raw) {
        return [];
    }
    $keys = json_decode($raw, true);
    return is_array($keys) ? $keys : [];
}


// Strumento della dashboard attivo per tutto il sito? ($key: 'bacheca', 'cinema', 'prodotti' — vedi Admin → Funzioni del sito.)
function siteToolEnabled(string $key): bool {
    return !in_array($key, getSiteDisabledNavKeys(), true);
}


function setSiteDisabledNavKeys(array $keys): void {
    setSiteSetting('disabled_nav_keys', json_encode(array_values(array_unique($keys))));
}


// Voci nascoste per QUESTO profilo — unione di ciò che il profilo stesso ha disattivato (Menu di
// Navigazione) e ciò che l'amministratore ha disattivato per tutta l'installazione: chi consuma
// questa lista (publicNav(), adminLteTopNav(), che_amo.php, dashboard_che_amo.php, il carosello
// Home...) non deve sapere da dove viene un nascondimento, gli basta questo unico elenco.
function getHiddenNavKeys(int $userId): array {
    $stmt = getDB()->prepare('SELECT name FROM profile_navigation_menu WHERE user_id = ? AND is_visible = 0');
    $stmt->execute([$userId]);
    $hidden = getSiteDisabledNavKeys();
    foreach ($stmt->fetchAll() as $row) {
        if (isset(PUBLIC_NAV_ITEM_KEYS[$row['name']])) {
            $hidden[] = PUBLIC_NAV_ITEM_KEYS[$row['name']];
        }
    }
    return $hidden;
}


// Ordine personalizzato (trascinamento in dashboard_nav_menu.php) di questo profilo, come
// chiave interna => sort_order, usata da publicNav() per riordinare il menu pubblico. Stessa
// nota di getHiddenNavKeys(): nessuna riga in tabella significa semplicemente nessun ordine
// personalizzato, publicNav() ricade sull'ordine con cui costruisce i tab.
function getNavItemOrder(int $userId): array {
    $stmt = getDB()->prepare('SELECT name, sort_order FROM profile_navigation_menu WHERE user_id = ?');
    $stmt->execute([$userId]);
    $order = [];
    foreach ($stmt->fetchAll() as $row) {
        if (isset(PUBLIC_NAV_ITEM_KEYS[$row['name']])) {
            $order[PUBLIC_NAV_ITEM_KEYS[$row['name']]] = (int) $row['sort_order'];
        }
    }
    return $order;
}


// Riporta l'ordine delle voci di menu di un profilo a quello predefinito (PUBLIC_NAV_ITEM_KEYS),
// senza toccare quali voci sono nascoste/visibili — utile a chi ha trascinato l'ordine in passato
// e vuole tornare a quello standard (allineato alla barra di gestione in dashboard) con un click,
// invece di ritrascinare a mano ogni voce.
function resetProfileNavMenuOrder(int $userId): void {
    $stmt = getDB()->prepare('UPDATE profile_navigation_menu SET sort_order = ? WHERE user_id = ? AND name = ?');
    $order = 1;
    foreach (array_keys(PUBLIC_NAV_ITEM_KEYS) as $name) {
        $stmt->execute([$order, $userId, $name]);
        $order++;
    }
}


// Ordine personalizzato (trascinamento in dashboard_nav_menu.php) della barra della dashboard di
// questo profilo, come chiave interna => sort_order — usata da _dash_header.php. Nessuna riga
// significa nessun ordine personalizzato: chi chiama ricade sull'ordine con cui costruisce i tab.
function getDashboardTabOrder(int $userId): array {
    $stmt = getDB()->prepare('SELECT tab_key, sort_order FROM dashboard_tab_order WHERE user_id = ?');
    $stmt->execute([$userId]);
    $order = [];
    foreach ($stmt->fetchAll() as $r) {
        $order[$r['tab_key']] = (int) $r['sort_order'];
    }
    return $order;
}


// Salva il nuovo ordine dopo un trascinamento in dashboard_nav_menu.php — $orderedKeys è
// l'elenco delle chiavi DASHBOARD_TAB_KEYS nell'ordine scelto dall'utente.
function saveDashboardTabOrder(int $userId, array $orderedKeys): void {
    $stmt = getDB()->prepare('INSERT INTO dashboard_tab_order (user_id, tab_key, sort_order) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)');
    foreach ($orderedKeys as $i => $key) {
        if (!isset(DASHBOARD_TAB_KEYS[$key])) {
            continue;
        }
        $stmt->execute([$userId, $key, $i + 1]);
    }
}


function resetDashboardTabOrder(int $userId): void {
    getDB()->prepare('DELETE FROM dashboard_tab_order WHERE user_id = ?')->execute([$userId]);
}
