-- Risposte alle richieste di contatto, scritte e memorizzate dalla dashboard (pagina Contatti).
-- Solo additiva. Da eseguire UNA volta su ogni database (ripeterla non fa danni).
CREATE TABLE IF NOT EXISTS contact_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contact_request_id INT NOT NULL,
    user_id INT NOT NULL,
    body TEXT NOT NULL,
    sent_ok TINYINT(1) NOT NULL DEFAULT 0,
    sent_at DATETIME DEFAULT NULL,
    created_by VARCHAR(160) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reply_request (contact_request_id),
    FOREIGN KEY (contact_request_id) REFERENCES contact_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
