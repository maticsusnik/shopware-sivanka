#!/usr/bin/env bash
# extract : read-only. Creates runs/<timestamp>/data/*.jsonl from the LOCAL database
# plus snapshot.tsv, the fingerprint `verify` compares against after an import.
set -euo pipefail
source "$(dirname "$0")/../config.sh"

RUN_DIR="$RUNS_DIR/${RUN:-$(date +%Y-%m-%d-%H%M)}"
D="$RUN_DIR/data"
mkdir -p "$D"
V="UNHEX('$LIVE_VERSION')"; SL="UNHEX('$LANG_SL')"
q() { sql "$DB_NAME" -N -B --raw -e "$1"; }

info "products -> $D/products.jsonl"
q "SELECT JSON_OBJECT(
  'id', LOWER(HEX(p.id)), 'parent', LOWER(HEX(p.parent_id)), 'number', p.product_number,
  'active', p.active, 'children', p.child_count, 'stock', p.stock,
  'name', pt.name, 'description', pt.description,
  'short_description', JSON_UNQUOTE(JSON_EXTRACT(pt.custom_fields, '$.shortDescription')),
  'meta_title', pt.meta_title, 'meta_description', pt.meta_description, 'keywords', pt.keywords,
  'parent_name', ppt.name,
  'price_gross', JSON_EXTRACT(p.price, '$.*.gross'), 'price_net', JSON_EXTRACT(p.price, '$.*.net'), 'tax_id', LOWER(HEX(p.tax_id)),
  'manufacturer', (SELECT mt.name FROM product_manufacturer_translation mt WHERE mt.product_manufacturer_id = p.product_manufacturer_id AND mt.language_id = $SL LIMIT 1),
  'ean', p.ean, 'weight', p.weight, 'width', p.width, 'height', p.height, 'length', p.length,
  'categories', (SELECT JSON_ARRAYAGG(LOWER(HEX(pc.category_id))) FROM product_category pc WHERE pc.product_id = p.id AND pc.product_version_id = p.version_id),
  'visibilities', (SELECT COUNT(*) FROM product_visibility v WHERE v.product_id = p.id AND v.product_version_id = p.version_id),
  'properties', (SELECT JSON_ARRAYAGG(LOWER(HEX(pp.property_group_option_id))) FROM product_property pp WHERE pp.product_id = p.id AND pp.product_version_id = p.version_id),
  'options', (SELECT JSON_ARRAYAGG(LOWER(HEX(po.property_group_option_id))) FROM product_option po WHERE po.product_id = p.id AND po.product_version_id = p.version_id),
  'cover_hash', (SELECT m.file_hash FROM product_media pm JOIN media m ON m.id = pm.media_id WHERE pm.id = p.product_media_id LIMIT 1),
  'cover_media_id', (SELECT LOWER(HEX(pm.media_id)) FROM product_media pm WHERE pm.id = p.product_media_id LIMIT 1),
  'media', (SELECT JSON_ARRAYAGG(JSON_OBJECT('hash', m.file_hash, 'path', m.path, 'size', m.file_size))
            FROM product_media pm JOIN media m ON m.id = pm.media_id WHERE pm.product_id = p.id AND pm.product_version_id = p.version_id),
  'updated_at', p.updated_at)
FROM product p
LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = $SL
LEFT JOIN product_translation ppt ON ppt.product_id = p.parent_id AND ppt.product_version_id = p.version_id AND ppt.language_id = $SL
WHERE p.version_id = $V ORDER BY p.product_number" > "$D/products.jsonl"

info "categories, property options"
q "SELECT JSON_OBJECT('id', LOWER(HEX(c.id)), 'parent', LOWER(HEX(c.parent_id)), 'path', c.path, 'level', c.level,
  'type', c.type, 'active', c.active, 'name', ct.name, 'description', ct.description,
  'meta_title', ct.meta_title, 'meta_description', ct.meta_description, 'keywords', ct.keywords,
  'products', (SELECT COUNT(*) FROM product_category pc WHERE pc.category_id = c.id))
FROM category c JOIN category_translation ct ON ct.category_id = c.id AND ct.category_version_id = c.version_id AND ct.language_id = $SL
WHERE c.version_id = $V" > "$D/categories.jsonl"
q "SELECT JSON_OBJECT('id', LOWER(HEX(o.id)), 'gid', LOWER(HEX(o.property_group_id)), 'group', gt.name,
  'name', ot.name, 'hex', o.color_hex_code, 'position', ot.position, 'filterable', g.filterable)
FROM property_group_option o
JOIN property_group g ON g.id = o.property_group_id
JOIN property_group_option_translation ot ON ot.property_group_option_id = o.id AND ot.language_id = $SL
JOIN property_group_translation gt ON gt.property_group_id = o.property_group_id AND gt.language_id = $SL" > "$D/options.jsonl"

"$CE_DIR/stages/snapshot.sh" "$RUN_DIR/snapshot.tsv"
python3 -I "$CE_DIR/stages/summary.py" "$RUN_DIR"
