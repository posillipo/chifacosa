-- Messaggi diretti: «Cancella conversazione» solo per sé. Due segni per messaggio: cancellato da chi l'ha scritto /
-- da chi l'ha ricevuto; l'altra persona continua a vedere la conversazione.
-- Solo additiva. Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'direct_messages' AND COLUMN_NAME = 'deleted_by_recipient');
SET @s := IF(@c = 0, 'ALTER TABLE direct_messages ADD COLUMN deleted_by_sender TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN deleted_by_recipient TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
