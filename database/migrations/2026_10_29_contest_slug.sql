-- Contest: slug (indirizzo /contest/<slug>) e logo. MySQL 8: colonne e indice solo se mancano.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contests' AND COLUMN_NAME = 'slug');
SET @s := IF(@c = 0, 'ALTER TABLE contests ADD COLUMN slug VARCHAR(80) DEFAULT NULL, ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Per i contest già creati: slug provvisorio "contest-<id>" (modificabile dalla dashboard).
UPDATE contests SET slug = CONCAT('contest-', id) WHERE slug IS NULL OR slug = '';

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contests' AND INDEX_NAME = 'uniq_contest_slug');
SET @s := IF(@i = 0, 'ALTER TABLE contests ADD UNIQUE KEY uniq_contest_slug (slug)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
