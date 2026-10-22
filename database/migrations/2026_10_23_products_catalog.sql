-- Catalogo prodotti importato da un feed (Google Shopping RSS). Da eseguire UNA volta su ogni database.
CREATE TABLE IF NOT EXISTS product_feeds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    feed_url VARCHAR(500) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_synced_at DATETIME DEFAULT NULL,
    last_result VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY uniq_product_feed_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    external_ref VARCHAR(120) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    link VARCHAR(600) DEFAULT NULL,
    image_url VARCHAR(600) DEFAULT NULL,
    price_regular DECIMAL(10,2) DEFAULT NULL,
    price_sale DECIMAL(10,2) DEFAULT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    category_path VARCHAR(255) DEFAULT NULL,
    in_stock TINYINT(1) NOT NULL DEFAULT 1,
    is_hidden TINYINT(1) NOT NULL DEFAULT 0,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    last_seen_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_ref (user_id, external_ref),
    KEY idx_products_user_cat (user_id, is_hidden, category_path),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Registro delle pubblicazioni social: una riga per ogni uso di un prodotto.
CREATE TABLE IF NOT EXISTS product_publications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    post_type VARCHAR(40) NOT NULL DEFAULT 'post',
    channels VARCHAR(200) DEFAULT NULL,
    published_at DATETIME NOT NULL,
    metricool_post_id VARCHAR(80) DEFAULT NULL,
    note VARCHAR(300) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pubs_product (product_id, published_at),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
