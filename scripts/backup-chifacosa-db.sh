#!/bin/bash
# Backup giornaliero dei database di CHI FA COSA (chifacosa, cfc_new, lacaraffa — aggiungi altri
# nomi qui sotto quando nascono nuovi siti), fatto con mysqldump dentro al container myband_db,
# compresso e salvato sull'HD montato sul server. Pensato per girare da crontab sull'HOST (non
# dentro un container): usa "docker exec" per raggiungere myband_db, quindi serve Docker
# raggiungibile dall'utente/cron che lo esegue.
set -euo pipefail

# ==== Configurazione — adatta questi valori al tuo server ====
BACKUP_DIR="/mnt/backup-hd/chifacosa-db"        # cartella sull'HD montato dove salvare i dump
DB_CONTAINER="myband_db"                        # nome del container MySQL condiviso
DB_ROOT_PASSWORD="cambiami_root_123"            # password root del DB (vedi ADMIN_SETUP.md)
DATABASES=("chifacosa" "cfc_new" "lacaraffa")   # aggiungi qui il nome di ogni nuovo sito
RETENTION_DAYS=30                               # dump più vecchi di così vengono cancellati
# ================================================================

TIMESTAMP="$(date +%F_%H%M)"
mkdir -p "$BACKUP_DIR"

FAILED=0
for DB in "${DATABASES[@]}"; do
    OUT="$BACKUP_DIR/${DB}_${TIMESTAMP}.sql.gz"
    echo "[$(date '+%F %T')] Backup di ${DB} -> ${OUT}"
    if docker exec "$DB_CONTAINER" mysqldump -u root -p"$DB_ROOT_PASSWORD" \
        --single-transaction --quick --routines --triggers "$DB" | gzip > "$OUT"; then
        echo "[$(date '+%F %T')] OK: ${DB} ($(du -h "$OUT" | cut -f1))"
    else
        echo "[$(date '+%F %T')] ERRORE nel backup di ${DB}" >&2
        rm -f "$OUT"
        FAILED=1
    fi
done

echo "[$(date '+%F %T')] Pulizia dump più vecchi di ${RETENTION_DAYS} giorni..."
find "$BACKUP_DIR" -name "*.sql.gz" -mtime "+${RETENTION_DAYS}" -print -delete

if [ "$FAILED" -eq 1 ]; then
    echo "[$(date '+%F %T')] Backup completato CON ERRORI (vedi sopra)." >&2
    exit 1
fi
echo "[$(date '+%F %T')] Backup completato con successo."
