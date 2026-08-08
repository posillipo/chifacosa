-- Tabella per gestire la visibilità delle voci di menu per ogni profilo
CREATE TABLE IF NOT EXISTS profile_navigation_menu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    icon VARCHAR(50) NULL,
    url VARCHAR(100) NOT NULL,
    is_visible BOOLEAN DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserisci voci di default per ogni nuovo utente (user_id = 1)
INSERT IGNORE INTO profile_navigation_menu (user_id, name, icon, url, is_visible, sort_order) VALUES
(1, 'Home', 'fas fa-home', '/home', 1, 1),
(1, 'Timeline', 'fas fa-stream', '/home/timeline', 1, 2),
(1, 'Blog', 'fas fa-newspaper', '/home/blog', 1, 3),
(1, 'Brani', 'fas fa-music', '/home/brani', 1, 4),
(1, 'Menù', 'fas fa-utensils', '/home/menu', 1, 5),
(1, 'Eventi', 'fas fa-calendar', '/home/eventi', 1, 6),
(1, 'Contatti', 'fas fa-envelope', '/home/contatti', 1, 7);
