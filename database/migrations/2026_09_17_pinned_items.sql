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
