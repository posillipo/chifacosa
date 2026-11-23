-- Coda social: elenco di fino a 10 immagini per uscita (carosello/galleria), come JSON con gli URL in ordine;
-- la prima resta anche in image_url. Vuoto = una sola immagine (image_url). Per i post Timeline l'elenco
-- si legge dal post al momento della consegna, quindi la colonna serve solo per elenchi passati a mano.
-- Solo additiva. Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_queue' AND COLUMN_NAME = 'image_urls');
SET @s := IF(@c = 0, 'ALTER TABLE social_queue ADD COLUMN image_urls TEXT NULL AFTER image_url', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
