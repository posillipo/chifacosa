-- Contest fase 3: sponsor. MySQL 8: la colonna si aggiunge solo se manca.
CREATE TABLE IF NOT EXISTS contest_sponsors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contest_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    website_url VARCHAR(300) DEFAULT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    access_token CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_sponsor_token (access_token),
    KEY idx_sponsors_contest (contest_id),
    FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contest_entries' AND COLUMN_NAME = 'sponsor_id');
SET @s := IF(@c = 0, 'ALTER TABLE contest_entries ADD COLUMN sponsor_id INT DEFAULT NULL, ADD KEY idx_entries_sponsor (sponsor_id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
