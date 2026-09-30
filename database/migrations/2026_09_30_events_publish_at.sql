ALTER TABLE events ADD COLUMN publish_at DATETIME DEFAULT NULL AFTER accepts_reservations;
