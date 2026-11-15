-- Visibilità dei temi grafici per utente (gestita da Area Admin > Temi grafici).
-- Per ogni tema: "all" (tutti), "admins" (solo amministratori) o "selected" (amministratori + utenti scelti).
-- Un tema senza riga è visibile a tutti: nessun cambiamento finché l'admin non restringe un tema.
-- Da eseguire UNA volta su ogni database (ripeterla non fa danni). Solo additiva.
CREATE TABLE IF NOT EXISTS theme_visibility (
    theme_key VARCHAR(40) NOT NULL PRIMARY KEY,
    mode ENUM('all','admins','selected') NOT NULL DEFAULT 'all',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS theme_visibility_users (
    theme_key VARCHAR(40) NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (theme_key, user_id),
    KEY idx_theme_vis_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
