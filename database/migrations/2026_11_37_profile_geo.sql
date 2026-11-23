-- Directory dei profili: coordinate del COMUNE (da città + provincia, mai dall'indirizzo) e indice per provincia.
-- geo_query = testo cercato l'ultima volta (serve a non ripetere la ricerca finché città/provincia non cambiano).
-- Solo additiva. Da eseguire UNA volta su ogni database (ripeterla non fa danni).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'geo_query');
SET @s := IF(@c = 0, 'ALTER TABLE profiles ADD COLUMN geo_lat DECIMAL(9,6) NULL, ADD COLUMN geo_lng DECIMAL(9,6) NULL, ADD COLUMN geo_query VARCHAR(255) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND INDEX_NAME = 'idx_profiles_provincia');
SET @s2 := IF(@i = 0, 'CREATE INDEX idx_profiles_provincia ON profiles (provincia)', 'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;
