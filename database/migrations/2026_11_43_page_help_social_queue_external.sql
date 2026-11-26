SET NAMES utf8mb4;
-- Guida di «Coda Social»: aggiunge il pulsante «Apri sistema Esterno». Si può rieseguire (ON DUPLICATE KEY UPDATE). Richiede la 2026_11_30.
INSERT INTO page_help (page_key, title, body, more, sort_order) VALUES
('dashboard_social_queue', 'Coda Social', 'L''elenco dei contenuti in coda per la pubblicazione sui social: vedi cosa è in attesa, in elaborazione, programmato, pubblicato o in errore.', 'Con **Filtri** scegli stato e tipo. Un clic sulle immagini o sui video mostra l''anteprima di ciò che verrà pubblicato. Da qui puoi sospendere, riprendere o rimuovere un elemento. Sugli elementi **Programmati** il pulsante **Apri sistema Esterno** apre in una nuova scheda il post nel calendario di Metricool: è colorato solo se l''elemento ha un collegamento valido (campo `scheduler_ref` con un indirizzo http/https), altrimenti è grigio e non cliccabile.', 1150)
ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), more = VALUES(more);
