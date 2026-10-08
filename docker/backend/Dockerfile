# Build context: repository root. PHP 8.3 matches backend/composer.lock.
FROM php:8.3-fpm-alpine

# -j1 keeps the extension compile within small dev VM memory.
ENV MAKEFLAGS="-j1"

RUN apk add --no-cache postgresql-dev libzip-dev zip unzip icu-dev oniguruma-dev su-exec $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath intl zip opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/. .
RUN composer dump-autoload --optimize --no-dev

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
