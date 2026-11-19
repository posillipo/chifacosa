<?php
// Costanti condivise (temi, moduli, tipi di contenuto, fusi orari, slug riservati...).
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Palette di colori pastello per i pulsanti "azione" nel tema colorato della pagina pubblica
// Registro dei temi grafici disponibili per la pagina pubblica — aggiungerne uno nuovo in
// futuro significa solo aggiungere una voce qui + le regole CSS corrispondenti (vedi
// style.css), senza toccare le singole pagine pubbliche.
const PAGE_THEMES = [
    'colorful' => ['label' => 'Colorful', 'description' => 'Sfumatura pastello, il classico CHI FA COSA', 'body_class' => 'colorful-page'],
    'rock' => ['label' => 'Rock', 'description' => 'Sfondo scuro, angoli netti, tono più deciso', 'body_class' => 'rock-page'],
    'wave' => ['label' => 'Wave', 'description' => 'Sfondo 3D scuro, griglia di cubi che ondeggia al passaggio del mouse', 'body_class' => 'wave-page'],
    'wave-light' => ['label' => 'Wave Chiaro', 'description' => 'Stessa griglia animata, in versione chiara e più ariosa', 'body_class' => 'wave-light-page'],
    'wave-neon' => ['label' => 'Wave Neon', 'description' => 'Griglia più fitta, colonne invece di cubi, tono più notturno', 'body_class' => 'wave-neon-page'],
    'aurora' => ['label' => 'Aurora', 'description' => 'Cielo stellato che sfuma verso il tramonto, pulsanti corallo con profondità', 'body_class' => 'aurora-page'],
    'plasma' => ['label' => 'Plasma', 'description' => 'Sfumatura viola-magenta-blu satura, pulsanti arancioni a pillola', 'body_class' => 'plasma-page'],
    'golden' => ['label' => 'Golden', 'description' => 'Tramonto caldo, pulsanti bianchi minimal, atmosfera quieta', 'body_class' => 'golden-page'],
    'la-caraffa' => ['label' => 'La Caraffa', 'description' => 'Tema blu corporativo ispirato al logo di La Caraffa Ristorante', 'body_class' => 'la-caraffa-page'],
    'electric' => ['label' => 'Electric', 'description' => 'Sfondo scuro con un bordo elettrico animato attorno al profilo', 'body_class' => 'electric-page'],
    'circuit' => ['label' => 'Circuit', 'description' => 'Griglia 3D di tubi che ruotano lentamente, come un circuito stampato', 'body_class' => 'circuit-page'],
    'cosplay-blue' => ['label' => 'Cosplay Blu', 'description' => 'Sfondo blu pastello, pulsanti a pillola con bordo nero e ombra netta, stile sticker/fumetto', 'body_class' => 'cosplay-blue-page'],
    'cosplay-pink' => ['label' => 'Cosplay Rosa', 'description' => 'Stessa grafica a sticker con bordo nero, in rosa confetto', 'body_class' => 'cosplay-pink-page'],
    'cosplay-mint' => ['label' => 'Cosplay Menta', 'description' => 'Stessa grafica a sticker con bordo nero, in verde menta', 'body_class' => 'cosplay-mint-page'],
    'cosplay-yellow' => ['label' => 'Cosplay Giallo', 'description' => 'Stessa grafica a sticker con bordo nero, in giallo pastello', 'body_class' => 'cosplay-yellow-page'],
    'scifi-cyan' => ['label' => 'Fantascienza Cyan', 'description' => 'Pannelli in stile HUD di un\'astronave, bordo neon ciano su sfondo scuro con scanline', 'body_class' => 'scifi-cyan-page'],
    'scifi-magenta' => ['label' => 'Fantascienza Magenta', 'description' => 'Stessi pannelli HUD, neon magenta cyberpunk al posto del ciano', 'body_class' => 'scifi-magenta-page'],
    'horror-blood' => ['label' => 'Horror Sangue', 'description' => 'Sfondo scuro con vignettatura, angoli netti e bordi rosso sangue', 'body_class' => 'horror-blood-page'],
    'horror-fog' => ['label' => 'Horror Nebbia', 'description' => 'Stessa atmosfera inquietante, tono verde-grigio nebbioso al posto del rosso', 'body_class' => 'horror-fog-page'],
    'thriller-noir' => ['label' => 'Thriller Noir', 'description' => 'Bianco e nero da spionaggio, vignettatura a faretto, accento rosso', 'body_class' => 'thriller-noir-page'],
    'backstage' => ['label' => 'Backstage Pass', 'description' => 'Il profilo come un pass da concerto laminato, i link come una scaletta numerata, su carta chiara con luci di scena che respirano piano', 'body_class' => 'backstage-page'],
    'thriller-shadow' => ['label' => 'Thriller Ombra', 'description' => 'Stessa tensione da thriller, tono blu-grigio freddo al posto del rosso', 'body_class' => 'thriller-shadow-page'],
    'agent-gold' => ['label' => '007 Oro', 'description' => 'Nero elegante con dettagli oro, stile titoli di apertura da film di spionaggio', 'body_class' => 'agent-gold-page'],
    'agent-silver' => ['label' => '007 Argento', 'description' => 'Stessa eleganza da agente segreto, argento al posto dell\'oro', 'body_class' => 'agent-silver-page'],
    'bunny' => ['label' => 'Coniglietti', 'description' => 'Pastello rosa soffice, con vere orecchie da coniglio sull\'avatar e zampette 🐾 sui pulsanti', 'body_class' => 'bunny-page'],
    'zebra' => ['label' => 'Zebrato', 'description' => 'Lo sfondo della pagina è a vere righe zebrate bianche e nere, dettagli rosa acceso', 'body_class' => 'zebra-page'],
    'polka' => ['label' => 'Pois', 'description' => 'Sfondo giallo a pois bianchi, dettagli rosso ciliegia, tono vintage/picnic', 'body_class' => 'polka-page'],
    'meme67' => ['label' => '67', 'description' => 'Il meme "6 7": numeri giganti sullo sfondo, sfondo a raggiera, verde neon e arancione', 'body_class' => 'meme67-page'],
    'napoli' => ['label' => 'Forza Napoli', 'description' => 'Cielo azzurro sul golfo con il Vesuvio all\'orizzonte, sole che ruota dietro l\'avatar, coriandoli azzurro/oro che salgono dal basso', 'body_class' => 'napoli-page'],
    'startrek' => ['label' => 'Frontiera Stellare', 'description' => 'Ispirato a Star Trek: pannelli in stile LCARS, campo stellare animato, lampi di "salto nel warp" e un distintivo circolare originale sull\'avatar (non il logo ufficiale del franchise)', 'body_class' => 'startrek-page'],
    'galactic' => ['label' => 'Console Galattica', 'description' => 'Iperspazio animato su canvas con salto al passaggio del mouse, nebulosa che si muove, avatar olografico con scanline e glitch, pulsanti console e 4 stili di pulsante animati, cursore a lama energetica, suoni sintetizzati silenziabili — elementi originali, nessun logo o personaggio di alcun franchise', 'body_class' => 'galactic-page'],
    'cinemapop' =>['label' => 'Cinema Pop', 'description' => 'Ispirato a una sala cinematografica: sfondo scuro con un bagliore arancione da faretto dietro l\'avatar, pellicola con fori da film in alto e in basso, popcorn dorati che salgono dal basso in continuo, pulsanti a righe come un secchiello di popcorn con un riflesso lucido che scorre — colori e atmosfera originali, nessun logo di alcun cinema', 'body_class' => 'cinemapop-page'],
    'nightdrop' => ['label' => 'Drop Notturno', 'description' => 'Sfondo blu notte da vetrina "shop", pulsanti bianchi a pillola con freccina a cerchio sulla destra, un pulsante ogni tanto in rosso acceso come evidenziato, card scure per le griglie di contenuto', 'body_class' => 'nightdrop-page'],
    'sartoria' => ['label' => 'Sartoria Elegante', 'description' => 'Nero caldo con dettagli oro e un sottile motivo a righe sartoriali (pinstripe) sullo sfondo, pulsanti bordati e titoli in serif — pensato per una boutique o sartoria uomo elegante', 'body_class' => 'sartoria-page'],
    'geolierstyle' => ['label' => 'GeolierStyle', 'description' => 'Atmosfera cinematografica scura ispirata alle grafiche da "link in bio" urban/trap: foto profilo a copertina piena con sfumatura grigio scuro neutra, titolo maiuscolo grassetto, pulsanti a rettangolo grigio ardesia con bordo sottile, icone social a cerchio contornato', 'body_class' => 'geolierstyle-page'],
    // body_class = 'colorful-page' di proposito, non un nome dedicato: il tema AdminLTE sostituisce
    // solo la Home (vedi il primo "if" in u.php), le altre pagine pubbliche (Timeline, Blog...) non
    // hanno mai avuto un vestito AdminLTE e continuano a usare lo scheletro condiviso — senza
    // questo fallback prenderebbero una classe CSS che non esiste da nessuna parte, cioè nessuno
    // stile, non "Colorful in prestito".
    'adminlte-profile' => ['label' => 'AdminLTE', 'description' => 'Layout a card in stile pannello gestionale (basato su AdminLTE 4): card "About" con follower e recensioni, i tuoi link, e le sezioni del sito raccolte in tab — Timeline, Che Amo, Podcast, Blog e le altre che hai attive. Vale solo per la Home: le altre pagine restano nel tema Colorful.', 'body_class' => 'colorful-page'],
    // body_class = 'colorful-page' per lo stesso motivo di adminlte-profile qui sopra: per ora
    // solo la Home ha la veste "PA Italia" (bootstrap-italia, il design system open source della
    // Pubblica Amministrazione italiana), le altre pagine pubbliche restano nel tema Colorful.
    'pa-italia' => ['label' => 'PA Italia', 'description' => 'Ispirato al design system ufficiale della Pubblica Amministrazione italiana (bootstrap-italia): intestazione con logo/nome, tipografia Titillium Web, card istituzionali per Timeline e Chi sono. Vale solo per la Home: le altre pagine restano nel tema Colorful.', 'body_class' => 'colorful-page'],
];


