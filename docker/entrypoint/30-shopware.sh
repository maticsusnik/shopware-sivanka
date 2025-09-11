# Install packages
composer install --no-progress --no-interaction || echo "[ERROR] Composer did not complete. Check logs!"

# Run Shopaware commands
bin/console theme:compile || echo "[ERROR] theme:compile did not complete. Check logs!"
bin/console cache:clear || echo "[ERROR] cache:clear did not complete. Check logs!"