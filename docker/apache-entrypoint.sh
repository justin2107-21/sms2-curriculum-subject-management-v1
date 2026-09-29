#!/bin/sh
set -eu

# CLI maintenance must not start Apache or initialize the database.
if [ "${1:-}" = 'apache2-foreground' ]; then
    port=${PORT:-8080}
    case "$port" in
        ''|*[!0-9]*|0*) echo 'Invalid PORT: expected an integer from 1 to 65535.' >&2; exit 1 ;;
    esac
    if [ "${#port}" -gt 5 ] || [ "$port" -gt 65535 ]; then
        echo 'Invalid PORT: expected an integer from 1 to 65535.' >&2
        exit 1
    fi
    export PORT="$port"
    printf 'Listen %s\n' "$port" > /etc/apache2/ports.conf
    sed "s/@PORT@/$port/g" /usr/local/etc/csms/apache-vhost.conf > /etc/apache2/sites-available/000-default.conf
fi

# Official entrypoint/foreground scripts ultimately exec Apache as PID 1.
exec docker-php-entrypoint "$@"