// Parametri della griglia 3D per ciascuna variante Wave — stesso script (wave-bg.js), letto
// tramite attributi data-* sulla canvas, così ogni variante cambia forma/dimensione/colori
// senza duplicare codice JavaScript.
const WAVE_THEME_PARAMS = [
    'wave' => ['base' => '#1a1a1a', 'shape' => 'box', 'gridSize' => 22, 'cubeSize' => 0.75, 'gap' => 0.18, 'cubeHeight' => 2.4, 'ambientIntensity' => 0.6, 'lightIntensity' => 2.2],
    'wave-light' => ['base' => '#d8d8e0', 'shape' => 'box', 'gridSize' => 18, 'cubeSize' => 0.85, 'gap' => 0.28, 'cubeHeight' => 1.6, 'ambientIntensity' => 1.1, 'lightIntensity' => 1.6],
    'wave-neon' => ['base' => '#0d0d14', 'shape' => 'cylinder', 'gridSize' => 30, 'cubeSize' => 0.55, 'gap' => 0.08, 'cubeHeight' => 2.8, 'ambientIntensity' => 0.4, 'lightIntensity' => 2.6],
];


// Icona Bootstrap Icons per ciascun modulo "che amo" (CHE_AMO_MODULES usa icone FontAwesome, non
// caricate in questo tema) — condivisa fra il tab Che Amo della Home e la vetrina standalone
// (che_amo.php).
const ADMINLTE_CHE_AMO_ICONS = [
    'bandcheamo' => 'bi-heart-pulse', 'attorichamo' => 'bi-mask', 'filmcheamo' => 'bi-film',
    'libricheamo' => 'bi-book', 'viaggi' => 'bi-airplane', 'brani' => 'bi-music-note-beamed',
    'playlistcheamo' => 'bi-music-note-list', 'albumcheamo' => 'bi-disc', 'ricettecheamo' => 'bi-egg-fried',
    'squadrecheamo' => 'bi-shield-fill', 'calciatoricheamo' => 'bi-person-badge-fill', 'partitecheamo' => 'bi-calendar-event-fill',
    'pubblicazionicheamo' => 'bi-journal-medical',
];


