-- Migrazione per database già esistenti (installazioni create prima di questa modifica).
-- Chi installa da zero non ha bisogno di questo file: database/schema.sql contiene già tutto.
--
-- Aggiunge: programmazione degli articoli del blog (riusa published_at, impostabile nel futuro
-- dalla dashboard), collegamento opzionale a un album fotografico, tag liberi, e le categorie
-- del blog (elenco gestito + assegnazione multipla per articolo).
--
-- Da eseguire una sola volta sul database di produzione, es.:
--   mysql -u <utente> -p <nome_database> < database/migrations/2026_09_15_blog_scheduling_album_categories.sql

ALTER TABLE blog_posts
    ADD COLUMN album_id INT DEFAULT NULL AFTER cover_path,
    ADD COLUMN tags VARCHAR(300) DEFAULT NULL AFTER album_id;

ALTER TABLE blog_posts
    ADD CONSTRAINT fk_blog_posts_album FOREIGN KEY (album_id) REFERENCES photo_albums(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS blog_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_slug (user_id, slug),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blog_post_categories (
    post_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (post_id, category_id),
    FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;
