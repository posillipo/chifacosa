-- Contest fase 2: gruppi e voti. MySQL 8: le colonne si aggiungono solo se mancano.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contest_entries' AND COLUMN_NAME = 'group_key');
SET @s := IF(@c = 0, 'ALTER TABLE contest_entries ADD COLUMN group_key VARCHAR(10) DEFAULT NULL, ADD COLUMN popularity_snapshot INT DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contests' AND COLUMN_NAME = 'groups_mode');
SET @s := IF(@c = 0, 'ALTER TABLE contests ADD COLUMN groups_mode VARCHAR(10) DEFAULT NULL, ADD COLUMN groups_locked_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS contest_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contest_id INT NOT NULL,
    entry_id INT NOT NULL,
    group_key VARCHAR(10) NOT NULL,
    email_norm VARCHAR(190) NOT NULL,
    email_to VARCHAR(190) NOT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    token CHAR(64) NOT NULL,
    status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    unique_key VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    confirmed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vote_token (token),
    UNIQUE KEY uniq_vote_confirmed (unique_key),
    KEY idx_votes_entry (entry_id, status),
    KEY idx_votes_email (contest_id, email_norm, created_at),
    FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
    FOREIGN KEY (entry_id) REFERENCES contest_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB;
