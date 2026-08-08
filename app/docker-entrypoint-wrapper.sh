#!/bin/bash
set -e

# Assicura che le cartelle di upload esistano e siano scrivibili da Apache (www-data)
# ad ogni avvio del container, indipendentemente da come il bind mount è stato inizializzato
# sul server host. Risolve alla radice l'errore "move_uploaded_file(): Unable to move".
mkdir -p /var/www/html/uploads/images /var/www/html/uploads/audio
chown -R www-data:www-data /var/www/html/uploads
chmod -R 775 /var/www/html/uploads

exec docker-php-entrypoint "$@"
