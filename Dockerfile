FROM serversideup/php:8.4-frankenphp-trixie

USER root

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock* ./

RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-dev

COPY --chown=www-data:www-data . .

RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html

ENV CADDY_HTTP_PORT=80
ENV CADDY_SERVER_ROOT=/var/www/html
ENV STORAGE_PATH=/var/www/html/data
ENV SESSION_LIFETIME_HOURS=24
ENV SESSION_ID=

USER www-data

EXPOSE 80
