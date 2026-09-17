CREATE TABLE IF NOT EXISTS dashboard_tab_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tab_key VARCHAR(30) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_user_tab (user_id, tab_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
