#!/usr/bin/env bash
set -e

# Unset base image env vars so Symfony dotenv reads them from .env.local
unset APP_ENV APP_DEBUG SHOPWARE_HTTP_CACHE_ENABLED SHOPWARE_SKIP_WEBINSTALLER

if [ "$DOCKER_ENTRYPOINT_DISABLE_RSYNC" != true ]; then
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
  echo "[INFO] Updated Shopware files.";
fi

composer install --no-progress --no-interaction || echo "[ERROR] Composer did not complete. Check logs!"

bin/console theme:compile || echo "[ERROR] theme:compile did not complete. Check logs!"
php -d opcache.enable_cli=0 bin/console cache:clear || echo "[ERROR] cache:clear did not complete. Check logs!"

exec "$@"
