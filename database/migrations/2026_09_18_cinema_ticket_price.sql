ALTER TABLE profiles ADD COLUMN cinema_ticket_price DECIMAL(6,2) DEFAULT NULL AFTER cinema_films_synced_at;
