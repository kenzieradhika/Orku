FROM composer:2 AS composer
FROM php:8.4-cli-bookworm
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /fixture
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
ENTRYPOINT ["php", "/orku/benchmarks/release/ssr_compare.php", "--dependency-root", "/fixture"]
