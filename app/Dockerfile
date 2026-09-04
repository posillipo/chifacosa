FROM php:8.2-apache

# Estensioni PHP necessarie
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && a2enmod headers

# GD: usata da compressImageToJpeg() (functions.php) per ricomprimere in JPEG sotto i 250KB ogni
# foto caricata (moduli "che amo", link, blog, eventi, timeline, avatar) — libjpeg/libpng/libwebp
# servono in fase di build per abilitare i formati corrispondenti, poi si tengono solo le librerie
# runtime (togliendo i pacchetti "-dev" l'immagine finale resta più leggera).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd \
    && apt-get purge -y --auto-remove libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
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
