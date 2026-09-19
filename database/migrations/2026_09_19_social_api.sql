ALTER TABLE timeline_posts ADD COLUMN title VARCHAR(100) DEFAULT NULL AFTER user_id;
ALTER TABLE timeline_posts ADD COLUMN hashtags VARCHAR(300) DEFAULT NULL AFTER image_thumb_path;
ALTER TABLE timeline_posts ADD COLUMN call_to_action VARCHAR(200) DEFAULT NULL AFTER hashtags;
ALTER TABLE timeline_posts ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'dashboard' AFTER call_to_action;

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    token_prefix VARCHAR(40) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at DATETIME DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_request_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_token_id INT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    method VARCHAR(10) NOT NULL,
    endpoint VARCHAR(200) NOT NULL,
    status_code SMALLINT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL,
    INDEX idx_token_time (api_token_id, created_at)
) ENGINE=InnoDB;
