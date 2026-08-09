#!/bin/bash
set -e

# Assicura che le cartelle di upload esistano e siano scrivibili da Apache (www-data)
# ad ogni avvio del container, indipendentemente da come il bind mount è stato inizializzato
# sul server host. Risolve alla radice l'errore "move_uploaded_file(): Unable to move".
mkdir -p /var/www/html/uploads/images /var/www/html/uploads/audio
chown -R www-data:www-data /var/www/html/uploads
chmod -R 775 /var/www/html/uploads

# Stesso discorso per la cartella dove install.php scrive le credenziali del database quando
# configurate dal browser (vedi app/src/db.php) — un volume Docker creato da zero parte di
# proprietà di root, e Apache/PHP girano come www-data.
mkdir -p /var/www/config
chown -R www-data:www-data /var/www/config
chmod -R 775 /var/www/config

exec docker-php-entrypoint "$@"
