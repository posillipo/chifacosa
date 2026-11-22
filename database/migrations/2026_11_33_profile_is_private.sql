-- Profilo privato: nascosto da ricerche, liste e sitemap; la pagina pubblica si apre solo al proprietario,
-- a chi lo gestisce, agli admin e ai follower collegati. I server MCP e le API non sono toccati.
-- Solo additiva (tutti i profili esistenti restano pubblici). Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'is_private');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
