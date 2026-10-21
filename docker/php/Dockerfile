# LedgerFlow API image (backlog P0.2-BE-01). Build from the repo root:
#   docker build --target dev  -f docker/php/Dockerfile .   (source mounted by compose)
#   docker build --target prod -f docker/php/Dockerfile .   (source and vendor baked in)

FROM composer:2.8.12 AS composer

# ---------------------------------------------------------------- base: PHP + extensions + non-root user
FROM php:8.3-fpm-bookworm AS base

# phpredis comes from its pinned GitHub release instead of PECL: pecl.php.net downloads failed (504) on 21 Oct 2026
# and blocked deploys; a pinned tarball is also reproducible.
ARG PHPREDIS_VERSION=6.2.0
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev default-mysql-client \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl zip bcmath opcache pcntl \
 && docker-php-source extract \
 && mkdir -p /usr/src/php/ext/redis \
 && curl -fsSL --retry 5 --retry-delay 3 "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" \
    | tar -xz -C /usr/src/php/ext/redis --strip-components=1 \
 && docker-php-ext-install -j"$(nproc)" redis \
 && docker-php-source delete \
 && rm -rf /var/lib/apt/lists/*

# Password hashing is Argon2id (07 §2); fail the build if this PHP lacks it.
RUN php -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);'

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
# Keep the container environment (env_file in compose) visible to PHP-FPM workers.
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-app.conf

# Files written to mounted volumes belong to the developer: match their uid/gid (macOS gid 20 clashes, hence -o).
ARG UID=1000
ARG GID=1000
RUN groupadd -o -g "${GID}" app && useradd -o -m -u "${UID}" -g app -s /bin/bash app

WORKDIR /var/www/html

# ---------------------------------------------------------------- dev: source is mounted at /var/www/html
FROM base AS dev
COPY docker/php/conf.d/opcache-dev.ini /usr/local/etc/php/conf.d/zz-opcache.ini
USER app
CMD ["php-fpm"]

# ---------------------------------------------------------------- prod: source baked in, no dev dependencies
FROM base AS prod
COPY docker/php/conf.d/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY --chown=app:app composer.json composer.lock ./
USER app
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --no-progress
COPY --chown=app:app . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi \
 && mkdir -p storage/keys storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache
CMD ["php-fpm"]
