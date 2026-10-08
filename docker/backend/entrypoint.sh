#!/bin/sh
set -e

if [ "$(id -u)" = "0" ]; then
  exec su-exec www-data "$0" "$@"
fi

# Only the web container migrates; queue/scheduler containers skip it.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "Waiting for database..."
  until php artisan db:show > /dev/null 2>&1; do sleep 1; done
  php artisan migrate --force
  php artisan db:seed --force   # production-safe DatabaseSeeder only
fi

php artisan config:cache
php artisan route:cache

exec "$@"
