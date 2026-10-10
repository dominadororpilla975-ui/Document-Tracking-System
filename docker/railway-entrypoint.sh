#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
    ''|*[!0-9]*)
        echo "PORT must be a valid TCP port number." >&2
        exit 1
        ;;
esac

mkdir -p /data/uploads /data/backups /data/image-uploads
chown www-data:www-data /data /data/uploads /data/backups /data/image-uploads

sed -ri "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:80>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
