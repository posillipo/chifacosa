# Backup giornaliero dei database

Script: `scripts/backup-chifacosa-db.sh`. Fa un dump di ogni database (`chifacosa`, `cfc_new`,
`lacaraffa` — modifica l'elenco `DATABASES` in cima allo script quando nasce un nuovo sito),
lo comprime con gzip e lo salva sull'HD montato sul server. Cancella da solo i dump più vecchi
di `RETENTION_DAYS` (30 di default) per non riempire il disco all'infinito.

Va eseguito sull'**host** (non dentro un container): usa `docker exec` per raggiungere il
database dentro `myband_db`, quindi serve un utente con accesso a Docker.

## Installazione (una tantum)

1. Copia lo script sul server, es. in `/root/scripts/backup-chifacosa-db.sh` (o clona/aggiorna
   il repository e usa il percorso dentro al checkout).
2. Apri lo script e verifica/adatta in cima:
   - `BACKUP_DIR` — il percorso reale dove è montato l'HD di backup sul server (es.
     `/mnt/backup-hd/chifacosa-db`). Se la cartella non esiste ancora lo script la crea da solo,
     ma il **punto di mount** dell'HD deve già esserci.
   - `DB_ROOT_PASSWORD` — se hai cambiato la password root del DB rispetto a quella di default,
     aggiornala qui (vedi `ADMIN_SETUP.md`).
   - `DATABASES` — elenco dei database da salvare.
3. Rendilo eseguibile ed eseguibile solo dal proprietario, dato che contiene una password in
   chiaro:
   ```bash
   chmod 700 scripts/backup-chifacosa-db.sh
   ```
4. Provalo a mano prima di automatizzarlo:
   ```bash
   ./scripts/backup-chifacosa-db.sh
   ```
   Deve creare in `BACKUP_DIR` un file `<nomedb>_<data_ora>.sql.gz` per ciascun database, senza
   errori in output.

## Automazione (crontab)

```bash
crontab -e
```
Aggiungi (backup ogni notte alle 3:00, log in un file separato):
```
0 3 * * * /root/scripts/backup-chifacosa-db.sh >> /var/log/chifacosa-backup.log 2>&1
```

## Ripristinare un backup

```bash
gunzip -c /mnt/backup-hd/chifacosa-db/chifacosa_2026-09-18_0300.sql.gz \
  | docker exec -i myband_db mysql -u root -p'PASSWORD_ROOT' chifacosa
```
Sovrascrive il database indicato con lo stato del dump: usalo solo quando è davvero quello che
vuoi (es. dopo aver verificato di avere il file giusto), non ha una conferma integrata.

## Verifica che i backup arrivino davvero

```bash
ls -lht /mnt/backup-hd/chifacosa-db/ | head -10
tail -30 /var/log/chifacosa-backup.log
```
Se l'ultimo dump di un database è più vecchio di un giorno, qualcosa nel cron non sta girando —
controlla il log per l'errore.
