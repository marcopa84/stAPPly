# syntax=docker/dockerfile:1
# stAPPly, frontend Laravel servito da FrankenPHP. Tre stadi: le dipendenze PHP; gli asset con
# Vite, che ha bisogno di vendor/ perché app.css importa il CSS di Flux; l'immagine finale,
# senza Node né Composer.

FROM dunglas/frankenphp:1-php8.4-bookworm AS base
# intl per Number::format e Number::fileSize; pdo_sqlite e OPcache ci sono già.
RUN install-php-extensions intl zip
WORKDIR /app

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist
COPY . .
# Sorgenti leggibili dall'utente senza privilegi dell'immagine finale, qualunque sia la umask di
# chi li ha copiati: qui, in uno stadio intermedio, il chmod non pesa sull'immagine.
RUN chmod -R a+rX /app \
    && composer dump-autoload --optimize --no-dev --no-interaction

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /app ./
RUN npm run build

FROM base
ENV SERVER_NAME=:8080 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    STAPPLY_DATA=/var/lib/stapply \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/lib/stapply/database.sqlite

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/entrypoint.sh /usr/local/bin/stapply-entrypoint

# Utente senza privilegi: la porta 8080 non ne chiede. /config/caddy e /data/caddy sono le
# cartelle di Caddy; STAPPLY_DATA (database SQLite e chiave dell'app) è il volume di compose.yaml.
RUN useradd --system --uid 10001 --home-dir /app stapply \
    && mkdir -p "$STAPPLY_DATA" /config/caddy /data/caddy \
    && chown -R stapply:stapply "$STAPPLY_DATA" storage bootstrap/cache /config/caddy /data/caddy
USER stapply

EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --start-period=30s --retries=6 \
    CMD ["curl", "-fsS", "http://127.0.0.1:8080/up"]

ENTRYPOINT ["stapply-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
