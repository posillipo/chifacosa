SET NAMES utf8mb4;
-- Guida della pagina Contest per tutti gli utenti (sola lettura + propria candidatura). Si può rieseguire: non sovrascrive testi già presenti.
INSERT IGNORE INTO page_help (page_key, title, body, more, sort_order) VALUES
('dashboard_contests', 'Contest', 'I contest organizzati dall''amministrazione del sito: scegli un contest per leggere come si partecipa, le date e il regolamento, e per gestire la tua candidatura.', 'Puoi candidare un solo brano per artista, cercandolo su Spotify. La candidatura resta in attesa finché l''organizzazione non la approva. Finché le candidature sono aperte puoi correggere nome ed email di contatto oppure ritirarla; durante le votazioni una candidatura approvata non si può più ritirare.', 1180),
('dashboard_contest', 'Gestisci Contest', 'La gestione dei contest del tuo profilo: edizioni, candidature, gruppi, voti e sponsor. Visibile solo ai profili abilitati dall''amministratore.', NULL, 1190);
