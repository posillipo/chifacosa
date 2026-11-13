-- Posizione geografica degli eventi (per la mappa nella pagina pubblica Eventi, tema AdminLTE).
-- lat/lng = coordinate calcolate dall'indirizzo (locale + città + provincia); geo_query = indirizzo usato,
-- per ricalcolare solo quando cambia. Da eseguire UNA volta su ogni database (ripeterla non fa danni).
-- Solo additiva: gli eventi esistenti restano invariati (senza posizione finché non viene calcolata).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'lat');
SET @s := IF(@c = 0, 'ALTER TABLE events ADD COLUMN lat DECIMAL(9,6) DEFAULT NULL, ADD COLUMN lng DECIMAL(9,6) DEFAULT NULL, ADD COLUMN geo_query VARCHAR(400) DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
