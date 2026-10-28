-- Contest come modulo di un profilo. Da eseguire UNA volta su ogni database.
-- ATTENZIONE: al primo passaggio (colonna contests.user_id assente) cancella i contest di prova
-- (con iscrizioni, voti e sponsor, per cascata). Se la colonna esiste già non tocca nessun dato.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contests' AND COLUMN_NAME = 'user_id');

SET @s := IF(@c = 0, 'DELETE FROM contests', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 0, 'ALTER TABLE contests ADD COLUMN user_id INT NOT NULL AFTER id, ADD COLUMN contact_email VARCHAR(190) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@c = 0, 'ALTER TABLE contests DROP INDEX uniq_contest_slug, ADD UNIQUE KEY uniq_contest_user_slug (user_id, slug), ADD CONSTRAINT fk_contest_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Autorizzazione per profilo: la decide l'amministratore (Area Admin -> dettaglio utente).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'contest_enabled');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN contest_enabled TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
