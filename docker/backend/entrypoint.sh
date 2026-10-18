#!/bin/sh
# OptiEntry container entrypoint.
#   entrypoint.sh bootstrap   one-shot job: configuration check, wait for the database and Redis, migrations, production-safe catalog seed
#   entrypoint.sh <anything>  runtime role (php-fpm, queue:work, schedule:work): waits (bounded) for the bootstrap to have finished, then runs
# Only the bootstrap job changes the database schema. Waits are bounded and end with a clear message; an invalid configuration exits instead of looping.
set -eu

APP_USER=www-data
WRITABLE_DIRS="storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache"

fail() { echo "ERROR: $*" >&2; exit 1; }

if [ "$(id -u)" = "0" ]; then
  mkdir -p $WRITABLE_DIRS
  find storage bootstrap/cache ! -user "$APP_USER" -exec chown "$APP_USER:$APP_USER" {} + 2>/dev/null || true
  exec su-exec "$APP_USER" "$0" "$@"
fi

mkdir -p $WRITABLE_DIRS

DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-90}"
BOOTSTRAP_WAIT_SECONDS="${BOOTSTRAP_WAIT_SECONDS:-300}"

# wait_until <seconds> <what> <command...>: run the command every 2 s until it succeeds or the time is up.
wait_until() {
  limit="$1"; what="$2"; shift 2
  waited=0
  until "$@" >/dev/null 2>&1; do
    waited=$((waited + 2))
    [ "$waited" -lt "$limit" ] || return 1
    sleep 2
  done
}

redis_up() { php -r 'exit(@fsockopen(getenv("REDIS_HOST") ?: "redis", (int) (getenv("REDIS_PORT") ?: 6379), $e, $s, 2) ? 0 : 1);'; }

if [ "${1:-}" = "bootstrap" ]; then
  echo "[bootstrap] checking configuration"
  [ -n "${APP_KEY:-}" ] || fail "APP_KEY is empty (generate one with: php artisan key:generate --show)"
  case "${OPTIENTRY_IDENTITY_MODE:-standalone}" in
    standalone) ;;
    optinexus) [ -n "${OPTINEXUS_BASE_URL:-}" ] || fail "OPTIENTRY_IDENTITY_MODE=optinexus needs OPTINEXUS_BASE_URL" ;;
    *) fail "OPTIENTRY_IDENTITY_MODE must be standalone or optinexus" ;;
  esac

  echo "[bootstrap] waiting for the database (at most ${DB_WAIT_SECONDS}s)"
  wait_until "$DB_WAIT_SECONDS" database php artisan db:show --no-interaction \
    || fail "the database did not become reachable within ${DB_WAIT_SECONDS}s (check DB_HOST/DB_PASSWORD)"
  wait_until "$DB_WAIT_SECONDS" redis redis_up \
    || fail "Redis did not become reachable within ${DB_WAIT_SECONDS}s (check REDIS_HOST)"

  echo "[bootstrap] migrating"
  php artisan migrate --force --no-interaction
  echo "[bootstrap] seeding catalogs (production-safe seeder)"
  php artisan db:seed --force --no-interaction
  echo "[bootstrap] complete"
  exit 0
fi

# Runtime roles never migrate. They start when the schema is current, or stop with a message instead of looping forever.
pending() { php artisan migrate:status --pending --no-interaction 2>&1 | grep -qi "no pending migrations"; }
wait_until "$BOOTSTRAP_WAIT_SECONDS" bootstrap pending \
  || fail "the database schema is not current after ${BOOTSTRAP_WAIT_SECONDS}s: run the bootstrap job (docker compose run --rm bootstrap) and start again"

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

exec "$@"
