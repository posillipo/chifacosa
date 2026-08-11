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
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- link_type distingue tre voci diverse nella stessa lista ordinabile: 'link' (pulsante normale,
-- comportamento originale), 'divider' (solo un titolo di sezione, non cliccabile, url resta ''),
-- 'map' (mappa OpenStreetMap incorporata, usa map_lat/map_lng invece di url). Tenerle nella
-- stessa tabella (invece di tabelle separate) permette di riordinarle tutte insieme con le
-- stesse frecce sposta-su/giù già esistenti.
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
    link_type ENUM('link','divider','map') NOT NULL DEFAULT 'link',
    map_lat DECIMAL(10,7) DEFAULT NULL,
    map_lng DECIMAL(10,7) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    cover_path VARCHAR(255) DEFAULT NULL,
    accepts_reservations TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS blog_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    excerpt VARCHAR(300),
    content TEXT NOT NULL,
    cover_path VARCHAR(255) DEFAULT NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_slug (user_id, slug)
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
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_artist (user_id, spotify_artist_id),
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
    visibility ENUM('public','private') NOT NULL DEFAULT 'public',
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
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('spotify_app_token_expires', '');
