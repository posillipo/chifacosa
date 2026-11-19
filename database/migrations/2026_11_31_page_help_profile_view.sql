SET NAMES utf8mb4;
-- Guida della scheda profilo (raggiunta dalla ricerca profili). Si può rieseguire: non sovrascrive testi già presenti.
INSERT IGNORE INTO page_help (page_key, title, body, more, sort_order) VALUES
('dashboard_profile_view', 'Profilo', 'La scheda di un profilo trovato con la ricerca: foto, nome, descrizione e ultimi contenuti, senza uscire dalla dashboard. Da qui puoi seguirlo o smettere di seguirlo.', 'Se vi seguite a vicenda compare anche «Scrivi un messaggio». Il pulsante «Pagina pubblica» apre il sito del profilo in una nuova scheda.', 1170);
