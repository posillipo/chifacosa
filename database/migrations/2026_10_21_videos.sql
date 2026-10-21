-- Modulo Video (video caricati) + rinomina della vecchia sezione "Video" (YouTube) in "YouTube".
-- Da eseguire UNA volta su ogni database (chifacosa, cfc_new, lacaraffa, myband), prima o subito
-- dopo il deploy del codice nuovo.

-- 1. Tabella dei video caricati
CREATE TABLE IF NOT EXISTS videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    video_path VARCHAR(255) NOT NULL,
    thumb_path VARCHAR(255) DEFAULT NULL,
    mime_type VARCHAR(40) NOT NULL DEFAULT 'video/mp4',
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds INT DEFAULT NULL,
    width INT DEFAULT NULL,
    height INT DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_videos_user_sort (user_id, sort_order),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Indirizzo del file video nella coda social (per la pubblicazione su Metricool)
ALTER TABLE social_queue ADD COLUMN IF NOT EXISTS media_url VARCHAR(500) DEFAULT NULL;

-- 3a. Se il codice nuovo è già andato online prima della migrazione, per alcuni profili può essere
--     già comparsa una voce "YouTube" con le impostazioni di default: si elimina, così la vecchia
--     voce (con le scelte del profilo: nascosta, ordine) prende il suo posto senza conflitti.
DELETE y FROM profile_navigation_menu y
  JOIN profile_navigation_menu v ON v.user_id = y.user_id AND v.name = 'Video' AND v.icon = 'fa-brands fa-youtube'
 WHERE y.name = 'YouTube';

-- 3b. La vecchia voce "Video" del menu (canale YouTube) diventa "YouTube". Si riconosce dall'icona,
--    così non tocca la voce "Video" nuova se la migrazione viene rilanciata per errore.
UPDATE profile_navigation_menu
   SET name = 'YouTube', url = CONCAT(LEFT(url, CHAR_LENGTH(url) - 5), 'youtube')
 WHERE name = 'Video' AND icon = 'fa-brands fa-youtube' AND url LIKE '%/video';

-- 4. Eventuale disattivazione della vecchia sezione "Video" a livello di installazione.
UPDATE site_settings
   SET setting_value = REPLACE(setting_value, '"video"', '"youtube"')
 WHERE setting_key = 'disabled_nav_keys';
