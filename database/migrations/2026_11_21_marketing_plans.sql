-- Modulo Marketing > Pianificate: progetti con passi in sequenza (Passo 1, 2, 3... e Finale).
-- Da eseguire UNA volta su ogni database (ripeterla non fa danni: CREATE TABLE IF NOT EXISTS).
-- Solo additiva: nessun dato esistente viene toccato.
CREATE TABLE IF NOT EXISTS marketing_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('active','completed','archived') NOT NULL DEFAULT 'active',
    created_by ENUM('user','ai') NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    KEY idx_mkt_projects_user (user_id, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketing_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    position INT NOT NULL,
    is_final TINYINT(1) NOT NULL DEFAULT 0,
    title VARCHAR(150) NOT NULL,
    instructions TEXT DEFAULT NULL,
    status ENUM('todo','done') NOT NULL DEFAULT 'todo',
    result TEXT DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    completed_by ENUM('user','ai') DEFAULT NULL,
    created_by ENUM('user','ai') NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mkt_steps_project (project_id, position),
    KEY idx_mkt_steps_user (user_id),
    FOREIGN KEY (project_id) REFERENCES marketing_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
