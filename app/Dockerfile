FROM php:8.2-apache

# Estensioni PHP necessarie
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && a2enmod headers

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
