# FaPost Core images.
#
# One file with two published targets, `core` and `web`, because they share the
# same Composer install. The front-end cannot be built without it: the Filament
# theme imports CSS from vendor/, so the Node stage has to run after Composer.
# Split across two Dockerfiles that dependency would have to be installed twice,
# and the two copies could resolve differently.
#
#   docker build --target core -t fapost/core .
#   docker build --target web  -t fapost/web  .
#
# The application image serves three roles — PHP-FPM, Horizon and the scheduler —
# which differ only in the command. Building them separately would let their
# dependencies drift, which is how "works on the web tier, fails in the worker"
# happens.

# ---------------------------------------------------------------------------
# base — PHP with every extension the application and its workers need
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

# The -dev packages are build inputs for the extensions below and are removed
# again once compilation finishes.
RUN apk add --no-cache \
        bash \
        postgresql-client \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        bcmath \
        zip \
        gd \
        opcache \
        pcntl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# pcntl and posix are what Horizon supervises workers with. Missing them is a
# silent failure: the web tier serves fine while no queued job ever runs, so the
# check belongs in the build rather than in an incident.
RUN php -m | grep -qx pcntl \
    && php -m | grep -qx posix \
    && php -m | grep -qx redis \
    && php -m | grep -qx pdo_pgsql

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# deps — Composer dependencies
#
# Runs on the base image rather than the composer image on purpose: resolution
# then happens where the extensions actually exist, so a missing one fails the
# build instead of the first request.
# ---------------------------------------------------------------------------
FROM base AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Manifests before source: this layer survives every change that does not touch
# dependencies, which is most of them.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

# ---------------------------------------------------------------------------
# assets — compiled front-end
# ---------------------------------------------------------------------------
FROM node:26-alpine AS assets

WORKDIR /build

COPY package.json package-lock.json* ./
RUN npm ci

# The Filament theme imports its stylesheet straight out of vendor/, so the
# Composer result has to be here before Vite runs.
COPY --from=deps /var/www/html/vendor ./vendor

COPY vite.config.ts tsconfig*.json ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# ---------------------------------------------------------------------------
# core — the application image (PHP-FPM, Horizon, scheduler)
# ---------------------------------------------------------------------------
FROM base AS core

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-fapost.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-fapost.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY --from=deps --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .

# Storage and the cache directory are the only writable paths; everything else
# stays owned by root so a compromised worker cannot rewrite application code.
#
# Package discovery runs here because it needs the full tree, and Composer is
# removed afterwards — a runtime image has no business installing packages.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && composer run-script post-autoload-dump --no-interaction \
    && rm -f /usr/bin/composer

ARG VERSION=dev
LABEL org.opencontainers.image.version=$VERSION

USER www-data

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# dev — the same runtime, arranged for working on the code
#
# Built from the same `base` as production on purpose. A separate development
# image — Sail or anything else assembled independently — would have its own PHP
# build and its own extension set, and every difference between the two becomes
# a bug that only appears after deployment. Sharing the base leaves exactly the
# differences that are meant to be there:
#
#   - dev Composer dependencies (Telescope, Pail, ide-helper)
#   - source bind-mounted rather than copied, so edits are live
#   - opcache revalidating, or nothing you type would take effect
#   - Xdebug, optional and off by default because it is not free
#   - runs as the host user, so files it creates stay editable outside
# ---------------------------------------------------------------------------
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node lives here too: development runs Vite in watch mode rather than compiling
# assets ahead of time.
RUN apk add --no-cache nodejs npm git

ARG INSTALL_XDEBUG=0
RUN if [ "$INSTALL_XDEBUG" = "1" ]; then \
        # linux-headers is not part of PHPIZE_DEPS, and Xdebug's configure stops
        # on a missing rtnetlink.h without it.
        apk add --no-cache --virtual .xdebug-deps $PHPIZE_DEPS linux-headers \
        && pecl install xdebug \
        && docker-php-ext-enable xdebug \
        && apk del .xdebug-deps; \
    fi

COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/99-fapost.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-fapost.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# The container runs as the developer's own user id. Without this, anything the
# container writes — migrations, cache, generated files — comes back owned by
# root on Linux hosts and cannot be edited without sudo.
ARG UID=1000
ARG GID=1000
RUN if [ "$UID" != "0" ]; then \
        deluser www-data 2>/dev/null || true; \
        addgroup -g "$GID" -S app 2>/dev/null || true; \
        adduser -u "$UID" -G app -S -H -D app 2>/dev/null || true; \
    fi

USER $UID:$GID

EXPOSE 9000

# No source is baked in: compose mounts the working tree over /var/www/html.
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# web — nginx with the compiled front-end
#
# Self-contained so the published image needs nothing mounted from this
# repository, and so the assets can never fall out of step with a volume that
# Docker refuses to re-seed once it is non-empty.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# PHP files are present but never executed here — nginx passes those to FPM.
COPY --from=assets /build/public /var/www/html/public

ARG VERSION=dev
LABEL org.opencontainers.image.version=$VERSION

EXPOSE 80
