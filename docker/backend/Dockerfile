# OptiNexus API image (web = php-fpm, queue worker, scheduler and the one-shot bootstrap job all run this image).
# Build context: repository root.   docker build -f docker/backend/Dockerfile .
ARG PHP_IMAGE=php:8.4-fpm-alpine
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${PHP_IMAGE}

# -j1 keeps the extension compile within small VM memory.
ENV MAKEFLAGS="-j1"

RUN apk add --no-cache postgresql-dev libzip-dev zip unzip icu-dev oniguruma-dev su-exec $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath intl zip opcache pcntl \
    && apk del $PHPIZE_DEPS

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first (cached layer). COMPOSER_INSTALL_FLAGS lets a mirror-only network use --prefer-source; the default is the normal path.
ARG COMPOSER_INSTALL_FLAGS="--prefer-dist"
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction ${COMPOSER_INSTALL_FLAGS}

COPY backend/. .
RUN composer dump-autoload --optimize --no-dev --no-interaction

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /var/keys \
    && chown -R www-data:www-data storage bootstrap/cache /var/keys

# The entrypoint starts as root only to repair ownership of mounted volumes, then continues as www-data.
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
