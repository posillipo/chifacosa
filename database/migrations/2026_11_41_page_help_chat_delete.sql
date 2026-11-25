SET NAMES utf8mb4;
-- Guida di «Messaggi» (chat): aggiunge «Cancella conversazione». Si può rieseguire (ON DUPLICATE KEY UPDATE). Richiede la 2026_11_29.
INSERT INTO page_help (page_key, title, body, more, sort_order) VALUES
('dashboard_chat', 'Messaggi', 'La conversazione privata con un altro profilo. Scrivi nel campo in basso e premi Invia.', 'Il messaggio compare subito all''altro utente, che riceve un avviso se non è collegato. Si può scrivere solo se vi seguite a vicenda; gli amministratori del sito possono scrivere a chiunque, anche più messaggi di seguito. «Cancella conversazione» (in alto) la toglie solo a te: l''altra persona continua a vederla, e se ti scrive di nuovo la conversazione riappare con i soli messaggi nuovi.', 1110)
ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), more = VALUES(more);
