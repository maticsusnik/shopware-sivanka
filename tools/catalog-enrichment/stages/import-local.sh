#!/usr/bin/env bash
# import-local : LOCAL only. Import <run>/import/*.csv in their numeric order with standard Import/Export
# (bin/console import:entity, never --rollbackOnError: it never commits), then apply fixes-manual.json.
# On prod the same files go in through the Admin (import/README.md).
set -euo pipefail
source "$(dirname "$0")/../config.sh"
R="$(current_run)"; I="$R/import"
[[ -d "$I" ]] || die "no $I: run ./enrich.sh build first"

profile_for() {
    case "$1" in
        01-*|04-*) echo default_properties ;;
        03-*)      echo sivanka_product_seo_update ;;
        06-*)      echo default_variant_configuration_settings ;;
        08-*)      echo sivanka_variant_listing ;;
        *)         echo default_product ;;
    esac
}

# the variant listing profile exists only where someone created it (Admin on prod, here by SQL)
if [[ -z "$(sql "$DB_NAME" -N -e "SELECT 1 FROM import_export_profile WHERE technical_name = 'sivanka_variant_listing'")" ]]; then
    info "creating profile sivanka_variant_listing"
    sql "$DB_NAME" <<'SQL'
INSERT INTO import_export_profile (id, system_default, source_entity, file_type, delimiter, enclosure, type, mapping, config, technical_name, created_at)
VALUES (UNHEX('0b7e1f4a9c2d4e6f8a1b3c5d7e9f0a21'), 0, 'product', 'text/csv', ';', '"', 'import',
 '[{"id":"0b7e1f4a9c2d4e6f8a1b3c5d7e9f0a22","key":"id","mappedKey":"id","position":0,"useDefaultValue":false,"defaultValue":null,"requiredByUser":false},{"id":"0b7e1f4a9c2d4e6f8a1b3c5d7e9f0a23","key":"productNumber","mappedKey":"product_number","position":1,"useDefaultValue":false,"defaultValue":null,"requiredByUser":false},{"id":"0b7e1f4a9c2d4e6f8a1b3c5d7e9f0a24","key":"variantListingConfig","mappedKey":"variant_listing_config","position":2,"useDefaultValue":false,"defaultValue":null,"requiredByUser":false}]',
 '{"createEntities":false,"updateEntities":true}', 'sivanka_variant_listing', NOW(3));
INSERT INTO import_export_profile_translation (import_export_profile_id, language_id, label, created_at)
SELECT UNHEX('0b7e1f4a9c2d4e6f8a1b3c5d7e9f0a21'), id, 'Sivanka – variant listing', NOW(3) FROM language;
SQL
fi

# the workers re-index while we write and deadlock some rows: pause them for the import
"${COMPOSE[@]}" stop async_worker low_priority_worker scheduler >/dev/null 2>&1 || true
trap '"${COMPOSE[@]}" start async_worker low_priority_worker scheduler >/dev/null 2>&1 || true' EXIT
"${COMPOSE[@]}" exec -T "$APP_SERVICE" mkdir -p /tmp/ce-import
for f in "$I"/[0-9][0-9]-*.csv; do
    b="$(basename "$f")"; p="$(profile_for "$b")"
    "${COMPOSE[@]}" cp "$f" "$APP_SERVICE:/tmp/ce-import/$b" >/dev/null 2>&1
    info "$b ($p)"
    out="$("${COMPOSE[@]}" exec -T "$APP_SERVICE" php bin/console import:entity "/tmp/ce-import/$b" "+1 day" \
        --profile-technical-name="$p" --printErrors 2>&1 || true)"
    echo "$out" | grep -v '▓\|░\|^\s*$\|^ ---\|Starting\|^====' | tail -n 12 >&2
    echo "$out" | grep -c "Successfully imported" >/dev/null || die "$b failed, stopping (later files depend on it)"
    if echo "$out" | grep -c "Not all records could be imported" >/dev/null; then
        die "$b: some records failed (see above; a deadlock with the workers is transient: rerun import-local)"
    fi
done

if [[ -f "$I/delete-emptied-parents.json" ]]; then
    info "delete-emptied-parents.json (php/delete-emptied-parents.php)"
    "${COMPOSE[@]}" cp "$CE_DIR/php/delete-emptied-parents.php" "$APP_SERVICE:/tmp/delete-emptied-parents.php" >/dev/null 2>&1
    "${COMPOSE[@]}" cp "$I/delete-emptied-parents.json" "$APP_SERVICE:/tmp/delete-emptied-parents.json" >/dev/null 2>&1
    "${COMPOSE[@]}" exec -T "$APP_SERVICE" php /tmp/delete-emptied-parents.php /tmp/delete-emptied-parents.json --apply | tail -n 3 >&2
fi
if [[ -f "$I/fixes-manual.json" ]]; then
    info "fixes-manual.json (php/apply-fixes.php)"
    "${COMPOSE[@]}" cp "$CE_DIR/php/apply-fixes.php" "$APP_SERVICE:/tmp/apply-fixes.php" >/dev/null 2>&1
    "${COMPOSE[@]}" cp "$I/fixes-manual.json" "$APP_SERVICE:/tmp/fixes-manual.json" >/dev/null 2>&1
    "${COMPOSE[@]}" exec -T "$APP_SERVICE" php /tmp/apply-fixes.php /tmp/fixes-manual.json --apply | tail -n 2 >&2
fi
"${COMPOSE[@]}" exec -T -e APP_ENV=dev "$APP_SERVICE" php bin/console cache:clear >/dev/null 2>&1 || warn "cache:clear failed"
info "done. Next: ./enrich.sh verify"
