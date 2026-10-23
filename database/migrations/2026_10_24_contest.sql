-- Modulo Contest (MyBand Festival): configurazione e candidature. Da eseguire UNA volta su ogni database.
CREATE TABLE IF NOT EXISTS contests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    regulation TEXT DEFAULT NULL,
    candidatures_open_at DATETIME DEFAULT NULL,
    candidatures_close_at DATETIME DEFAULT NULL,
    voting_start_at DATETIME DEFAULT NULL,
    voting_end_at DATETIME DEFAULT NULL,
    max_track_age_months INT NOT NULL DEFAULT 12,
    big_threshold INT NOT NULL DEFAULT 40,
    big_min_count INT NOT NULL DEFAULT 8,
    new_min_count INT NOT NULL DEFAULT 4,
    status ENUM('draft','open','voting','closed') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contest_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contest_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    band_name VARCHAR(150) NOT NULL,
    contact_email VARCHAR(190) NOT NULL,
    spotify_track_id VARCHAR(40) NOT NULL,
    track_name VARCHAR(200) NOT NULL,
    spotify_artist_id VARCHAR(40) DEFAULT NULL,
    track_url VARCHAR(300) DEFAULT NULL,
    image_url VARCHAR(500) DEFAULT NULL,
    release_date DATE DEFAULT NULL,
    artist_popularity INT DEFAULT NULL,
    artist_match TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(300) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_contest_user (contest_id, user_id),
    UNIQUE KEY uniq_contest_track (contest_id, spotify_track_id),
    KEY idx_entries_status (contest_id, status),
    FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
