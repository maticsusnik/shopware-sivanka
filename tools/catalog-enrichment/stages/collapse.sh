#!/usr/bin/env bash
# collapse [--apply] : LOCAL only. Turn the old hand-made variant families of <run>/collapse-plan.json
# back into the plain Minimax product (php/collapse-variants.php). Dry run without --apply.
set -euo pipefail
source "$(dirname "$0")/../config.sh"
R="$(current_run)"
[[ -f "$R/collapse-plan.json" ]] || die "no $R/collapse-plan.json"
"${COMPOSE[@]}" cp "$CE_DIR/php/collapse-variants.php" "$APP_SERVICE:/tmp/collapse-variants.php" >/dev/null 2>&1
"${COMPOSE[@]}" cp "$R/collapse-plan.json" "$APP_SERVICE:/tmp/collapse-plan.json" >/dev/null 2>&1
"${COMPOSE[@]}" exec -T "$APP_SERVICE" php /tmp/collapse-variants.php /tmp/collapse-plan.json "$@"
