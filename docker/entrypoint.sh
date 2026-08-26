#!/bin/sh
set -e

cd /var/www/html

if [ "$DB_CONNECTION" = "sqlite" ]; then
    mkdir -p database
    touch "${DB_DATABASE:-database/database.sqlite}"
fi

php artisan config:cache
php artisan route:cache
php artisan migrate --force

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
