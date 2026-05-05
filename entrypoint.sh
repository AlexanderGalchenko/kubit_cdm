#!/bin/sh
set -e
mkdir -p /data /var/cache/nginx/kubit /var/cache/nginx/kubit_tmp
if [ ! -f /data/cache-path.conf ]; then
  echo 'proxy_cache_path /var/cache/nginx/kubit levels=1:2 keys_zone=kubit_cache:256m max_size=20g inactive=7d use_temp_path=off;' > /data/cache-path.conf
fi
if [ ! -f /data/cdn-location.conf ]; then
  cat > /data/cdn-location.conf <<'EOC'
return 503 'CDN node is not configured. Open /admin/ first.\n';
EOC
fi
chown -R www-data:www-data /data /var/cache/nginx/kubit /var/cache/nginx/kubit_tmp
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
