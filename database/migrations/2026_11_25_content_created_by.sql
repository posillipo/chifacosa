-- Firma dell'autore anche su Timeline, album, eventi, servizi e video (chi li ha creati: persona, Telegram, IA via API/MCP).
-- Solo additiva e visibile solo nella dashboard: i contenuti esistenti restano senza firma (NULL).
-- Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timeline_posts' AND COLUMN_NAME = 'created_by');
SET @s := IF(@c = 0, 'ALTER TABLE timeline_posts ADD COLUMN created_by VARCHAR(160) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'photo_albums' AND COLUMN_NAME = 'created_by');
SET @s := IF(@c = 0, 'ALTER TABLE photo_albums ADD COLUMN created_by VARCHAR(160) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'created_by');
SET @s := IF(@c = 0, 'ALTER TABLE events ADD COLUMN created_by VARCHAR(160) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'created_by');
SET @s := IF(@c = 0, 'ALTER TABLE services ADD COLUMN created_by VARCHAR(160) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'videos' AND COLUMN_NAME = 'created_by');
SET @s := IF(@c = 0, 'ALTER TABLE videos ADD COLUMN created_by VARCHAR(160) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
