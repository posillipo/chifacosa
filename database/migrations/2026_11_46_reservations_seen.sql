SET NAMES utf8mb4;
-- Prenotazioni «nuove»: seen_at = quando il gestore le ha viste nella pagina Prenotazioni (NULL = mai viste).
-- Da eseguire UNA volta (una seconda esecuzione dà «Duplicate column» ed è innocua). Le prenotazioni già presenti
-- vengono segnate come viste, così il contatore parte da zero.
ALTER TABLE table_reservations ADD COLUMN seen_at DATETIME NULL DEFAULT NULL AFTER marketing_opt_in;
UPDATE table_reservations SET seen_at = created_at WHERE seen_at IS NULL;
