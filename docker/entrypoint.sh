#!/bin/sh
# Hopln API container entrypoint.
# Ensures storage directories exist before PHP-FPM starts.
# The storage_data volume may be empty on first boot, so we recreate the structure.

set -e

mkdir -p \
  /var/www/storage/framework/cache/data \
  /var/www/storage/framework/sessions \
  /var/www/storage/framework/testing \
  /var/www/storage/framework/views \
  /var/www/storage/logs \
  /var/www/storage/app/gtfs \
  /var/www/storage/app/public

chown -R www-data:www-data \
  /var/www/storage \
  /var/www/bootstrap/cache

exec "$@"
