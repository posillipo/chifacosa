-- Firma dell'autore sugli articoli del blog (chi li ha creati: persona in dashboard, Telegram, IA via API/MCP).
-- Solo additiva e visibile solo nella dashboard: gli articoli esistenti restano senza firma (NULL).
-- Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blog_posts' AND COLUMN_NAME = 'created_by');
SET @s := IF(@c = 0, 'ALTER TABLE blog_posts ADD COLUMN created_by VARCHAR(160) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
