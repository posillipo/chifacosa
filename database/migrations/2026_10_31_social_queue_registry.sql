-- social_queue come REGISTRO delle pubblicazioni social (oltre che coda di lavoro).
-- Da eseguire UNA volta su ogni database (ripeterla non fa danni: ogni passo controlla prima).
-- SOLO modifiche additive: nessun dato esistente viene cancellato o cambiato.
--
-- Riuso delle colonne già presenti (nessun duplicato):
--   content_type / content_id -> "source_type" / "source_id" (l'elemento sorgente)
--   media_url                 -> "video_url"
--   scheduled_for             -> "publication_date"
--   status                    -> "outcome" (qui si aggiungono scheduled e skipped)
--
-- Nuove colonne: scope, format, image_story_url, channels, scheduler_ref + vincolo di unicità.

-- scope
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'scope');
SET @s := IF(@c = 0, "ALTER TABLE social_queue ADD COLUMN scope ENUM('announcement','reminder','result','new','promo','release','reprise') DEFAULT NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- format
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'format');
SET @s := IF(@c = 0, "ALTER TABLE social_queue ADD COLUMN `format` ENUM('feed','story','reel') DEFAULT NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- image_story_url
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'image_story_url');
SET @s := IF(@c = 0, 'ALTER TABLE social_queue ADD COLUMN image_story_url VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- channels (array JSON, es. ["FB","IG","Threads"])
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'channels');
SET @s := IF(@c = 0, 'ALTER TABLE social_queue ADD COLUMN channels JSON DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- scheduler_ref (riferimento esterno opaco, es. id del post su Metricool)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'scheduler_ref');
SET @s := IF(@c = 0, 'ALTER TABLE social_queue ADD COLUMN scheduler_ref VARCHAR(120) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- status: aggiunge 'scheduled' e 'skipped' ai valori GIÀ presenti, leggendo l'elenco reale dal
-- database (in produzione la colonna ha valori che le vecchie migrazioni non descrivono, es.
-- 'suspended'): niente valore viene perso. Si ferma da sola se la colonna non è un ENUM.
SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'status');
SET @s := IF(@t LIKE 'enum(%' AND @t NOT LIKE "%'scheduled'%",
             CONCAT('ALTER TABLE social_queue MODIFY COLUMN status ', LEFT(@t, CHAR_LENGTH(@t) - 1), ",'scheduled') NOT NULL DEFAULT 'pending'"),
             'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'status');
SET @s := IF(@t LIKE 'enum(%' AND @t NOT LIKE "%'skipped'%",
             CONCAT('ALTER TABLE social_queue MODIFY COLUMN status ', LEFT(@t, CHAR_LENGTH(@t) - 1), ",'skipped') NOT NULL DEFAULT 'pending'"),
             'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Unicità: profilo + tipo sorgente + id sorgente + scope identificano ogni uscita distinta dello
-- stesso elemento. Le righe vecchie hanno scope NULL e MySQL considera diversi i NULL: restano
-- tutte valide, nessun conflitto con i dati esistenti.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND INDEX_NAME = 'uniq_sq_registry');
SET @s := IF(@c = 0, 'ALTER TABLE social_queue ADD UNIQUE KEY uniq_sq_registry (user_id, content_type, content_id, scope)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Verifica finale (stampa lo schema risultante)
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' ORDER BY ORDINAL_POSITION;
