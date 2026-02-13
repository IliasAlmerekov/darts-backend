FROM composer:2 AS vendor

WORKDIR /app

COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.4-cli-alpine AS app

WORKDIR /app

RUN apk add --no-cache \
        icu-dev \
        libpq-dev \
        postgresql-libs \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        opcache \
        pdo \
        pdo_mysql \
        pdo_pgsql \
    && apk del --no-network icu-dev libpq-dev

COPY --from=vendor /app/vendor ./vendor
COPY app/ .

RUN mkdir -p var/cache var/log \
    && addgroup -S symfony \
    && adduser -S -G symfony symfony \
    && chown -R symfony:symfony /app

USER symfony

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV PORT=8080

EXPOSE 8080

CMD ["sh", "-c", "php -d variables_order=EGPCS -S 0.0.0.0:${PORT} -t public public/index.php"]
