# Catalog enrichment pipeline: project settings (sourced by every stage).
# Nothing secret lives here. DB credentials stay inside the database container.

CE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$CE_DIR/../.." && pwd)"

# Matic's local-setup wrapper layers the Caddy override (no host ports 80/8025); plain compose clashes with it.
if command -v ow >/dev/null 2>&1; then
    COMPOSE=(ow sivanka)
else
    COMPOSE=(docker compose --project-directory "$PROJECT_DIR")
fi
DB_SERVICE=database
DB_NAME=shopware
# services that write to the DB and must be stopped during a restore
APP_SERVICES=(shopware async_worker low_priority_worker scheduler)
APP_SERVICE=shopware

PROD_URL=https://www.sivanka.com
LOCAL_URL=https://sivanka.shopware.localhost
ADMIN_USER=admin
ADMIN_PASSWORD=shopware

# Shopware ids that are fixed for this shop
LIVE_VERSION=0fa91ce3e96a4bc2be4bd9ce752c3425       # product live version
LANG_SL=2fbb5fe2e29a4d70aa5854ce7ce3e20b            # Slovenščina = system language = translations.DEFAULT

RUNS_DIR="$CE_DIR/runs"
STATE_DIR="$CE_DIR/state"

info() { printf '\033[1;34m==>\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[1;33mwarn:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

# sql [mariadb args...] : run against the LOCAL database container only
sql() {
    "${COMPOSE[@]}" exec -T "$DB_SERVICE" sh -c \
        'exec mariadb -u root -p"$MARIADB_ROOT_PASSWORD" --default-character-set=utf8mb4 "$@"' -- "$@"
}

console() {
    "${COMPOSE[@]}" exec -T "$APP_SERVICE" php bin/console "$@"
}

# the run directory every stage after `restore` reads and writes (latest unless RUN is set)
current_run() {
    if [[ -n "${RUN:-}" ]]; then echo "$RUNS_DIR/$RUN"; return; fi
    local last
    last="$(ls -1d "$RUNS_DIR"/20* 2>/dev/null | sort | tail -n1)"
    [[ -n "$last" ]] || die "no run yet: ./enrich.sh extract creates one"
    echo "$last"
}
