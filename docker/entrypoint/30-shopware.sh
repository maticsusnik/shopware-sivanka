# Install packages
# Rsync

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

# Run Shopaware commands
bin/console theme:compile || echo "[ERROR] theme:compile did not complete. Check logs!"
bin/console cache:clear || echo "[ERROR] cache:clear did not complete. Check logs!"

find /var/www/html/ \( -path /var/www/html/public/media -o -path /var/www/html/public/thumbnail \) -prune -o \( ! -user application -o ! -group application \) -exec chown application:application {} +
