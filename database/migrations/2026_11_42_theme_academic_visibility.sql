-- Tema «Academic»: in prova, visibile solo agli amministratori. Per aprirlo a tutti: Area Admin → Temi grafici
-- (oppure cancellare la riga). INSERT IGNORE: rieseguire non rimette la restrizione se l'hai già cambiata.
-- Richiede la 2026_11_23 (tabella theme_visibility).
INSERT IGNORE INTO theme_visibility (theme_key, mode) VALUES ('academic', 'admins');
