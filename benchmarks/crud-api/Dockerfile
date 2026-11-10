FROM php:8.4-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY benchmarks/crud-api/php-bench.ini /usr/local/etc/php/conf.d/zz-bench.ini
COPY benchmarks/crud-api/php-fpm.conf /usr/local/etc/php-fpm.d/zz-bench.conf

FROM base AS orku

WORKDIR /orku
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative --no-scripts
COPY src ./src
COPY benchmarks/crud-api ./benchmarks/crud-api
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p /data && chown www-data:www-data /data

FROM base AS laravel

WORKDIR /crud/laravel
COPY benchmarks/crud-api/laravel/composer.json benchmarks/crud-api/laravel/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative --no-scripts
COPY benchmarks/crud-api/laravel/ ./
COPY benchmarks/crud-api/CrudStore.php /crud/CrudStore.php
COPY benchmarks/crud-api/schema.sql /crud/schema.sql
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /data \
    && chown -R www-data:www-data storage bootstrap/cache /data
