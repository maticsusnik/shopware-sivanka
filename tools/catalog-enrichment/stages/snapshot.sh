#!/usr/bin/env bash
# snapshot <out.tsv> : read-only fingerprint of every product (core fields, text hash, relations)
set -euo pipefail
source "$(dirname "$0")/../config.sh"
OUT="$1"
V="UNHEX('$LIVE_VERSION')"; SL="UNHEX('$LANG_SL')"
q() { sql "$DB_NAME" -N -B --raw -e "$1"; }
info "snapshot -> $OUT"
q "SELECT LOWER(HEX(p.id)), p.product_number, p.active, p.stock, MD5(CAST(p.price AS CHAR)), LOWER(HEX(p.tax_id)),
  LOWER(HEX(p.parent_id)), p.child_count, LOWER(HEX(p.product_media_id)),
  MD5(CONCAT_WS('|', pt.name, pt.description, pt.meta_title, pt.meta_description, pt.keywords, pt.custom_fields)),
  (SELECT GROUP_CONCAT(LOWER(HEX(pp.property_group_option_id)) ORDER BY pp.property_group_option_id) FROM product_property pp WHERE pp.product_id = p.id AND pp.product_version_id = p.version_id),
  (SELECT GROUP_CONCAT(LOWER(HEX(po.property_group_option_id)) ORDER BY po.property_group_option_id) FROM product_option po WHERE po.product_id = p.id AND po.product_version_id = p.version_id),
  (SELECT COUNT(*) FROM product_category pc WHERE pc.product_id = p.id AND pc.product_version_id = p.version_id),
  (SELECT COUNT(*) FROM product_visibility v WHERE v.product_id = p.id AND v.product_version_id = p.version_id),
  (SELECT COUNT(*) FROM product_media pm WHERE pm.product_id = p.id AND pm.product_version_id = p.version_id)
FROM product p LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = $SL
WHERE p.version_id = $V ORDER BY p.product_number" > "$OUT"

