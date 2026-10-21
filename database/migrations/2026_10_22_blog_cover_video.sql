-- Articoli del blog con video di copertina (in alternativa all'immagine).
-- Da eseguire UNA volta su ogni database. MySQL 8: niente "ADD COLUMN IF NOT EXISTS", si controlla
-- information_schema.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts' AND COLUMN_NAME = 'cover_video_path');
SET @s := IF(@c = 0, 'ALTER TABLE blog_posts ADD COLUMN cover_video_path VARCHAR(255) DEFAULT NULL AFTER cover_path', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
