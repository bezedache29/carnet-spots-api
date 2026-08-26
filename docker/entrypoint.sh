#!/bin/sh
set -e

cd /var/www/html

mkdir -p database
touch "${DB_DATABASE:-database/database.sqlite}"

php artisan config:cache
php artisan route:cache
php artisan migrate --force

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
