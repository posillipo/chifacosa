-- Migrazione per database già esistenti (installazioni create prima di questa modifica).
-- Chi installa da zero non ha bisogno di questo file: database/schema.sql contiene già tutto.
--
-- Il modulo "Ricette che amo" è passato da Spoonacular a TheMealDB (limite di 150 richieste/
-- giorno troppo basso su Spoonacular, e nessuna cache in questa prima versione — vedi commit).
-- Rinomina solo la colonna dell'id esterno: le ricette già aggiunte da un profilo restano nella
-- lista con lo stesso titolo/foto già salvati, ma l'id che portavano (di Spoonacular) non
-- corrisponde più a nulla su TheMealDB — la pagina di dettaglio di quelle ricette non riuscirà più
-- a recuperare procedimento/ingredienti aggiornati finché non vengono rimosse e ri-aggiunte
-- cercandole di nuovo (stavolta su TheMealDB).
--
-- Da eseguire una sola volta sul database di produzione, es.:
--   mysql -u <utente> -p <nome_database> < database/migrations/2026_09_16_recipes_switch_to_themealdb.sql

ALTER TABLE fan_favorite_recipes
    CHANGE COLUMN spoonacular_recipe_id themealdb_recipe_id VARCHAR(50) NOT NULL;
