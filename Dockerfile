FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
COPY artisan ./artisan
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

FROM php:8.4-fpm-alpine AS runtime

WORKDIR /var/www/html

RUN apk add --no-cache \
    $PHPIZE_DEPS \
    bash \
    curl \
    icu-dev \
    libzip-dev \
    postgresql-dev \
    zip \
    && docker-php-ext-install \
    bcmath \
    intl \
    opcache \
    pdo_pgsql \
    pgsql \
    zip

COPY --from=vendor /app /var/www/html
COPY public ./public
COPY resources ./resources
COPY storage ./storage

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
