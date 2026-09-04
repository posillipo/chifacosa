FROM php:8.2-apache

# Estensioni PHP necessarie
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && a2enmod headers

# GD: usata da compressImageToJpeg() (functions.php) per ricomprimere in JPEG sotto i 250KB ogni
# foto caricata (moduli "che amo", link, blog, eventi, timeline, avatar) — libjpeg/libpng/libwebp
# servono sia in fase di build (per compilare l'estensione) sia a runtime (l'estensione compilata
# ci si collega dinamicamente ogni volta che PHP parte): niente "apt-get purge" dei pacchetti dopo
# la build, altrimenti l'estensione risulta installata ma non caricabile ("cannot open shared
# object file libpng16.so.16" — errore reale riscontrato in produzione con la prima versione di
# questo Dockerfile). La manciata di MB in più rispetto a poterle rimuovere è un compromesso
# accettabile per l'affidabilità.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd \
    && rm -rf /var/lib/apt/lists/*

# Copia applicazione
COPY public/ /var/www/html/
COPY src/ /var/www/src/
COPY import/ /var/www/import/

# Entrypoint
COPY docker-entrypoint-wrapper.sh /usr/local/bin/docker-entrypoint-wrapper.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-wrapper.sh
ENTRYPOINT ["docker-entrypoint-wrapper.sh"]
CMD ["apache2-foreground"]

# Permessi
RUN mkdir -p /var/www/html/uploads/audio /var/www/html/uploads/images \
    && chown -R www-data:www-data /var/www/html

RUN { \
      echo 'upload_max_filesize = 20M'; \
      echo 'post_max_size = 25M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

RUN { \
      echo 'display_errors = Off'; \
      echo 'display_startup_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'error_reporting = E_ALL'; \
    } > /usr/local/etc/php/conf.d/error-handling.ini

RUN { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '    Require all granted'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/chifacosa.conf \
    && a2enconf chifacosa

WORKDIR /var/www/html
