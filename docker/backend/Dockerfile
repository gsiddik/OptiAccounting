# Build context: repository root. PHP 8.3 matches backend/composer.lock. The root .dockerignore keeps .env files, vendor/, caches, logs and uploads out of the image.
ARG PHP_IMAGE=php:8.3-fpm-alpine
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${PHP_IMAGE}

# -j1 keeps the extension compile within small dev VM memory.
ENV MAKEFLAGS="-j1"

RUN apk add --no-cache postgresql-dev libzip-dev zip unzip icu-dev oniguruma-dev su-exec $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath intl zip opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# COMPOSER_INSTALL_FLAGS lets a mirror-only network use --prefer-source; the default is the normal path.
ARG COMPOSER_INSTALL_FLAGS="--prefer-dist"
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction ${COMPOSER_INSTALL_FLAGS}

COPY backend/. .
RUN composer dump-autoload --optimize --no-dev --no-interaction

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
# sed: a Windows checkout with core.autocrlf=true gives the script CRLF line ends, which /bin/sh cannot run.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# The entrypoint starts as root only to repair ownership of mounted volumes, then continues as www-data.
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
