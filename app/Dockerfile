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

FROM php:8.4-fpm-alpine AS app

WORKDIR /app

RUN apk add --no-cache \
        icu-libs \
        libpq \
        nginx \
        supervisor \
        unzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        opcache \
        pdo \
        pdo_mysql \
        pdo_pgsql \
    && apk del --no-network .build-deps

COPY --from=vendor /app/vendor ./vendor
COPY app/ .
COPY app/docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY app/docker/php-fpm.zz-darts.conf /usr/local/etc/php-fpm.d/zz-darts.conf
COPY app/docker/supervisord.conf /etc/supervisord.conf
COPY app/docker/start-container.sh /usr/local/bin/start-container

RUN mkdir -p var/cache var/log \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /etc/nginx/templates \
    && addgroup -S symfony \
    && adduser -S -G symfony symfony \
    && rm -f /etc/nginx/http.d/default.conf \
    && chmod +x /usr/local/bin/start-container \
    && chown -R symfony:symfony /app \
    && chown -R nginx:nginx /run/nginx /var/lib/nginx /var/log/nginx

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV PORT=10000

EXPOSE 10000

CMD ["/usr/local/bin/start-container"]
