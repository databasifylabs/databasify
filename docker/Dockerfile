FROM ubuntu:26.04@sha256:6a06111c3b0a8600feadb270aec771dc239bf407b25625f93f44ab00e11f4d3c AS base

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl nginx unzip \
        php8.5-cli php8.5-fpm php8.5-bcmath php8.5-curl php8.5-gmp php8.5-intl php8.5-mbstring \
        php8.5-mysql php8.5-pgsql php8.5-redis php8.5-sqlite3 php8.5-xml php8.5-zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

FROM base AS vendor

COPY --from=composer:2.9@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=composer_auth \
    COMPOSER_AUTH="$(cat /run/secrets/composer_auth 2>/dev/null)" \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

FROM vendor AS assets

COPY --from=node:26.12.1-slim@sha256:94816674c62774da0d099a947bf4613b9e1972a2733abd0d0cd80e795f8411d0 /usr/local/bin/node /usr/local/bin/node
COPY --from=node:26.12.1-slim@sha256:94816674c62774da0d099a947bf4613b9e1972a2733abd0d0cd80e795f8411d0 /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libatomic1 \
    && rm -rf /var/lib/apt/lists/* \
    && ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && npm ci --no-audit --no-fund \
    && npm run build

FROM base

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/php-fpm.conf /etc/php/8.5/fpm/pool.d/www.conf
COPY docker/php.ini /etc/php/8.5/fpm/conf.d/99-databasify.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/databasify
COPY --chmod=644 docker/horizon-workers.sh /usr/local/lib/databasify/horizon-workers.sh

RUN rm -rf node_modules tests docker .github \
    && mkdir -p /run/php \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

VOLUME /app/storage
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD ["databasify", "healthcheck"]

ENTRYPOINT ["databasify"]
CMD ["web"]
