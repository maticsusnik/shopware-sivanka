#!/usr/bin/env bash
set -euo pipefail

# Unset base image env vars so Symfony dotenv reads them from .env.local
unset APP_ENV APP_DEBUG SHOPWARE_HTTP_CACHE_ENABLED SHOPWARE_SKIP_WEBINSTALLER

# Strict mode (dev/prod): a boot step that keeps failing aborts the start instead
# of leaving a container that looks up but serves a broken shop.
# Local development sets OW_ENTRYPOINT_STRICT=false, so the container still comes
# up when Shopware is not installed yet and you need to `docker exec` into it.
STRICT="${OW_ENTRYPOINT_STRICT:-true}"
STEP_ATTEMPTS="${OW_ENTRYPOINT_ATTEMPTS:-5}"
STEP_DELAY="${OW_ENTRYPOINT_RETRY_DELAY:-5}"

fail() {
    echo "[ERROR] $1"
    if [ "$STRICT" = "true" ]; then
        echo "[ERROR] Aborting startup. Set OW_ENTRYPOINT_STRICT=false to start anyway."
        exit 1
    fi
    echo "[WARN] OW_ENTRYPOINT_STRICT=false - continuing with a possibly broken application."
}

# Retries a boot step: the database or another dependency may still be starting.
run_step() {
    local name="$1"
    shift

    local attempt=1
    while true; do
        if "$@"; then
            return 0
        fi
        if [ "$attempt" -ge "$STEP_ATTEMPTS" ]; then
            fail "${name} failed after ${attempt} attempt(s)."
            return 1
        fi
        echo "[WARN] ${name} failed (attempt ${attempt}/${STEP_ATTEMPTS}), retrying in ${STEP_DELAY}s ..."
        attempt=$((attempt + 1))
        sleep "$STEP_DELAY"
    done
}

if [ "${DOCKER_ENTRYPOINT_DISABLE_RSYNC:-false}" != true ]; then
    rsync -ahv --delete \
        "/usr/src/shopware/" "/var/www/html/" \
        --include="/var/logs/" \
        --include="/var/config/" \
        --exclude="/vendor/" \
        --exclude="/public/*/" \
        --exclude="/var/*/" \
        --exclude="/.env.local" \
        --exclude="/config/jwt/*" \
        --exclude="/config/secrets/*" \
        --include="/var/cache/" \
        --exclude="/config/*.local.php"
    echo "[INFO] Updated Shopware files."
fi

# Dev dependencies (phpunit, phpstan, web profiler, shopware/dev-tools) are only
# wanted on local development. dev/prod install with --no-dev so the deployed
# vendor/ stays lean and dev-only tooling never ships to a public host.
# The role writes OW_COMPOSER_NO_DEV into .docker.env (false locally, true on
# dev/prod); override per host with shopware_composer_no_dev.
COMPOSER_INSTALL_ARGS=(--no-progress --no-interaction)
if [ "${OW_COMPOSER_NO_DEV:-true}" = "true" ]; then
    COMPOSER_INSTALL_ARGS+=(--no-dev)
fi

run_step "composer install" composer install "${COMPOSER_INSTALL_ARGS[@]}" || true

# Without an autoloader the app cannot serve a single request - always fatal.
# A failed `composer install` on top of an existing vendor/ is survivable: the
# previously installed dependencies are still in place.
if [ ! -f vendor/autoload.php ]; then
    echo "[ERROR] vendor/autoload.php is missing - Shopware cannot start."
    exit 1
fi

run_step "theme:compile" php bin/console theme:compile || true
run_step "translation:install" php bin/console translation:install --locales=sl-SI || true
run_step "cache:clear" php -d opcache.enable_cli=0 bin/console cache:clear || true

exec "$@"
