SET NAMES utf8mb4;
-- Aggiorna la guida della pagina Profilo con l'opzione "Profilo privato". Si può rieseguire.
INSERT INTO page_help (page_key, title, body, more, sort_order) VALUES
('dashboard_profile', 'Profilo', 'Qui modifichi come ti presenti: nome, descrizione, foto profilo, colore e informazioni di contatto. Sono i dati che vedono i visitatori della tua pagina pubblica.', 'Il colore scelto qui resta il tuo accento personale anche cambiando tema grafico. Con «Profilo privato» il profilo sparisce da ricerche, liste e motori di ricerca e la pagina pubblica si apre solo a te, a chi lo gestisce e ai tuoi follower collegati; le tue automazioni e i server MCP continuano a funzionare. In fondo alla pagina trovi l''eliminazione dell''account.', 1020)
ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), more = VALUES(more);