// Configurazione condivisa dei 6 moduli "che amo" con contenuto arricchito da un'API esterna
// (Spotify/TMDb/Google Books) — stessa forma di FAN_FAVORITE_KINDS in fan_favorite_item.php (tema
// Colorful), duplicata qui volutamente: due temi che vivono in file diversi, senza <head>/<body>
// condivisi — tenerle vicine al proprio tema evita un accoppiamento sottile tra i due.
const ADMINLTE_FAN_FAVORITE_KINDS = [
    'band' => ['table' => 'fan_favorite_bands', 'external_id_col' => 'spotify_artist_id', 'name_col' => 'spotify_artist_name', 'image_col' => 'artist_image', 'label' => 'Band che amo', 'nav_key' => 'bandcheamo', 'list_url_segment' => 'band-che-amo', 'external_label' => 'Vedi su Spotify', 'external_url' => 'https://open.spotify.com/artist/', 'image_shape' => 'circle'],
    'actor' => ['table' => 'fan_favorite_actors', 'external_id_col' => 'tmdb_person_id', 'name_col' => 'actor_name', 'image_col' => 'actor_image', 'label' => 'Attori che amo', 'nav_key' => 'attorichamo', 'list_url_segment' => 'attori-che-amo', 'external_label' => 'Vedi su TMDb', 'external_url' => 'https://www.themoviedb.org/person/', 'image_shape' => 'circle'],
    'movie' => ['table' => 'fan_favorite_movies', 'external_id_col' => 'tmdb_movie_id', 'name_col' => 'movie_title', 'image_col' => 'movie_image', 'label' => 'Film che amo', 'nav_key' => 'filmcheamo', 'list_url_segment' => 'film-che-amo', 'external_label' => 'Vedi su TMDb', 'external_url' => 'https://www.themoviedb.org/movie/', 'image_shape' => 'circle'],
    'book' => ['table' => 'fan_favorite_books', 'external_id_col' => 'google_books_id', 'name_col' => 'book_title', 'image_col' => 'book_image', 'label' => 'Libri che amo', 'nav_key' => 'libricheamo', 'list_url_segment' => 'libri-che-amo', 'external_label' => 'Vedi su Google Books', 'external_url' => 'https://books.google.com/books?id=', 'image_shape' => 'book'],
    'playlist' => ['table' => 'fan_favorite_playlists', 'external_id_col' => 'spotify_playlist_id', 'name_col' => 'playlist_name', 'image_col' => 'playlist_image', 'label' => 'Playlist che amo', 'nav_key' => 'playlistcheamo', 'list_url_segment' => 'playlist-che-amo', 'external_label' => 'Ascolta su Spotify', 'external_url' => 'https://open.spotify.com/playlist/', 'image_shape' => 'square'],
    'album' => ['table' => 'fan_favorite_albums', 'external_id_col' => 'spotify_album_id', 'name_col' => 'album_name', 'image_col' => 'album_image', 'label' => 'Album che amo', 'nav_key' => 'albumcheamo', 'list_url_segment' => 'album-che-amo', 'external_label' => 'Ascolta su Spotify', 'external_url' => 'https://open.spotify.com/album/', 'image_shape' => 'square'],
    'recipe' => ['table' => 'fan_favorite_recipes', 'external_id_col' => 'spoonacular_recipe_id', 'name_col' => 'recipe_title', 'image_col' => 'recipe_image', 'label' => 'Ricette che amo', 'nav_key' => 'ricettecheamo', 'list_url_segment' => 'ricette-che-amo', 'external_label' => 'Vedi ricetta completa', 'external_url' => 'https://spoonacular.com/recipes/-', 'image_shape' => 'square'],
    'team' => ['table' => 'fan_favorite_teams', 'external_id_col' => 'thesportsdb_team_id', 'name_col' => 'team_name', 'image_col' => 'team_badge', 'label' => 'Squadre che amo', 'nav_key' => 'squadrecheamo', 'list_url_segment' => 'squadre-che-amo', 'external_label' => 'Vedi su TheSportsDB', 'external_url' => 'https://www.thesportsdb.com/team/', 'image_shape' => 'square'],
    'footballer' => ['table' => 'fan_favorite_players', 'external_id_col' => 'thesportsdb_player_id', 'name_col' => 'player_name', 'image_col' => 'player_photo', 'label' => 'Calciatori che amo', 'nav_key' => 'calciatoricheamo', 'list_url_segment' => 'calciatori-che-amo', 'external_label' => 'Vedi su TheSportsDB', 'external_url' => 'https://www.thesportsdb.com/player/', 'image_shape' => 'circle'],
    'match' => ['table' => 'fan_favorite_matches', 'external_id_col' => 'thesportsdb_event_id', 'name_col' => 'match_title', 'image_col' => 'match_image', 'label' => 'Partite che amo', 'nav_key' => 'partitecheamo', 'list_url_segment' => 'partite-che-amo', 'external_label' => 'Vedi su TheSportsDB', 'external_url' => 'https://www.thesportsdb.com/event/', 'image_shape' => 'square'],
    'publication' => ['table' => 'fan_favorite_publications', 'external_id_col' => 'crossref_doi', 'name_col' => 'publication_title', 'image_col' => 'publication_image', 'label' => 'Pubblicazioni che amo', 'nav_key' => 'pubblicazionicheamo', 'list_url_segment' => 'pubblicazioni-che-amo', 'external_label' => 'Apri pubblicazione (DOI)', 'external_url' => 'https://doi.org/', 'image_shape' => 'book'],
];


