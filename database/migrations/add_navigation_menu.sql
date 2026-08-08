-- Tabella per il menu di navigazione personalizzato per ogni utente
CREATE TABLE IF NOT EXISTS navigation_menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    url VARCHAR(255) NOT NULL,
    icon VARCHAR(50) NULL,
    is_active BOOLEAN DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_sort_order (user_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserisci menu di default per utente ID 1
INSERT IGNORE INTO navigation_menu_items (user_id, label, url, icon, is_active, sort_order) VALUES
(1, 'Home', '/home', 'fas fa-home', 1, 1),
(1, 'Timeline', '/home/timeline', 'fas fa-stream', 1, 2),
(1, 'Blog', '/home/blog', 'fas fa-newspaper', 1, 3),
(1, 'Menù', '/home/menu', 'fas fa-utensils', 1, 4),
(1, 'Eventi', '/home/eventi', 'fas fa-calendar', 1, 5),
(1, 'Contatti', '/home/contatti', 'fas fa-envelope', 1, 6);
