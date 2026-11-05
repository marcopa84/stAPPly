#!/bin/sh
# Avvio di Laravel nel container. La chiave dell'app serve a tutti i servizi; database,
# migration, template della demo e cache della configurazione li prepara solo il servizio web
# (STAPPLY_SETUP=1), così lo scheduler, che parte dopo, non li fa una seconda volta.
set -e

mkdir -p "$STAPPLY_DATA"

if [ -z "${APP_KEY:-}" ]; then
    # Generata una volta e tenuta nel volume: con una chiave nuova a ogni avvio sessioni e dati
    # cifrati (i segreti della doppia autenticazione) non si leggerebbero più.
    if [ ! -s "$STAPPLY_DATA/app_key" ]; then
        php artisan key:generate --show --no-ansi > "$STAPPLY_DATA/app_key"
    fi
    APP_KEY="$(cat "$STAPPLY_DATA/app_key")"
    export APP_KEY
fi

if [ "${STAPPLY_SETUP:-0}" = "1" ]; then
    [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
    php artisan migrate --force --no-interaction
    # Il template della demo torna ai valori di DemoSeeder a ogni avvio, come con db:seed in locale.
    php artisan db:seed --class=DemoSeeder --force --no-interaction
    php artisan config:cache --no-interaction
    php artisan view:cache --no-interaction
fi

exec docker-php-entrypoint "$@"