// Icona/colore per ogni "tipo" prodotto da getTimelineFeedForUsers() — usati dal widget .timeline
// reale di AdminLTE (UI/timeline.html), condiviso fra il tab Timeline della Home a tema AdminLTE e
// la pagina Timeline standalone dello stesso tema (vedi renderAdminLteTimelineRows()).
const ADMINLTE_TIMELINE_TYPE_META = [
    'pensiero' => ['icon' => 'bi-chat-text', 'color' => 'primary', 'label' => 'Pensiero'],
    'blog' => ['icon' => 'bi-newspaper', 'color' => 'info', 'label' => 'Blog'],
    'brano' => ['icon' => 'bi-music-note', 'color' => 'warning', 'label' => 'Brano che amo'],
    'evento' => ['icon' => 'bi-calendar-event', 'color' => 'success', 'label' => 'Evento'],
    'offerta' => ['icon' => 'bi-tag', 'color' => 'danger', 'label' => 'Offerta'],
    'servizio' => ['icon' => 'bi-briefcase', 'color' => 'secondary', 'label' => 'Servizio'],
    'band_favorita' => ['icon' => 'bi-heart-pulse', 'color' => 'danger', 'label' => 'Band che amo'],
    'attore_favorito' => ['icon' => 'bi-mask', 'color' => 'secondary', 'label' => 'Attore che amo'],
    'film_favorito' => ['icon' => 'bi-film', 'color' => 'info', 'label' => 'Film che amo'],
    'libro_favorito' => ['icon' => 'bi-book', 'color' => 'primary', 'label' => 'Libro che amo'],
    'viaggio_favorito' => ['icon' => 'bi-airplane', 'color' => 'success', 'label' => 'Viaggio'],
    'playlist_favorita' => ['icon' => 'bi-music-note-list', 'color' => 'warning', 'label' => 'Playlist che amo'],
    'album_favorito' => ['icon' => 'bi-disc', 'color' => 'primary', 'label' => 'Album che amo'],
    'album_foto' => ['icon' => 'bi-images', 'color' => 'secondary', 'label' => 'Album foto'],
    'video' => ['icon' => 'bi-play-btn-fill', 'color' => 'danger', 'label' => 'Video'],
    'ricetta_favorita' => ['icon' => 'bi-egg-fried', 'color' => 'warning', 'label' => 'Ricetta che amo'],
    'squadra_favorita' => ['icon' => 'bi-shield-fill', 'color' => 'success', 'label' => 'Squadra che amo'],
    'calciatore_favorito' => ['icon' => 'bi-person-badge-fill', 'color' => 'primary', 'label' => 'Calciatore che amo'],
    'partita_favorita' => ['icon' => 'bi-calendar-event-fill', 'color' => 'warning', 'label' => 'Partita che amo'],
    'pubblicazione_favorita' => ['icon' => 'bi-journal-medical', 'color' => 'info', 'label' => 'Pubblicazione che amo'],
];


const COLORFUL_PALETTE = ['#FFD6A5', '#FDFFB6', '#CAFFBF', '#9BF6FF', '#A0C4FF', '#BDB2FF', '#FFC6FF', '#FFADAD'];


// I 14 allergeni ad etichettatura obbligatoria nell'UE (Regolamento 1169/2011, Allegato II),
// numerati come da convenzione comune sui menu dei ristoranti italiani.
const MENU_ALLERGENS = [
    1 => 'Cereali contenenti glutine',
    2 => 'Crostacei',
    3 => 'Uova',
    4 => 'Pesce',
    5 => 'Arachidi',
    6 => 'Soia',
    7 => 'Latte (incluso lattosio)',
    8 => 'Frutta a guscio',
    9 => 'Sedano',
    10 => 'Senape',
    11 => 'Semi di sesamo',
    12 => 'Anidride solforosa e solfiti',
    13 => 'Lupini',
    14 => 'Molluschi',
];


