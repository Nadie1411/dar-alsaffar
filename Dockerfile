# Production image: FrankenPHP (Caddy + PHP 8.4) serving public/ on :8080.
# The host nginx terminates TLS and proxies to this container.
FROM dunglas/frankenphp:1-php8.4-alpine

RUN install-php-extensions intl zip bcmath pdo_sqlite opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Run as the same uid as the deploy user on the VPS so bind-mounted
# storage/, uploads/ and .env stay writable by both.
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app \
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R app:app /data/caddy /config/caddy

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && mkdir -p public/uploads \
    && chown -R app:app storage bootstrap/cache public/uploads

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

USER app
ENV SERVER_NAME=":8080" \
    APP_ENV=production

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
