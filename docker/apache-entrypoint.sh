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
    # Literal opt-in only. CLI commands never invoke these startup gates.
    if [ "${CSMS_RUN_DEMO_IMPORT:-}" = '1' ] && [ "${CSMS_BOOTSTRAP_ADMIN:-}" = '1' ]; then
        echo 'Demo import cannot run with admin provisioning enabled.' >&2
        exit 1
    fi
    if [ "${CSMS_RUN_MIGRATIONS:-}" = '1' ]; then
        php /var/www/html/database/startup.php
    fi
    if [ "${CSMS_BOOTSTRAP_ADMIN:-}" = '1' ]; then
        php /var/www/html/database/provision_admin.php
    fi
    if [ "${CSMS_RUN_DEMO_IMPORT:-}" = '1' ]; then
        php /var/www/html/database/import_demo.php --apply --confirm-dataset=DEV-BSIT-PREORAL-2026
    fi
    # Do not pass provisioning secrets into the Apache/PHP worker environment.
    # Operators must still remove them from HostForge's stored configuration.
    unset CSMS_ADMIN_PASSWORD CSMS_ADMIN_USERNAME CSMS_ADMIN_EMAIL
    unset CSMS_ADMIN_FIRST_NAME CSMS_ADMIN_LAST_NAME
    unset CSMS_RUN_MIGRATIONS CSMS_MIGRATION_CONFIRM_DATABASE CSMS_BOOTSTRAP_ADMIN
    unset CSMS_RUN_DEMO_IMPORT CSMS_DEMO_IMPORT_CONFIRM_DATABASE CSMS_DEMO_IMPORT_CONFIRM_DATASET
    unset CSMS_DEMO_IMPORT_CONFIRM_MODE CSMS_DEMO_IMPORT_ACTOR_ID
fi

# Official entrypoint/foreground scripts ultimately exec Apache as PID 1.
exec docker-php-entrypoint "$@"
