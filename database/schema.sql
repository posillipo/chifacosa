CREATE DATABASE IF NOT EXISTS chifacosa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE chifacosa;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    email_verified TINYINT(1) NOT NULL DEFAULT 1,
    verification_token VARCHAR(64) DEFAULT NULL,
    verification_expires DATETIME DEFAULT NULL,
    account_type ENUM('band','fan','label') NOT NULL DEFAULT 'band',
    account_type_chosen TINYINT(1) NOT NULL DEFAULT 0,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_token_expires DATETIME DEFAULT NULL,
    otp_code VARCHAR(10) DEFAULT NULL,
    otp_expires_at DATETIME DEFAULT NULL,
    otp_attempts INT NOT NULL DEFAULT 0,
    legacy_gestore_id INT DEFAULT NULL,
    legacy_band_id INT DEFAULT NULL,
    legacy_stato VARCHAR(20) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profiles (
    user_id INT PRIMARY KEY,
    display_name VARCHAR(120) NOT NULL,
    bio TEXT,
    avatar_path VARCHAR(255),
    theme_color VARCHAR(7) DEFAULT '#6C5CE7',
    page_theme VARCHAR(30) NOT NULL DEFAULT 'colorful',
    dashboard_theme VARCHAR(10) NOT NULL DEFAULT 'dark',
    spotify_artist_id VARCHAR(50) DEFAULT NULL,
    spotify_artist_name VARCHAR(200) DEFAULT NULL,
    spotify_show_id VARCHAR(50) DEFAULT NULL,
    spotify_show_name VARCHAR(200) DEFAULT NULL,
    youtube_channel_id VARCHAR(50) DEFAULT NULL,
    youtube_channel_name VARCHAR(200) DEFAULT NULL,
    genere VARCHAR(100) DEFAULT NULL,
    citta VARCHAR(100) DEFAULT NULL,
    provincia VARCHAR(50) DEFAULT NULL,
    telefono VARCHAR(50) DEFAULT NULL,
    custom_feed_guid VARCHAR(500) DEFAULT NULL,
    custom_feed_guid_since DATETIME DEFAULT NULL,
    privacy_tracking_settings TEXT DEFAULT NULL,
    cinema_films_json_url VARCHAR(500) DEFAULT NULL,
    cinema_films_synced_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- link_type distingue le voci diverse nella stessa lista ordinabile: 'link' (pulsante normale,
-- comportamento originale), 'divider' (solo un titolo di sezione, non cliccabile, url resta ''),
-- 'map' (mappa OpenStreetMap incorporata, usa map_lat/map_lng invece di url), 'film' (pulsante
-- film in programmazione, creato/aggiornato automaticamente dalla sincronizzazione Cinema —
-- vedi syncCinemaFilms() in functions.php — identificato da external_ref, sempre mostrato lato
-- pubblico dopo tutti gli altri pulsanti). Tenerle nella stessa tabella (invece di tabelle
-- separate) permette di riordinarle tutte insieme con le stesse frecce sposta-su/giù già esistenti.
CREATE TABLE IF NOT EXISTS links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(120) NOT NULL,
    url VARCHAR(500) NOT NULL,
    icon VARCHAR(40) DEFAULT 'link',
    cover_path VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    click_count INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_website_icon TINYINT(1) NOT NULL DEFAULT 0,
    link_type ENUM('link','divider','map','film') NOT NULL DEFAULT 'link',
    map_lat DECIMAL(10,7) DEFAULT NULL,
    map_lng DECIMAL(10,7) DEFAULT NULL,
    external_ref VARCHAR(64) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_external_ref (user_id, external_ref)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audio_tracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    cover_path VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    venue VARCHAR(150),
    city VARCHAR(100),
    event_date DATETIME NOT NULL,
    ticket_url VARCHAR(500),
    description TEXT DEFAULT NULL,
    is_perpetual TINYINT(1) NOT NULL DEFAULT 0,
    recurrence ENUM('none','weekdays','weekend') NOT NULL DEFAULT 'none',
    cover_path VARCHAR(255) DEFAULT NULL,
    accepts_reservations TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Offerte speciali (promozioni/sconti): validità opzionale (entrambe NULL = sempre attiva),
-- is_active per sospendere manualmente senza eliminare.
CREATE TABLE IF NOT EXISTS special_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    price_label VARCHAR(100) DEFAULT NULL,
    cover_path VARCHAR(255) DEFAULT NULL,
    valid_from DATETIME DEFAULT NULL,
    valid_until DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Album fotografici: titolo, descrizione, fino a 50 foto (photo_album_photos). Stessa semantica
-- pubblicazione degli altri contenuti (show_in_feed/publish_at) per restare coerente col resto
-- del sito — a differenza della sezione "Foto" (aggregazione automatica delle foto dei post
-- Timeline, nessuna tabella propria: vedi getPublicTimelinePhotos() in functions.php).
CREATE TABLE IF NOT EXISTS photo_albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    cover_path VARCHAR(255) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS photo_album_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    album_id INT NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (album_id) REFERENCES photo_albums(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Servizi aziendali: galleria fotografica (service_photos, stesso schema di photo_album_photos),
-- accepts_inquiries attiva/disattiva il form "richiedi informazioni" lato pubblico per il singolo
-- servizio (come accepts_reservations per gli eventi).
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    cover_path VARCHAR(255) DEFAULT NULL,
    accepts_inquiries TINYINT(1) NOT NULL DEFAULT 1,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Richieste di informazioni su un servizio (stessi campi del form prenotazioni: nome, email,
-- telefono, messaggio) — is_read per la stessa gestione "letto/da leggere" di contact_requests.
CREATE TABLE IF NOT EXISTS service_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    service_id INT NOT NULL,
    guest_name VARCHAR(120) NOT NULL,
    guest_email VARCHAR(190) NOT NULL,
    guest_phone VARCHAR(30) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Prenotazioni tavolo legate a un evento (fase 1: sempre agganciate a un evento — event_id resta
-- pensato per poter restare NULL in futuro per prenotazioni "libere", senza nuove migrazioni).
CREATE TABLE IF NOT EXISTS table_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_id INT NULL,
    guest_name VARCHAR(120) NOT NULL,
    guest_email VARCHAR(190) NOT NULL,
    guest_phone VARCHAR(30) DEFAULT NULL,
    party_size SMALLINT UNSIGNED NOT NULL,
    notes VARCHAR(300) DEFAULT NULL,
    status ENUM('pending','confirmed','declined','cancelled','no_show','completed') NOT NULL DEFAULT 'confirmed',
    marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_owner_status (user_id, status),
    INDEX idx_guest_email (guest_email)
) ENGINE=InnoDB;

-- published_at è sia la data mostrata pubblicamente sia la data usata nel permalink SEO
-- (blogPostUrl()) sia — impostandola nel futuro dal form della dashboard — la programmazione
-- della pubblicazione: un articolo con published_at futuro non compare in nessuna pagina/feed
-- pubblico finché quella data non arriva (stesso principio di publish_at in photo_albums, qui
-- riusa il campo già esistente invece di aggiungerne uno parallelo). album_id collega
-- opzionalmente l'articolo a un album della sezione Foto (ON DELETE SET NULL: se l'album viene
-- eliminato l'articolo resta, semplicemente senza più l'album collegato).
CREATE TABLE IF NOT EXISTS blog_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    excerpt VARCHAR(300),
    content TEXT NOT NULL,
    cover_path VARCHAR(255) DEFAULT NULL,
    album_id INT DEFAULT NULL,
    tags VARCHAR(300) DEFAULT NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (album_id) REFERENCES photo_albums(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_user_slug (user_id, slug)
) ENGINE=InnoDB;

