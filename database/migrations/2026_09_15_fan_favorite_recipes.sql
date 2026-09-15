-- Migrazione per database già esistenti (installazioni create prima di questa modifica).
-- Chi installa da zero non ha bisogno di questo file: database/schema.sql contiene già tutto.
--
-- Aggiunge il modulo "Ricette che amo" (ricerca su Spoonacular, stesso principio di Band/Attori/
-- Film/Libri che amo).
--
-- Da eseguire una sola volta sul database di produzione, es.:
--   mysql -u <utente> -p <nome_database> < database/migrations/2026_09_15_fan_favorite_recipes.sql

CREATE TABLE IF NOT EXISTS fan_favorite_recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    spoonacular_recipe_id VARCHAR(50) NOT NULL,
    recipe_title VARCHAR(200) NOT NULL,
    recipe_image VARCHAR(500) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    image_path VARCHAR(500) DEFAULT NULL,
    image_thumb_path VARCHAR(500) DEFAULT NULL,
    show_in_feed TINYINT(1) NOT NULL DEFAULT 1,
    publish_at DATETIME DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_recipe (user_id, spoonacular_recipe_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