// Elenco dei moduli "che amo" raccolti nella vetrina unica (che_amo.php / dashboard_che_amo.php)
// — chiave interna (usata anche in profile_navigation_menu tramite PUBLIC_NAV_ITEM_KEYS) => nome
// visualizzato, funzione che dice se il profilo ha contenuto, segmento URL pubblico. "Brani che
// amo" non ha una funzione di controllo contenuto: non l'ha mai avuta (era il modulo originale,
// sempre mostrato), si mantiene lo stesso comportamento anche da dentro la vetrina.
const CHE_AMO_MODULES = [
    'bandcheamo' => ['label' => 'Band che amo', 'icon' => 'fas fa-heart-circle-check', 'check' => 'hasFanFavoriteBands', 'segment' => 'band-che-amo', 'table' => 'fan_favorite_bands'],
    'attorichamo' => ['label' => 'Attori che amo', 'icon' => 'fas fa-clapperboard', 'check' => 'hasFanFavoriteActors', 'segment' => 'attori-che-amo', 'table' => 'fan_favorite_actors'],
    'filmcheamo' => ['label' => 'Film che amo', 'icon' => 'fas fa-film', 'check' => 'hasFanFavoriteMovies', 'segment' => 'film-che-amo', 'table' => 'fan_favorite_movies'],
    'libricheamo' => ['label' => 'Libri che amo', 'icon' => 'fas fa-book', 'check' => 'hasFanFavoriteBooks', 'segment' => 'libri-che-amo', 'table' => 'fan_favorite_books'],
    'viaggi' => ['label' => 'Viaggi', 'icon' => 'fas fa-plane', 'check' => 'hasFanFavoriteTrips', 'segment' => 'viaggi', 'table' => 'fan_favorite_trips'],
    'brani' => ['label' => 'Brani che amo', 'icon' => 'fas fa-music', 'check' => null, 'segment' => 'brani', 'table' => 'favorite_tracks'],
    'playlistcheamo' => ['label' => 'Playlist che amo', 'icon' => 'fas fa-list-ul', 'check' => 'hasFanFavoritePlaylists', 'segment' => 'playlist-che-amo', 'table' => 'fan_favorite_playlists'],
    'albumcheamo' => ['label' => 'Album che amo', 'icon' => 'fas fa-compact-disc', 'check' => 'hasFanFavoriteAlbums', 'segment' => 'album-che-amo', 'table' => 'fan_favorite_albums'],
    'ricettecheamo' => ['label' => 'Ricette che amo', 'icon' => 'fas fa-bowl-food', 'check' => 'hasFanFavoriteRecipes', 'segment' => 'ricette-che-amo', 'table' => 'fan_favorite_recipes'],
    'squadrecheamo' => ['label' => 'Squadre che amo', 'icon' => 'fas fa-futbol', 'check' => 'hasFanFavoriteTeams', 'segment' => 'squadre-che-amo', 'table' => 'fan_favorite_teams'],
    'calciatoricheamo' => ['label' => 'Calciatori che amo', 'icon' => 'fas fa-shirt', 'check' => 'hasFanFavoritePlayers', 'segment' => 'calciatori-che-amo', 'table' => 'fan_favorite_players'],
    'partitecheamo' => ['label' => 'Partite che amo', 'icon' => 'fas fa-calendar-check', 'check' => 'hasFanFavoriteMatches', 'segment' => 'partite-che-amo', 'table' => 'fan_favorite_matches'],
    'pubblicazionicheamo' => ['label' => 'Pubblicazioni che amo', 'icon' => 'fas fa-microscope', 'check' => 'hasFanFavoritePublications', 'segment' => 'pubblicazioni-che-amo', 'table' => 'fan_favorite_publications'],
];


// Fusi orari selezionabili dal profilo (Dashboard → Profilo e anagrafica) per correggere la
// visualizzazione di date/orari quando il server non è nello stesso fuso dei visitatori — elenco
// curato (non tutti i ~596 nomi IANA) apposta: la chiave va nella colonna profiles.dashboard_theme
// (VARCHAR(10)), un campo rimasto inutilizzato da quando la scelta del tema dashboard chiaro/scuro
// è stata abbandonata (vedi nota in _dash_header.php) — riusarlo evita una migrazione del database
// per una nuova colonna. Il valore è il vero nome IANA, usato con DateTimeZone: gestisce
// correttamente anche l'ora legale, a differenza di un semplice offset fisso.
const TIMEZONE_OPTIONS = [
    'rome' => ['tz' => 'Europe/Rome', 'label' => 'Italia (Roma)'],
    'london' => ['tz' => 'Europe/London', 'label' => 'Regno Unito (Londra)'],
    'paris' => ['tz' => 'Europe/Paris', 'label' => 'Francia (Parigi)'],
    'madrid' => ['tz' => 'Europe/Madrid', 'label' => 'Spagna (Madrid)'],
    'berlin' => ['tz' => 'Europe/Berlin', 'label' => 'Germania (Berlino)'],
    'lisbon' => ['tz' => 'Europe/Lisbon', 'label' => 'Portogallo (Lisbona)'],
    'athens' => ['tz' => 'Europe/Athens', 'label' => 'Grecia (Atene)'],
    'moscow' => ['tz' => 'Europe/Moscow', 'label' => 'Russia (Mosca)'],
    'newyork' => ['tz' => 'America/New_York', 'label' => 'USA Est (New York)'],
    'chicago' => ['tz' => 'America/Chicago', 'label' => 'USA Centrale (Chicago)'],
    'denver' => ['tz' => 'America/Denver', 'label' => 'USA Montagne (Denver)'],
    'la' => ['tz' => 'America/Los_Angeles', 'label' => 'USA Pacifico (Los Angeles)'],
    'saopaulo' => ['tz' => 'America/Sao_Paulo', 'label' => 'Brasile (San Paolo)'],
    'dubai' => ['tz' => 'Asia/Dubai', 'label' => 'Emirati (Dubai)'],
    'mumbai' => ['tz' => 'Asia/Kolkata', 'label' => 'India (Mumbai)'],
    'tokyo' => ['tz' => 'Asia/Tokyo', 'label' => 'Giappone (Tokyo)'],
    'shanghai' => ['tz' => 'Asia/Shanghai', 'label' => 'Cina (Shanghai)'],
    'sydney' => ['tz' => 'Australia/Sydney', 'label' => 'Australia (Sydney)'],
    'utc' => ['tz' => 'UTC', 'label' => 'UTC'],
];


