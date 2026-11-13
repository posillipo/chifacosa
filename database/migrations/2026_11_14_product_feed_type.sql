-- Tipo di feed prodotti (google = Google Shopping/PrestaShop, woocommerce = sito WooCommerce).
-- Da eseguire UNA volta su ogni database (ripeterla non fa danni). Solo additiva: i feed esistenti restano 'google'.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_feeds' AND COLUMN_NAME = 'feed_type');
SET @s := IF(@c = 0, "ALTER TABLE product_feeds ADD COLUMN feed_type ENUM('google','woocommerce') NOT NULL DEFAULT 'google' AFTER feed_url", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
