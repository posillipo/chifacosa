SET NAMES utf8mb4;
-- Guide di «Profilo» (scheda) e «Messaggi» (chat): gli amministratori del sito possono scrivere anche a chi non li segue.
-- Si può rieseguire (ON DUPLICATE KEY UPDATE). Richiede la 2026_11_29.
INSERT INTO page_help (page_key, title, body, more, sort_order) VALUES
('dashboard_profile_view', 'Profilo', 'La scheda di un profilo trovato con la ricerca: foto, nome, descrizione e ultimi contenuti, senza uscire dalla dashboard. Da qui puoi seguirlo o smettere di seguirlo.', 'Se vi seguite a vicenda compare anche «Scrivi un messaggio» (agli amministratori del sito compare sempre). Il pulsante «Pagina pubblica» apre il sito del profilo in una nuova scheda.', 1170),
('dashboard_chat', 'Messaggi', 'La conversazione privata con un altro profilo. Scrivi nel campo in basso e premi Invia.', 'Il messaggio compare subito all''altro utente, che riceve un avviso se non è collegato. Si può scrivere solo se vi seguite a vicenda; gli amministratori del sito possono scrivere a chiunque, anche più messaggi di seguito.', 1110)
ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), more = VALUES(more);
