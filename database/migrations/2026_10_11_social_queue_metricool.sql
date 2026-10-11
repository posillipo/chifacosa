-- Metricool auto-publish integration: queue table + profile columns

ALTER TABLE profiles
    ADD COLUMN IF NOT EXISTS metricool_blog_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS metricool_networks VARCHAR(300) DEFAULT NULL COMMENT 'JSON array: ["instagram","facebook","tiktok",...]',
    ADD COLUMN IF NOT EXISTS metricool_auto_social TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS social_queue (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    content_type ENUM('timeline','blog') NOT NULL,
    content_id  INT NOT NULL,
    title       VARCHAR(255) DEFAULT NULL,
    body        TEXT DEFAULT NULL,
    image_url   VARCHAR(500) DEFAULT NULL,
    public_url  VARCHAR(500) NOT NULL,
    status      ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    error_msg   TEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    INDEX idx_status_created (status, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO site_settings (setting_key, setting_value)
VALUES ('social_queue_secret', '8db363a26d27a0edaa98c159192bbe1520b5a28d56b312ed');
