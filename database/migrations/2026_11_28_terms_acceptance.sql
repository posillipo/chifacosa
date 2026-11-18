-- Accettazione delle Condizioni d'uso: quando e quale versione del testo ha accettato l'utente.
-- Solo additiva. Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'terms_accepted_at');
SET @s := IF(@c = 0, 'ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME DEFAULT NULL, ADD COLUMN terms_version VARCHAR(20) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
