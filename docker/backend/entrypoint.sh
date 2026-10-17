#!/bin/sh
# OptiNexus container entrypoint.
#   entrypoint.sh bootstrap   one-shot job: preflight, wait for the database, signing keys (only when absent), migrations, catalog seed
#   entrypoint.sh <anything>  runtime role (php-fpm, queue:work, schedule:work): waits (bounded) for the bootstrap to have finished, then runs
# Only the bootstrap job changes the database schema. Waits are bounded and end with a clear message; an invalid configuration exits instead of looping.
set -eu

APP_USER=www-data
WRITABLE_DIRS="storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache"
KEY_DIR="${PASSPORT_KEY_PATH:-/var/keys}"

fail() { echo "ERROR: $*" >&2; exit 1; }

if [ "$(id -u)" = "0" ]; then
  mkdir -p $WRITABLE_DIRS "$KEY_DIR"
  find storage bootstrap/cache "$KEY_DIR" ! -user "$APP_USER" -exec chown "$APP_USER:$APP_USER" {} + 2>/dev/null || true
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

if [ "${1:-}" = "bootstrap" ]; then
  echo "[bootstrap] checking configuration"
  php artisan ecosystem:preflight --skip-database --skip-keys || fail "the configuration was refused (see above); fix it and run the bootstrap again"

  echo "[bootstrap] waiting for the database (at most ${DB_WAIT_SECONDS}s)"
  wait_until "$DB_WAIT_SECONDS" database php artisan db:show --no-interaction \
    || { php artisan ecosystem:preflight || true; fail "the database did not become reachable within ${DB_WAIT_SECONDS}s"; }

  # Signing keys are created once and live on the keys volume; they are never regenerated on restart.
  if [ -z "${OIDC_PRIVATE_KEY:-}" ] && [ ! -f "$KEY_DIR/oauth-private.key" ]; then
    echo "[bootstrap] creating signing keys in $KEY_DIR"
    php artisan passport:keys --no-interaction
  fi

  echo "[bootstrap] migrating"
  php artisan migrate --force --no-interaction

  case "${NEXUS_SEED:-catalog}" in
    catalog) php artisan db:seed --class=ProductionSeeder --force --no-interaction ;;
    demo) php artisan db:seed --force --no-interaction ;;
    none) echo "[bootstrap] NEXUS_SEED=none: no seeding" ;;
    *) fail "NEXUS_SEED must be catalog, demo or none" ;;
  esac

  php artisan ecosystem:preflight || fail "the installation is not usable after the bootstrap (see above)"
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
