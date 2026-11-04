#!/usr/bin/env bash
# restore <dump.sql|dump.sql.gz> [-y]
# Replace the LOCAL database with a production dump, then make it safe for local work.
# Production is never contacted: the dump is a file Matic downloaded himself.
set -euo pipefail
source "$(dirname "$0")/../config.sh"

DUMP="${1:-}"; YES=0
[[ "${2:-}" == "-y" ]] && YES=1
[[ -f "$DUMP" ]] || die "usage: enrich.sh restore <dump.sql[.gz]> [-y]"

cat_dump() { case "$DUMP" in *.gz) zcat -- "$DUMP" ;; *) cat -- "$DUMP" ;; esac; }

info "checking dump $(du -h "$DUMP" | cut -f1)"
[[ "$DUMP" != *.gz ]] || gzip -t "$DUMP" || die "not a valid gzip file"
cat_dump | tail -n 3 | grep -q "Dump completed" || die "dump looks truncated (no 'Dump completed' trailer)"
# (no `grep -q` in a pipe: it exits early, the writer gets SIGPIPE and pipefail reports a failure)
[[ "$(cat_dump | grep -a -c 'CREATE TABLE `product`')" -ge 1 ]] || die "no product table in the dump: is this a Shopware dump?"

if ((YES == 0)); then
    read -r -p "Drop the LOCAL database '$DB_NAME' and import $DUMP? Type yes: " a </dev/tty
    [[ "$a" == yes ]] || die "aborted"
fi

# 1. safety copy of the current local database (gitignored, kept until you delete it)
mkdir -p "$RUNS_DIR/backups"
BK="$RUNS_DIR/backups/local-before-restore-$(date +%Y%m%d-%H%M%S).sql.gz"
info "backing up the current local database -> $BK"
"${COMPOSE[@]}" exec -T "$DB_SERVICE" sh -c \
    'exec mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$1"' -- "$DB_NAME" \
    | gzip > "$BK"
zcat "$BK" | tail -n 1 | grep -q "Dump completed" || die "local backup failed, nothing was changed"

# 2. nothing may write while the dump goes in
STOPPED=0
restart() { ((STOPPED)) && { info "starting ${APP_SERVICES[*]}"; "${COMPOSE[@]}" start "${APP_SERVICES[@]}" >&2 || true; }; }
trap restart EXIT
info "stopping ${APP_SERVICES[*]}"
"${COMPOSE[@]}" stop "${APP_SERVICES[@]}" >&2
STOPPED=1

info "recreating '$DB_NAME' and importing"
sql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cat_dump | sed -E '/enable the sandbox mode/d; s/DEFINER=`[^`]+`@`[^`]+`//g' | sql "$DB_NAME"

# 3. local safety: domains, mail, ERP sync
info "domains: $PROD_URL -> $LOCAL_URL"
sql "$DB_NAME" -t <<SQL
UPDATE sales_channel_domain SET url = REPLACE(url, '$PROD_URL', '$LOCAL_URL') WHERE url LIKE '$PROD_URL%';
UPDATE sales_channel_domain SET url = REPLACE(url, '${PROD_URL/www./}', '$LOCAL_URL') WHERE url LIKE '${PROD_URL/www./}%';
SELECT url FROM sales_channel_domain;
SQL

info "disabling mail delivery and the Optiweb (Minimax) scheduled tasks"
sql "$DB_NAME" -t <<'SQL'
DELETE FROM system_config WHERE configuration_key IN ('core.mailerSettings.disableDelivery', 'core.mailerSettings.emailAgent');
INSERT INTO system_config (id, configuration_key, configuration_value, sales_channel_id, created_at)
VALUES (UNHEX(REPLACE(UUID(), '-', '')), 'core.mailerSettings.disableDelivery', '{"_value": true}', NULL, NOW(3));
UPDATE system_config SET configuration_value = '{"_value": false}' WHERE configuration_key = 'OptiwebSync.config.enableOrderExport';
UPDATE scheduled_task SET status = 'inactive' WHERE scheduled_task_class LIKE 'Optiweb%' OR name LIKE 'optiweb%';
SELECT name, status FROM scheduled_task WHERE name LIKE 'optiweb%' ORDER BY name;
SQL

# 4. app back up, local code vs prod schema
restart; STOPPED=0
for _ in $(seq 1 30); do console about >/dev/null 2>&1 && break; sleep 2; done
info "plugin:refresh + updates for plugins whose local code is newer than prod"
console plugin:refresh >/dev/null
console plugin:update:all >&2 || warn "plugin:update:all failed: check 'bin/console plugin:list'"
console user:change-password "$ADMIN_USER" --password="$ADMIN_PASSWORD" >&2 || warn "admin password not reset"
# the web process runs APP_ENV=dev, the CLI defaults to prod: clear the cache the storefront reads
"${COMPOSE[@]}" exec -T -e APP_ENV=dev "$APP_SERVICE" php bin/console cache:clear >/dev/null || warn "cache:clear failed"

info "done. Local admin: $LOCAL_URL/admin ($ADMIN_USER / $ADMIN_PASSWORD). Previous local DB: $BK"
