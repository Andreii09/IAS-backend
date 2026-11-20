#!/bin/sh
set -e

# Render/Fly inject PORT; local Docker defaults to 80.
PORT="${PORT:-80}"
export PORT

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY must be provided (php artisan key:generate)." >&2
    exit 1
fi

# Only substitute ${PORT}; leave Nginx's own $uri/$query_string variables alone.
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf

cd /var/www/html

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan storage:link --force >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force --no-interaction
fi

if [ "${RUN_SEED:-false}" = "true" ]; then
    echo "Seeding initial data..."
    php artisan db:seed --force --no-interaction
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

echo "Starting Bantay Cordova API on port ${PORT}..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
