-- Chi ha messo l'elemento in coda social (es. "Dashboard · Nome profilo" col tastino manuale).
-- Solo additiva e visibile solo nella dashboard. Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'created_by');
SET @s := IF(@c = 0, 'ALTER TABLE social_queue ADD COLUMN created_by VARCHAR(160) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
