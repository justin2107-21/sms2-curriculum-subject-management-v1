FROM php:8.2-apache-bookworm

# mysqli/mysqlnd is required; curl, DOM, fileinfo and core JSON/session support
# are already provided by this official image. No GD/intl/zip/PDO driver usage.
RUN docker-php-ext-install -j"$(nproc)" mysqli \
    && a2enmod rewrite \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php-production.ini /usr/local/etc/php/conf.d/csms.ini
COPY docker/apache-vhost.conf /usr/local/etc/csms/apache-vhost.conf
COPY docker/apache-entrypoint.sh /usr/local/bin/csms-entrypoint
COPY docker/healthcheck.php /usr/local/bin/csms-healthcheck.php
WORKDIR /var/www/html
COPY . /var/www/html/
RUN chmod 755 /usr/local/bin/csms-entrypoint \
    && find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && install -d -o www-data -g www-data -m 700 /var/lib/php/csms-sessions \
    && php -r 'foreach (["mysqli","mysqlnd","curl","dom","fileinfo","json","session","ctype","filter"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing required PHP extension.\n"); exit(1); } }'

# Secure cookies are correct behind HostForge HTTPS without trusting forwarded
# headers. Override to 0 ONLY for an isolated local HTTP Docker test.
ENV APP_ENV=production PORT=8080 SMS_SESSION_COOKIE_SECURE=1 SMS_ALLOW_SETUP=0
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["php", "/usr/local/bin/csms-healthcheck.php"]
ENTRYPOINT ["/usr/local/bin/csms-entrypoint"]
CMD ["apache2-foreground"]
