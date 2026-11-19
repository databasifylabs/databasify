# https://www.php.net/releases/
ARG PHP_VERSION=8.5.13
# https://nginx.org/packages/mainline/debian/pool/nginx/n/nginx/
ARG NGINX_VERSION=1.31.9
# https://hub.docker.com/_/redis/tags?name=-trixie
ARG REDIS_VERSION=8.8.4

FROM redis:${REDIS_VERSION}-trixie AS redis

FROM php:${PHP_VERSION}-fpm-trixie AS base

ARG NGINX_VERSION
ENV DEBIAN_FRONTEND=noninteractive

COPY --from=mlocati/php-extension-installer:2.13.2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gpg \
    && curl -fsSL https://nginx.org/keys/nginx_signing.key -o /tmp/nginx_signing.key \
    && gpg --show-keys --with-colons /tmp/nginx_signing.key | grep -q '^fpr:::::::::573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62:$' \
    && gpg --dearmor -o /usr/share/keyrings/nginx-archive-keyring.gpg /tmp/nginx_signing.key \
    && rm /tmp/nginx_signing.key \
    && echo 'deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] https://nginx.org/packages/mainline/debian trixie nginx' > /etc/apt/sources.list.d/nginx.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends "nginx=${NGINX_VERSION}-*" supervisor unzip \
    && install-php-extensions bcmath gmp intl mysqli pcntl pdo_mysql pdo_pgsql pgsql redis sockets zip \
    && apt-get purge -y --auto-remove gpg \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/conf.d/default.conf /usr/local/etc/php-fpm.d/* \
    && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && nginx -v

WORKDIR /app

FROM base AS vendor

COPY --from=composer:2.9@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=composer_auth \
    COMPOSER_AUTH="$(cat /run/secrets/composer_auth 2>/dev/null)" \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer check-platform-reqs --no-dev \
    && composer dump-autoload --optimize --no-dev \
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
COPY --from=redis /usr/local/bin/redis-server /usr/local/bin/redis-cli /usr/local/bin/
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-databasify.ini
COPY docker/supervisord.conf /etc/supervisor/databasify.conf
COPY docker/redis.conf /etc/redis/databasify.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/databasify

RUN rm -rf node_modules tests docker .github \
    && useradd --system --user-group --home-dir /var/lib/redis --no-create-home --shell /usr/sbin/nologin redis \
    && mkdir -p /run/php /var/lib/redis \
    && chown redis:redis /var/lib/redis \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

VOLUME /app/storage /var/lib/redis
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD ["databasify", "healthcheck"]

ENTRYPOINT ["databasify"]
CMD ["start"]
