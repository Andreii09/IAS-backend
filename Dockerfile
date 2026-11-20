# Bantay Cordova API — Laravel 12 + PHP-FPM + Nginx + Supervisor
# Build:  docker build -t bantay-cordova-api .
# Run:    docker run --env-file .env -p 8000:80 bantay-cordova-api
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        nginx \
        supervisor \
        gettext \
        git \
        unzip \
        libzip-dev \
        icu-dev \
        libpq-dev \
        oniguruma-dev \
    && docker-php-ext-install \
        pdo_mysql \
        pdo_pgsql \
        bcmath \
        intl \
        mbstring \
        zip \
        opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Laravel's post-autoload-dump boots artisan, so writable dirs must exist first.
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        bootstrap/cache \
        resources/views \
    && chown -R www-data:www-data storage bootstrap/cache resources

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
