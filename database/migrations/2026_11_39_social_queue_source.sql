-- Coda social: origine dei file usati (sorgente). Valori: site (file sul nostro dominio), external (altrove), mixed,
-- oppure un'etichetta breve data da chi accoda (es. claude, canva, catalogo). Si compila da sola se non indicata.
-- Solo additiva. Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'source');
SET @s := IF(@c = 0, 'ALTER TABLE social_queue ADD COLUMN source VARCHAR(60) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
