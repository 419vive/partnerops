# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-apache-bookworm AS php-base

# libpq bounds establishment of a fresh connection. The readiness probe
# separately applies a transaction-local PostgreSQL statement timeout.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APP_RUNTIME_OPTIONS='{"disable_dotenv":true}' \
    APP_TIMEZONE=Asia/Taipei \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PGCONNECT_TIMEOUT=3

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        curl \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        unzip; \
    docker-php-ext-install -j"$(nproc)" intl mbstring opcache pdo_pgsql; \
    a2enmod headers rewrite; \
    sed -ri 's!^Listen 80$!Listen 8080!' /etc/apache2/ports.conf; \
    rm -rf /var/lib/apt/lists/*; \
    { \
        echo 'expose_php=Off'; \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'memory_limit=256M'; \
        echo 'variables_order=EGPCS'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.jit=off'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
    } > "$PHP_INI_DIR/conf.d/partnerops.ini"

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

RUN set -eux; \
    mkdir -p var /var/run/apache2 /var/lock/apache2; \
    chown -R www-data:www-data var /var/run/apache2 /var/lock/apache2

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --show-error http://127.0.0.1:8080/health/live >/dev/null || exit 1

CMD ["apache2-foreground"]


FROM composer:2.10.2 AS production-vendor

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY . .

RUN composer install \
    --classmap-authoritative \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist


FROM php-base AS development

ENV APP_DEBUG=1 \
    APP_ENV=dev

COPY --from=composer:2.10.2 /usr/bin/composer /usr/local/bin/composer
COPY . .

RUN set -eux; \
    composer install --no-interaction --no-progress --no-scripts --prefer-dist; \
    mkdir -p var public/assets .phpunit.cache /var/www/.composer; \
    chown -R www-data:www-data var public/assets .phpunit.cache /var/www/.composer; \
    chmod 0770 var public/assets .phpunit.cache /var/www/.composer

USER www-data


FROM php-base AS production

ENV APP_DEBUG=0 \
    APP_ENV=prod

COPY --from=production-vendor /app/assets ./assets
COPY --from=production-vendor /app/bin ./bin
COPY --from=production-vendor /app/config ./config
COPY --from=production-vendor /app/migrations ./migrations
COPY --from=production-vendor /app/public ./public
COPY --from=production-vendor /app/src ./src
COPY --from=production-vendor /app/templates ./templates
COPY --from=production-vendor /app/vendor ./vendor
COPY --from=production-vendor /app/composer.json /app/composer.lock /app/importmap.php /app/symfony.lock ./

RUN set -eux; \
    APP_SECRET=build-only-placeholder \
    DEFAULT_URI=http://localhost:8080 \
    API_TOKEN_PEPPER=build-only-placeholder \
    DATABASE_URL='postgresql://build:build@127.0.0.1:5432/build?serverVersion=16&charset=utf8' \
        php bin/console asset-map:compile --env=prod --no-debug; \
    rm -rf var/cache/*; \
    chown -R www-data:www-data var; \
    chmod 0770 var; \
    chmod -R go-w assets bin config migrations public src templates vendor

USER www-data
