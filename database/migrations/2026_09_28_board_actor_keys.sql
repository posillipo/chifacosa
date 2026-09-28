CREATE TABLE IF NOT EXISTS board_actor_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    actor VARCHAR(30) NOT NULL,
    secret_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_board_actor (user_id, actor)
) ENGINE=InnoDB;
