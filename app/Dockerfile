# syntax=docker/dockerfile:1
#
# Production image for the darts backend: nginx and php-fpm under supervisord.
# Build from the repository root:
#
#   docker build -f app/Dockerfile --target runtime -t darts-backend .
#
# Targets:
#   runtime  production image (default, last stage)
#   test     runtime code plus dev dependencies, for running PHPUnit against the image build

FROM composer:2 AS composer

FROM php:8.4-fpm-alpine AS base

WORKDIR /app

RUN apk add --no-cache \
        icu-libs \
        libpq \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        opcache \
        pdo_mysql \
        pdo_pgsql \
    && apk del --no-network .build-deps \
    && addgroup -S -g 10001 symfony \
    && adduser -S -D -H -u 10001 -G symfony -h /app symfony

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'

# Install dependencies, build an authoritative classmap that includes App\ classes, and
# warm the prod cache so FPM workers never compile the container under their memory
# limit. Composer auto-scripts stay off: the API serves no bundle assets or importmap
# (the Swagger UI needs Twig, which is a dev-only dependency), and importmap:install
# would download from a CDN during the build. cache:warmup needs DATABASE_URL to be
# set, so that one command gets a dummy value without credentials; it is not written
# to the cache or to the image.
FROM base AS build

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist

COPY app/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && DATABASE_URL='mysql://warmup@127.0.0.1:3306/warmup?serverVersion=8.4&charset=utf8mb4' \
        php -d memory_limit=-1 bin/console cache:warmup --no-interaction \
    && mkdir -p var/log var/sessions

# Build-only and test-only files stay out of the runtime image.
FROM build AS prune

RUN rm -rf \
        .env \
        .env.dev \
        .env.test \
        tests \
        docker \
        Dockerfile \
        compose.yaml \
        compose.override.yaml \
        phpunit.dist.xml \
        psalm.xml \
        psalm-baseline.xml \
        phpcs.xml.dist \
        build

FROM base AS test

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=test \
    APP_DEBUG=1 \
    APP_RUNTIME_OPTIONS='{}'

COPY --from=build /app ./
RUN composer install --no-scripts --no-interaction --no-progress --prefer-dist \
    && rm -rf var/cache/prod
# Defaults come from the committed .env and .env.test. Point DATABASE_URL at a test
# database with `docker run -e DATABASE_URL=...`.
CMD ["php", "-d", "memory_limit=-1", "vendor/bin/phpunit"]

FROM base AS runtime

RUN apk add --no-cache \
        nginx \
        supervisor

COPY app/docker/php.ini "$PHP_INI_DIR/conf.d/zz-darts.ini"
COPY app/docker/php-fpm.zz-darts.conf /usr/local/etc/php-fpm.d/zz-darts.conf
COPY app/docker/nginx.conf /etc/nginx/nginx.conf
COPY app/docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY app/docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=755 app/docker/start-container.sh /usr/local/bin/start-container

# Code stays root-owned and read-only for the app user; only var/ is writable.
COPY --from=prune /app ./
COPY --from=prune --chown=symfony:symfony /app/var ./var

# Secrets stay in the mounted files and are read by Symfony env processors at runtime,
# so FPM, `docker compose run` and `docker compose exec` all see them without an
# entrypoint. APP_SECRET_FILE is read by config/packages/framework.yaml (when@prod).
# DATABASE_URL is a template: doctrine.yaml applies `resolve:`, which fills in the
# URL-encoded password from DATABASE_PASSWORD_FILE. Overriding DATABASE_URL or setting
# APP_SECRET directly still works for platforms that inject plain variables.
ENV PORT=10000 \
    APP_SECRET_FILE=/run/secrets/app_secret \
    DATABASE_PASSWORD_FILE=/run/secrets/mysql_password \
    DATABASE_URL='mysql://darts:%env(urlencode:trim:file:DATABASE_PASSWORD_FILE)%@mysql:3306/darts?serverVersion=8.4&charset=utf8mb4'

USER symfony

EXPOSE 10000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -q -O /dev/null "http://127.0.0.1:${PORT}/api/health" || exit 1

CMD ["/usr/local/bin/start-container"]