// Nomi dei mesi in italiano: il sito non ha mai avuto bisogno finora di stampare un mese per
// esteso (solo date numeriche, d/m/Y), quindi non c'è un formattatore locale già pronto — niente
// setlocale()/intl (dipenderebbero da cosa ha installato il container), un semplice array basta.
const ITALIAN_MONTHS = [1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];


// ===== Elementi fissati in "Primo Piano" (pinned_items) =====
// Config per i tipi di contenuto pinnabili — stessa lista di 'tipo' prodotta da
// getTimelineFeedForUsers() qui sopra, usata sia dalla ricerca di dashboard_featured.php sia da
// getPinnedItemsForUser() per recuperare titolo/copertina/URL di un pin. 'visibility' seleziona
// quale regola applicare quando si mostra il carosello sul sito pubblico: 'standard' = is_public +
// publish_at (la maggioranza dei moduli "che amo"), 'timeline' = colonna visibility enum di
// timeline_posts, 'blog' = solo published_at, 'offerta' = is_active + valid_from/valid_until,
// 'none' = nessun filtro (eventi, che oggi non hanno una programmazione propria).
const PINNABLE_CONTENT_TYPES = [
    'pensiero' => ['table' => 'timeline_posts', 'title_col' => 'testo', 'cover_cols' => ['image_thumb_path', 'image_path'], 'date_col' => 'created_at', 'visibility' => 'timeline', 'url_tpl' => '/%s/timeline/%d', 'label' => 'Pensiero'],
    'blog' => ['table' => 'blog_posts', 'title_col' => 'title', 'cover_cols' => ['cover_path'], 'date_col' => 'published_at', 'visibility' => 'blog', 'url_tpl' => null, 'label' => 'Blog'],
    'brano' => ['table' => 'favorite_tracks', 'title_col' => 'track_name', 'cover_cols' => ['image_thumb_path', 'image_path', 'track_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/brani/%d/scheda', 'label' => 'Brano che amo'],
    'evento' => ['table' => 'events', 'title_col' => 'title', 'cover_cols' => ['cover_path'], 'date_col' => 'created_at', 'visibility' => 'evento', 'url_tpl' => '/%s/eventi/%d', 'label' => 'Evento'],
    'band_favorita' => ['table' => 'fan_favorite_bands', 'title_col' => 'spotify_artist_name', 'cover_cols' => ['image_thumb_path', 'image_path', 'artist_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/band-che-amo/%d', 'label' => 'Band che amo'],
    'attore_favorito' => ['table' => 'fan_favorite_actors', 'title_col' => 'actor_name', 'cover_cols' => ['image_thumb_path', 'image_path', 'actor_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/attori-che-amo/%d', 'label' => 'Attore che amo'],
    'film_favorito' => ['table' => 'fan_favorite_movies', 'title_col' => 'movie_title', 'cover_cols' => ['image_thumb_path', 'image_path', 'movie_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/film-che-amo/%d', 'label' => 'Film che amo'],
    'libro_favorito' => ['table' => 'fan_favorite_books', 'title_col' => 'book_title', 'cover_cols' => ['image_thumb_path', 'image_path', 'book_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/libri-che-amo/%d', 'label' => 'Libro che amo'],
    'viaggio_favorito' => ['table' => 'fan_favorite_trips', 'title_col' => 'place_name', 'cover_cols' => ['image_thumb_path', 'image_path', 'map_image_path'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/viaggi/%d', 'label' => 'Viaggio'],
    'playlist_favorita' => ['table' => 'fan_favorite_playlists', 'title_col' => 'playlist_name', 'cover_cols' => ['image_thumb_path', 'image_path', 'playlist_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/playlist-che-amo/%d', 'label' => 'Playlist che amo'],
    'album_favorito' => ['table' => 'fan_favorite_albums', 'title_col' => 'album_name', 'cover_cols' => ['image_thumb_path', 'image_path', 'album_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/album-che-amo/%d', 'label' => 'Album che amo'],
    'ricetta_favorita' => ['table' => 'fan_favorite_recipes', 'title_col' => 'recipe_title', 'cover_cols' => ['image_thumb_path', 'image_path', 'recipe_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/ricette-che-amo/%d', 'label' => 'Ricetta che amo'],
    'squadra_favorita' => ['table' => 'fan_favorite_teams', 'title_col' => 'team_name', 'cover_cols' => ['image_thumb_path', 'image_path', 'team_badge'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/squadre-che-amo/%d', 'label' => 'Squadra che amo'],
    'calciatore_favorito' => ['table' => 'fan_favorite_players', 'title_col' => 'player_name', 'cover_cols' => ['image_thumb_path', 'image_path', 'player_photo'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/calciatori-che-amo/%d', 'label' => 'Calciatore che amo'],
    'partita_favorita' => ['table' => 'fan_favorite_matches', 'title_col' => 'match_title', 'cover_cols' => ['image_thumb_path', 'image_path', 'match_image'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/partite-che-amo/%d', 'label' => 'Partita che amo'],
    'pubblicazione_favorita' => ['table' => 'fan_favorite_publications', 'title_col' => 'publication_title', 'cover_cols' => ['image_thumb_path', 'image_path'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/pubblicazioni-che-amo/%d', 'label' => 'Pubblicazione che amo'],
    'offerta' => ['table' => 'special_offers', 'title_col' => 'title', 'cover_cols' => ['cover_path'], 'date_col' => 'created_at', 'visibility' => 'offerta', 'url_tpl' => '/%s/offerte/%d', 'label' => 'Offerta'],
    'album_foto' => ['table' => 'photo_albums', 'title_col' => 'title', 'cover_cols' => ['cover_path'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/album/%d', 'label' => 'Album foto'],
    'video' => ['table' => 'videos', 'title_col' => 'title', 'cover_cols' => ['thumb_path'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/video/%d', 'label' => 'Video'],
    'servizio' => ['table' => 'services', 'title_col' => 'title', 'cover_cols' => ['cover_path'], 'date_col' => 'created_at', 'visibility' => 'standard', 'url_tpl' => '/%s/servizi/%d', 'label' => 'Servizio'],
];


// Pagina dashboard dove gestire/modificare un elemento programmato di un certo tipo — usata dal
// link "Modifica" di dashboard_schedule.php. '%d' viene sostituito con l'id della riga solo per i
// tipi che lo prevedono (sprintf ignora l'argomento in più per gli altri URL, senza errori).
const SCHEDULABLE_DASHBOARD_URLS = [
    'pensiero' => '/dashboard_timeline_edit.php?id=%d',
    'blog' => '/dashboard_blog_edit.php?id=%d',
    'brano' => '/dashboard_audio.php',
    'band_favorita' => '/dashboard_fan_bands.php',
    'attore_favorito' => '/dashboard_fan_actors.php',
    'film_favorito' => '/dashboard_fan_movies.php',
    'libro_favorito' => '/dashboard_fan_books.php',
    'viaggio_favorito' => '/dashboard_fan_trips.php',
    'playlist_favorita' => '/dashboard_fan_playlists.php',
    'album_favorito' => '/dashboard_fan_albums.php',
    'ricetta_favorita' => '/dashboard_fan_recipes.php',
    'squadra_favorita' => '/dashboard_fan_teams.php',
    'calciatore_favorito' => '/dashboard_fan_players.php',
    'partita_favorita' => '/dashboard_fan_matches.php',
    'pubblicazione_favorita' => '/dashboard_fan_publications.php',
    'offerta' => '/dashboard_offers.php',
    'album_foto' => '/dashboard_albums.php',
    'video' => '/dashboard_videos.php',
    'servizio' => '/dashboard_services.php',
    'evento' => '/dashboard_events.php',
];


// Converte event_date (salvato nel fuso del server, vedi formatLocalDateTime()) in un timestamp
// UTC nel formato richiesto sia dai link Google Calendar sia dal file .ics (YYYYMMDDTHHMMSSZ).
// $addHours sposta l'orario, usato per calcolare l'orario di fine (nessuna colonna "durata" in
// events, si assume una durata fissa di default — vedi EVENT_CALENDAR_DEFAULT_DURATION_HOURS).
const EVENT_CALENDAR_DEFAULT_DURATION_HOURS = 2;


// Whitelist di route dell'app: uno slug musicista non può collidere con queste pagine
const RESERVED_SLUGS = ['login','register','logout','dashboard','dashboard_profile',
    'dashboard_links','dashboard_audio','dashboard_events','dashboard_blog',
    'dashboard_contacts','u','index','assets','uploads','blog','contatti','link',
    'admin','admin_users','admin_user_detail','admin_privacy','brani','eventi',
    'verify','resend_verification','admin_dashboard','admin_user_edit','admin_contacts','admin_tracking','admin_smtp',
    'admin_spotify','dashboard_spotify','follow','follow_confirm','follow_unsubscribe','dashboard_followers',
    'admin_import_legacy','admin_profiles','track','evento','admin_youtube','dashboard_youtube','video','youtube','dashboard_videos',
    'forgot_password','reset_password','dashboard_podcast','podcast',
    'choose_account_type','dashboard_fan_bands','band_che_amo','admin_apply_percorso','admin_link_avatars',
    'follow_account','dashboard_timeline','timeline','dashboard_post','timeline_post','feed','admin_import_old_timeline','timeline_more','track_review','admin_reviews','dashboard_password','dashboard_timeline_more',
    'login_otp_request','login_otp_verify','request_access','admin_access_requests','dashboard_theme','credits',
    'dashboard_invite','dashboard_following','dashboard_team','dashboard_log','track_lyrics',
    'dashboard_messages','dashboard_chat','menu','dashboard_menu',
    'reserve_table','dashboard_reservations','dashboard_profiles','dashboard_feed','sitemap','robots',
    'dashboard_fan_actors','attori_che_amo','admin_gemini','dashboard_ai_caption','admin_tmdb',
    'dashboard_fan_movies','film_che_amo','fan_favorite_item',
    'dashboard_fan_books','libri_che_amo','admin_googlebooks',
    'dashboard_fan_publications','pubblicazioni_che_amo','admin_crossref',
    'admin_turnstile', 'dashboard_schedule',
    'dashboard_cinema','cron_cinema_sync','dashboard_products','contest','dashboard_contest','contest_vote_confirm','contest_entry','contest_sponsor_preview','contest_page','favorite_track_item',
    'dashboard_fan_trips','viaggi','viaggio_item','admin_geoapify',
    'auth_google_start','auth_google_callback','admin_google_login','onboarding_setup',
    'dashboard_che_amo','che_amo',
    'dashboard_fan_playlists','playlist_che_amo','dashboard_fan_albums','album_che_amo',
    'dashboard_api_tokens','api','admin_mcp','guida','admin_page_help','api_profile_search','dashboard_profile_view','api_username_check'];


// ============================================
// PROFILE NAVIGATION MENU FUNCTIONS
// ============================================
// Permette a ogni profilo di nascondere singoli tab del proprio menu di navigazione pubblico,
// incluse le integrazioni (Spotify/Podcast/Video) e il pulsante Segui — questi ultimi restano
// comunque governati anche dalla loro condizione di base (es. Spotify compare solo se
// effettivamente collegato): nasconderli qui li nasconde sempre, ma non basta da solo a farli
// comparire se quella condizione non è soddisfatta.

// Mappa tra il "name" salvato in profile_navigation_menu e la chiave interna usata da
// publicNav() per identificare ciascun tab — tenerle distinte evita di legare lo schema del
// database ai nomi visualizzati (che potrebbero cambiare) o a caratteri accentati nelle chiavi.
// L'ordine qui rispecchia l'ordine con cui i tab compaiono davvero nel menu pubblico (publicNav())
// e nella checklist di dashboard_nav_menu.php, allineato anche a quello della barra di gestione
// in dashboard (_dash_header.php: Feed, Timeline, Link, Band/Attori/Film/Libri che amo, Viaggi,
// Blog, Brani che amo, Menù, Eventi, Segui/Follower, Contatti) — Home apre l'elenco (non ha un
// equivalente in dashboard) e Spotify/Podcast/Video (integrazioni senza una voce nella barra di
// gestione) restano raggruppate subito dopo Viaggi, come già erano.
// "Che Amo" è il tab riepilogativo (vetrina) che sostituisce quelli che una volta erano tab
// individuali per Band/Attori/Film/Libri che amo, Viaggi e Brani che amo — quelle voci restano
// comunque qui sotto: controllano se la card del singolo modulo compare nella vetrina, non più
// se il modulo ha un proprio tab. Vedi CHE_AMO_MODULES più sopra per l'elenco completo.
const PUBLIC_NAV_ITEM_KEYS = [
    'Home' => 'home',
    'Timeline' => 'timeline',
    'Link' => 'link',
    'Che Amo' => 'cheamo',
    'Band che amo' => 'bandcheamo',
    'Attori che amo' => 'attorichamo',
    'Film che amo' => 'filmcheamo',
    'Libri che amo' => 'libricheamo',
    'Viaggi' => 'viaggi',
    'Spotify' => 'spotify',
    'Podcast' => 'podcast',
    'YouTube' => 'youtube',
    'Video' => 'video',
    'Blog' => 'blog',
    'Brani che amo' => 'brani',
    'Playlist che amo' => 'playlistcheamo',
    'Album che amo' => 'albumcheamo',
    'Ricette che amo' => 'ricettecheamo',
    'Squadre che amo' => 'squadrecheamo',
    'Calciatori che amo' => 'calciatoricheamo',
    'Partite che amo' => 'partitecheamo',
    'Pubblicazioni che amo' => 'pubblicazionicheamo',
    'Menù' => 'menu',
    'Offerte' => 'offerte',
    'Foto' => 'foto',
    'Servizi' => 'servizi',
    'Eventi' => 'eventi',
    'Contest' => 'contest',
    'Segui' => 'segui',
    'Contatti' => 'contatti',
];


// ===== Ordine dei tasti nella barra della dashboard (indipendente dal menu pubblico) =====
// Elenco/etichette dei tasti riordinabili, nell'ordine predefinito — usato sia come fallback per
// chi non ha mai riordinato nulla sia dal tasto "Ripristina l'ordine predefinito" in
// dashboard_nav_menu.php. A differenza di PUBLIC_NAV_ITEM_KEYS/profile_navigation_menu (che
// riguarda solo le sezioni della pagina pubblica), copre anche voci senza equivalente pubblico:
// Feed e Primo Piano sono strumenti solo di gestione, Richieste/Prenotazioni sono le sotto-voci
// di Servizi/Eventi che nella barra della dashboard compaiono come tasti a sé.
const DASHBOARD_TAB_KEYS = [
    'feed' => 'Feed', 'timeline' => 'Timeline', 'link' => 'Link', 'featured' => 'Primo Piano',
    'cheamo' => 'Che Amo', 'blog' => 'Blog', 'menu' => 'Menù', 'offerte' => 'Offerte', 'foto' => 'Album', 'video' => 'Video', 'contest' => 'Contest',
    'servizi' => 'Servizi', 'service_inquiries' => 'Richieste', 'eventi' => 'Eventi', 'reservations' => 'Prenotazioni',
    'segui' => 'Follower', 'contatti' => 'Contatti', 'api' => 'API', 'schedule' => 'Programmati',
];
