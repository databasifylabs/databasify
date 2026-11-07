FROM dunglas/frankenphp:1.13.3-php8.5.12-alpine@sha256:6c330d81614c3e451beb6c9d3aec7e3893e4e46007e2e3cd5b2056c7bd3c0ffa AS base

RUN install-php-extensions bcmath gmp intl opcache pcntl pdo_mysql pdo_pgsql redis zip \
    && apk add --no-cache curl

WORKDIR /app

FROM base AS vendor

COPY --from=composer:2.9@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

FROM vendor AS assets

RUN apk add --no-cache nodejs npm
RUN npm ci --no-audit --no-fund && npm run build

FROM base

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=redis \
    BROADCAST_CONNECTION=reverb \
    REDIS_HOST=valkey \
    REVERB_HOST=reverb \
    REVERB_PORT=8080 \
    REVERB_SCHEME=http \
    SERVER_NAME=:80

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/databasify
COPY --chmod=644 docker/horizon-workers.sh /usr/local/lib/databasify/horizon-workers.sh

RUN rm -rf node_modules tests docker .github \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

VOLUME /app/storage
EXPOSE 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD ["databasify", "healthcheck"]

ENTRYPOINT ["databasify"]
CMD ["web"]
