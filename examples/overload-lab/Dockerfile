FROM php:8.4-fpm-alpine
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist
COPY src/ src/
COPY examples/overload-lab/ /var/www/html/examples/overload-lab/
COPY examples/overload-lab/php-fpm.conf /usr/local/etc/php-fpm.d/zz-overload-lab.conf
RUN addgroup -S lab && adduser -S -G lab lab && chown -R lab:lab /var/www/html
USER lab
