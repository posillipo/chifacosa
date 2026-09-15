-- Migrazione per database già esistenti (installazioni create prima di questa modifica).
-- Chi installa da zero non ha bisogno di questo file: database/schema.sql contiene già tutto.
--
-- Torna indietro dal breve passaggio a TheMealDB (catalogo troppo piccolo, poche centinaia di
-- ricette) e riporta il modulo "Ricette che amo" su Spoonacular — stavolta con i dettagli messi
-- in cache nel database invece di richiamare l'API a ogni visita della pagina pubblica, per non
-- esaurire il limite di 150 richieste/giorno del piano gratuito.
--
-- Le ricette aggiunte durante la parentesi TheMealDB restano nella lista con lo stesso titolo/
-- foto già salvati, ma l'id che portavano non corrisponde a nulla su Spoonacular — la pagina di
-- dettaglio di quelle ricette non riuscirà più a recuperare tempo di preparazione/ingredienti
-- finché non vengono rimosse e ri-aggiunte cercandole di nuovo (stavolta su Spoonacular).
--
-- Da eseguire una sola volta sul database di produzione, es.:
--   mysql -u <utente> -p <nome_database> < database/migrations/2026_09_16_recipes_back_to_spoonacular_cached.sql

ALTER TABLE fan_favorite_recipes
    CHANGE COLUMN themealdb_recipe_id spoonacular_recipe_id VARCHAR(50) NOT NULL,
    ADD COLUMN cached_details TEXT DEFAULT NULL AFTER image_thumb_path;
