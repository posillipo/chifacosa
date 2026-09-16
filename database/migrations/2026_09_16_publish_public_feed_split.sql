-- Separa la visibilità pubblica di un contenuto ("Pubblico"/"Solo io") dalla sua comparsa nel
-- Feed aggregato: prima un solo flag (show_in_feed) controllava entrambe le cose insieme. Ora
-- show_in_feed diventa is_public (stesso significato di sempre: visibile sulla sua pagina/elenco,
-- soggetto alla programmazione), e una nuova colonna in_feed (default 1, per non cambiare il
-- comportamento di quanto già pubblicato) decide se compare *anche* nel flusso aggiornamenti.
--
-- fan_favorite_playlists e fan_favorite_albums esistono già in produzione (il modulo è in uso)
-- pur non essendo mai finite in schema.sql: vanno quindi alterate come le altre, non ricreate.

ALTER TABLE fan_favorite_bands     CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_actors    CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_movies    CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_books     CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_trips     CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_playlists CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_albums    CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_recipes   CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_teams     CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_players   CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE fan_favorite_matches   CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE favorite_tracks        CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE photo_albums           CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;
ALTER TABLE services               CHANGE COLUMN show_in_feed is_public TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER is_public;

ALTER TABLE timeline_posts ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 AFTER visibility;