-- Categorie del blog: elenco gestito dal profilo (aggiungi/rinomina/elimina in dashboard_blog.php),
-- ogni articolo può averne una o più (blog_post_categories). slug usato per la pagina pubblica
-- filtrata per categoria (/nomeartista/blog/categoria/nome-categoria).
CREATE TABLE IF NOT EXISTS blog_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_slug (user_id, slug),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blog_post_categories (
    post_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (post_id, category_id),
    FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender_name VARCHAR(120) NOT NULL,
    sender_email VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Impostazioni globali del sito (chiave/valore), es. script privacy/cookie da iniettare in tutte le pagine pubbliche
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB;

-- Token per il login "ricordami": selector in chiaro (per la ricerca), validator solo come hash
-- (mai in chiaro nel database), seguendo il pattern standard selector/validator per i cookie
-- di login persistenti.
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector VARCHAR(24) NOT NULL UNIQUE,
    validator_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Follower "leggeri" (solo email, nessun account) di un artista. Il token serve sia per
-- confermare l'iscrizione (doppio opt-in anti-spam) sia, dopo la conferma, come link di
-- disiscrizione in ogni email inviata — un solo utilizzo per entrambi gli scopi.
CREATE TABLE IF NOT EXISTS followers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(190) NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    token VARCHAR(64) NOT NULL,
    accepted_terms_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_email (user_id, email),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Lista libera di band/artisti Spotify che un account "Fan" sceglie di seguire come preferiti
-- sulla propria pagina pubblica — non necessariamente band registrate su CHI FA COSA, qualsiasi
-- artista/band presente nel catalogo pubblico Spotify.
CREATE TABLE IF NOT EXISTS fan_favorite_bands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    spotify_artist_id VARCHAR(50) NOT NULL,
    spotify_artist_name VARCHAR(200) NOT NULL,
    artist_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_artist (user_id, spotify_artist_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- "Playlist che amo" e "Album che amo": playlist/album Spotify scelti dal profilo, stessa
-- struttura degli altri moduli "che amo" (note/foto/pubblicazione facoltative). Mancavano da
-- questo file pur essendo già in uso: le tabelle esistevano già nei database di produzione,
-- create in un passaggio precedente non finito nello schema di riferimento.
CREATE TABLE IF NOT EXISTS fan_favorite_playlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    spotify_playlist_id VARCHAR(50) NOT NULL,
    playlist_name VARCHAR(200) NOT NULL,
    playlist_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_playlist (user_id, spotify_playlist_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fan_favorite_albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    spotify_album_id VARCHAR(50) NOT NULL,
    album_name VARCHAR(200) NOT NULL,
    album_artist_name VARCHAR(200) DEFAULT NULL,
    album_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_album (user_id, spotify_album_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fan_favorite_actors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tmdb_person_id VARCHAR(50) NOT NULL,
    actor_name VARCHAR(200) NOT NULL,
    actor_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_actor (user_id, tmdb_person_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fan_favorite_movies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tmdb_movie_id VARCHAR(50) NOT NULL,
    movie_title VARCHAR(200) NOT NULL,
    movie_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_movie (user_id, tmdb_movie_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fan_favorite_books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    google_books_id VARCHAR(50) NOT NULL,
    book_title VARCHAR(200) NOT NULL,
    book_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_book (user_id, google_books_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Ricette preferite, cercate sul catalogo Spoonacular (stesso principio di Band/Attori/Film/Libri
-- che amo: ricerca su un catalogo esterno, non solo ricette già presenti su CHI FA COSA).
-- cached_details: dettagli (tempo di preparazione, porzioni, ingredienti, già tradotti in
-- italiano) salvati in JSON alla prima richiesta invece di richiamare Spoonacular a ogni visita
-- della pagina pubblica — il piano gratuito concede solo 150 richieste/giorno. Vedi
-- fan_favorite_item.php e la funzione spoonacularGetRecipeDetails() in spoonacular.php.
CREATE TABLE IF NOT EXISTS fan_favorite_recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    spoonacular_recipe_id VARCHAR(50) NOT NULL,
    recipe_title VARCHAR(200) NOT NULL,
    recipe_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    cached_details TEXT DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_recipe (user_id, spoonacular_recipe_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Squadre sportive preferite, cercate sul catalogo TheSportsDB (stesso principio degli altri
-- moduli "che amo" con catalogo esterno).
CREATE TABLE IF NOT EXISTS fan_favorite_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    thesportsdb_team_id VARCHAR(50) NOT NULL,
    team_name VARCHAR(200) NOT NULL,
    team_badge VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_team (user_id, thesportsdb_team_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Calciatori preferiti, cercati sul catalogo TheSportsDB (stesso principio degli altri moduli
-- "che amo" con catalogo esterno).
CREATE TABLE IF NOT EXISTS fan_favorite_players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    thesportsdb_player_id VARCHAR(50) NOT NULL,
    player_name VARCHAR(200) NOT NULL,
    player_photo VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_player (user_id, thesportsdb_player_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Partite preferite di una squadra, scelte dal calendario (prossime/ultime) di quella squadra su
-- TheSportsDB — non esiste una ricerca libera per nome di una partita qualsiasi.
CREATE TABLE IF NOT EXISTS fan_favorite_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    thesportsdb_event_id VARCHAR(50) NOT NULL,
    match_title VARCHAR(200) NOT NULL,
    match_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_match (user_id, thesportsdb_event_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- "Viaggi": diverso dagli altri moduli "che amo" perché non esiste un'API esterna gratuita con un
-- ID canonico + foto per un luogo qualsiasi (a differenza di Spotify/TMDb/Google Books) — qui
-- l'"entità" è la coppia di coordinate scelta dall'utente (ricerca libera via OpenStreetMap
-- Nominatim, o inserite a mano), niente UNIQUE: si può tornare più volte nello stesso posto e
-- raccontarlo ogni volta in un elemento diverso. map_image_path è la miniatura statica generata
-- automaticamente (Geoapify) usata come og:image quando non c'è una foto propria in image_path.
CREATE TABLE IF NOT EXISTS fan_favorite_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    place_name VARCHAR(200) NOT NULL,
    address VARCHAR(500) DEFAULT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    map_image_path VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Segui tra account (chiunque può seguire chiunque, indipendentemente dal tipo di account),
-- alimenta il feed "La mia Timeline" aggregato. Diverso dal "Segui via email" (tabella
-- followers) che resta per i visitatori senza account.
CREATE TABLE IF NOT EXISTS account_follows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    follower_user_id INT NOT NULL,
    followed_user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_follow (follower_user_id, followed_user_id),
    FOREIGN KEY (follower_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (followed_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Chat interna: consentita solo tra due account che si seguono a vicenda (verificato a ogni
-- invio, non solo alla creazione della conversazione — se uno smette di seguire l'altro, la
-- conversazione resta leggibile ma non si possono più mandare nuovi messaggi).
CREATE TABLE IF NOT EXISTS direct_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conversation (sender_id, recipient_id, created_at)
) ENGINE=InnoDB;

-- Aggiornamenti brevi pubblicati direttamente sulla Timeline (un pensiero, una foto con
-- didascalia, o entrambi) — diverso da un articolo blog completo, pensato per condivisioni
-- rapide, come il "cosa c'è di nuovo?" del vecchio CHI FA COSA.
CREATE TABLE IF NOT EXISTS timeline_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    testo TEXT DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    image_thumb_path VARCHAR(255) DEFAULT NULL,
    visibility ENUM('public','private') NOT NULL DEFAULT 'public',
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Nuovo modulo "Brani": brani Spotify scelti dal profilo (di qualsiasi tipo), al posto del
-- vecchio upload di file mp3. Stesso pattern di fan_favorite_bands, ma per singoli brani.
CREATE TABLE IF NOT EXISTS favorite_tracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    spotify_track_id VARCHAR(50) NOT NULL,
    track_name VARCHAR(200) NOT NULL,
    artist_name VARCHAR(200) DEFAULT NULL,
    track_image VARCHAR(500) DEFAULT NULL,
    spotify_url VARCHAR(500) DEFAULT NULL,
    lyrics TEXT DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_track (user_id, spotify_track_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Recensioni: solo voto (1-5 crome), nessun commento testuale. Una sola recensione per persona
-- per band/brano. Il "nome" mostrato pubblicamente è sempre lo username del recensore.
-- Registrazione solo su invito: chi vuole un account compila questa richiesta, l'admin la
-- approva o rifiuta; solo un'approvazione genera un link di registrazione valido (con token
-- monouso), da qui il nome del campo invite_token.
CREATE TABLE IF NOT EXISTS access_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    band_name VARCHAR(150) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    invite_token VARCHAR(64) DEFAULT NULL,
    invite_used TINYINT(1) NOT NULL DEFAULT 0,
    referrer_user_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME DEFAULT NULL,
    FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Co-gestione di un profilo: chi (admin_user_id) può gestire il profilo di chi (owner_user_id).
-- Il titolare (owner) sceglie tra i propri follower chi promuovere; solo il titolare può
-- aggiungere/rimuovere co-admin, non un co-admin stesso.
CREATE TABLE IF NOT EXISTS profile_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    admin_user_id INT NOT NULL,
    role ENUM('coadmin','owner') NOT NULL DEFAULT 'coadmin',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_owner_admin (owner_user_id, admin_user_id),
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Registro delle azioni fatte dai co-admin su un profilo condiviso (visibile solo quando un
-- profilo ha più di un admin attivo).
CREATE TABLE IF NOT EXISTS admin_action_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    actor_user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Visibilità dei tab standard (Home/Timeline/Blog/Brani/Menù/Eventi/Contatti) nel menu di
-- navigazione pubblico di ciascun profilo. Righe create automaticamente al primo accesso a
-- "Menu di Navigazione" in dashboard (vedi getAllProfileNavigationMenu() in functions.php) —
-- un profilo senza righe qui non ha nulla di nascosto, comportamento identico a prima di questa
-- funzionalità.
CREATE TABLE IF NOT EXISTS profile_navigation_menu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    icon VARCHAR(50) NULL,
    url VARCHAR(255) NOT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS band_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    band_user_id INT NOT NULL,
    reviewer_user_id INT NOT NULL,
    rating TINYINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_band_reviewer (band_user_id, reviewer_user_id),
    FOREIGN KEY (band_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Modulo Menù: categorie (Antipasti, Primi, ecc.) e piatti con prezzo e allergeni UE
-- (Regolamento 1169/2011, elencati come codici 1-14 in functions.php::MENU_ALLERGENS),
-- disponibile per qualsiasi tipo di account, non solo Band/Etichetta.
CREATE TABLE IF NOT EXISTS menu_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(300) DEFAULT NULL,
    price DECIMAL(6,2) DEFAULT NULL,
    allergens VARCHAR(60) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS track_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    track_id INT NOT NULL,
    reviewer_user_id INT NOT NULL,
    rating TINYINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_track_reviewer (track_id, reviewer_user_id),
    FOREIGN KEY (track_id) REFERENCES favorite_tracks(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS themes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    primary_color VARCHAR(7) NOT NULL,
    deep_color VARCHAR(7) NOT NULL,
    light_color VARCHAR(7) NOT NULL,
    accent_color VARCHAR(7) NOT NULL,
    text_primary VARCHAR(7) NOT NULL DEFAULT '#1A1A1A',
    text_secondary VARCHAR(7) NOT NULL DEFAULT '#757575',
    success_color VARCHAR(7) NOT NULL DEFAULT '#4CAF50',
    error_color VARCHAR(7) NOT NULL DEFAULT '#F44336',
    is_preset BOOLEAN DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO themes (name, description, primary_color, deep_color, light_color, accent_color, is_preset) VALUES
('La Caraffa', 'Tema blu corporativo ispirato a La Caraffa Ristorante', '#0077DD', '#003D99', '#E3F2FD', '#64B5F6', 1),
('Default', 'Tema predefinito minimalista', '#ff6b6b', '#cc5555', '#ffe8e8', '#ff8e8e', 1),
('Dark', 'Tema scuro moderno', '#1a1a1a', '#000000', '#333333', '#666666', 1);

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('current_theme_id', '1');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('gtm_head_script', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('gtm_body_script', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('fb_pixel_script', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_host', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_port', '587');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_user', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_pass', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_secure', 'tls');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_from', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_from_name', 'CHI FA COSA');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('smtp_verify_cert', '1');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('ga_measurement_id', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('privacy_policy_url', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('spotify_client_id', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('youtube_api_key', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('spotify_client_secret', '');
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('spotify_app_token', '');

-- Elementi fissati in "Primo Piano" nella Timeline pubblica a tema AdminLTE (carosello sempre in
-- cima, mostrato solo con almeno 2 pin attivi — vedi renderAdminLtePinnedCarousel() in
-- functions.php). content_type/content_id sono polimorfici (puntano a una riga qualsiasi tra i
-- ~18 tipi di contenuto uniti da getTimelineFeedForUsers(), es. 'pensiero'+id di timeline_posts,
-- 'calciatore_favorito'+id di fan_favorite_players): una sola tabella generica invece di una
-- colonna is_pinned su ognuna delle tabelle di contenuto, per poter fissare elementi "di natura
-- diversa" senza una migration su ogni tabella.
CREATE TABLE IF NOT EXISTS pinned_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content_type VARCHAR(30) NOT NULL,
    content_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    pinned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_content (user_id, content_type, content_id),
    KEY idx_user_sort (user_id, sort_order),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('spotify_app_token_expires', '');

-- Ordine personalizzato dei tasti nella barra della dashboard (Feed, Timeline, Che Amo, Primo
-- Piano...), indipendente dall'ordine del menu pubblico (profile_navigation_menu/
-- getNavItemOrder()): copre anche voci senza equivalente pubblico (Feed, Link, Primo Piano,
-- Richieste, Prenotazioni) che nell'ordine del menu pubblico non potrebbero mai comparire.
-- Riordinabile trascinando in dashboard_nav_menu.php — vedi getDashboardTabOrder() in
-- functions.php.
CREATE TABLE IF NOT EXISTS dashboard_tab_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tab_key VARCHAR(30) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_user_tab (user_id, tab_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
