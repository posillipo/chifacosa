-- Migrazione per database già esistenti (installazioni create prima di questa modifica).
-- Chi installa da zero non ha bisogno di questo file: database/schema.sql contiene già tutto.
--
-- Aggiunge i moduli "Calciatori che amo" e "Partite che amo" (entrambi su TheSportsDB, stessa
-- chiave API già usata da "Squadre che amo" — nessuna nuova chiave da configurare).
--
-- Da eseguire una sola volta sul database di produzione, es.:
--   mysql -u <utente> -p <nome_database> < database/migrations/2026_09_16_fan_favorite_players_matches.sql

CREATE TABLE IF NOT EXISTS fan_favorite_players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    thesportsdb_player_id VARCHAR(50) NOT NULL,
    player_name VARCHAR(200) NOT NULL,
    player_photo VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    show_in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_player (user_id, thesportsdb_player_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fan_favorite_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    thesportsdb_event_id VARCHAR(50) NOT NULL,
    match_title VARCHAR(200) NOT NULL,
    match_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    show_in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_match (user_id, thesportsdb_event_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
