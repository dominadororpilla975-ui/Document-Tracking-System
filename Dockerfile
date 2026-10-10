FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libzip-dev mariadb-client \
    && docker-php-ext-install -j"$(nproc)" curl mbstring mysqli pdo_mysql zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/

RUN printf '%s\n' \
        'upload_max_filesize=25M' \
        'post_max_size=512M' \
        'max_file_uploads=20' \
        'max_input_time=300' \
        > /usr/local/etc/php/conf.d/railway.ini \
    && rm -rf /var/www/html/uploads /var/www/html/backups /var/www/html/image/uploads \
    && mkdir -p /data/uploads /data/backups /data/image-uploads /var/www/html/image \
    && ln -s /data/uploads /var/www/html/uploads \
    && ln -s /data/backups /var/www/html/backups \
    && ln -s /data/image-uploads /var/www/html/image/uploads \
    && printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

COPY docker/railway-entrypoint.sh /usr/local/bin/railway-entrypoint
RUN chmod +x /usr/local/bin/railway-entrypoint

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/railway-entrypoint"]
CMD ["apache2-foreground"]
