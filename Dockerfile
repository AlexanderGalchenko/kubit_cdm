FROM debian:12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx php8.2-fpm php8.2-sqlite3 php8.2-curl supervisor ca-certificates curl \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/php /data /var/cache/nginx/kubit /var/log/supervisor

COPY nginx/nginx.conf /etc/nginx/nginx.conf
COPY nginx/default.conf /etc/nginx/conf.d/default.conf
COPY supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY app /var/www/admin
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && chown -R www-data:www-data /var/www/admin /data /var/cache/nginx/kubit \
    && chmod +x /var/www/admin/reload_nginx.sh

EXPOSE 80
CMD ["/entrypoint.sh"]
