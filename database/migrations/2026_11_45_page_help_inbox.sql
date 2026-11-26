SET NAMES utf8mb4;
-- Guida della nuova pagina «Messaggistica» (dashboard_inbox.php). Si può rieseguire (ON DUPLICATE KEY UPDATE). Richiede la 2026_11_29.
INSERT INTO page_help (page_key, title, body, more, sort_order) VALUES
('dashboard_inbox', 'Messaggistica', 'Il riepilogo di tutto ciò che c''è da leggere: messaggi, contatti dal sito, richieste di servizio e prenotazioni.', 'Ogni riquadro mostra quante novità ci sono e porta alla sua sezione. L''icona dei messaggi nella barra in alto si colora e mostra il totale quando c''è qualcosa da leggere; un clic ti porta qui. Richieste di servizio e Prenotazioni compaiono solo se quelle sezioni sono attive per il tuo profilo.', 1105)
ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), more = VALUES(more);
