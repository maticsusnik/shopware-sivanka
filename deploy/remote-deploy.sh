#!/usr/bin/env bash
# Runs ON the production host (NEOSERV cPanel, no Docker, no Python), called by
# .github/workflows/deploy-prod.yml over SSH. Never run by hand unless you know
# which release you are activating.
#
#   remote-deploy.sh deploy   <release-id>   unpack, migrate, build, switch, clean up
#   remote-deploy.sh rollback                point `current` back at the previous release
#
# Layout (APP_BASE, default ~/sivanka):
#   releases/<id>/   one immutable copy of the shopware/ directory per deploy
#   shared/          survives deploys: .env.local, media, files, logs, theme
#   uploads/<id>.tgz the artifact CI uploaded, removed after unpacking
#   current -> releases/<id>
#   history          ids that went live, oldest first - rollback walks it backwards
# ~/public_html is a symlink to current/public (one-time manual setup, see deploy/README.md).
set -euo pipefail

APP_BASE="${APP_BASE:-$HOME/sivanka}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP="${PHP_BIN:-/usr/local/bin/php}"

RELEASES="$APP_BASE/releases"
SHARED="$APP_BASE/shared"
UPLOADS="$APP_BASE/uploads"
CURRENT="$APP_BASE/current"
HISTORY="$APP_BASE/history"

# Directories that must outlive a release. public/theme is shared so the old
# release keeps serving its CSS/JS while the new one compiles a new theme seed;
# Shopware deletes stale theme folders itself, delayed, through the queue.
# public/.well-known holds cPanel AutoSSL validation files, which must survive deploys.
SHARED_DIRS=(
    files
    public/.well-known
    public/media
    public/thumbnail
    public/sitemap
    public/theme
    var/log
    var/optiweb-logs
    var/optiweb-sync-locks
    config/jwt
)
SHARED_FILES=(
    .env.local
)

UNFINISHED_RELEASE=""

log() { echo "[deploy] $*"; }
die() { echo "[deploy][ERROR] $*" >&2; exit 1; }

console() { "$PHP" bin/console --no-interaction "$@"; }

switch_current() {
    local target="$1"
    # Atomic: create the new link beside the old one, then rename over it.
    ln -sfn "$target" "$APP_BASE/current.tmp"
    mv -Tf "$APP_BASE/current.tmp" "$CURRENT"
    # LiteSpeed keeps detached lsphp processes (and their realpath/opcache view of
    # the old release) alive; this file asks it to restart them for this account.
    touch "$HOME/.lsphp_restart.txt"
}

link_shared() {
    local release="$1" path
    for path in "${SHARED_DIRS[@]}"; do
        mkdir -p "$SHARED/$path"
        rm -rf "${release:?}/$path"
        mkdir -p "$(dirname "$release/$path")"
        ln -s "$SHARED/$path" "$release/$path"
    done
    for path in "${SHARED_FILES[@]}"; do
        [ -f "$SHARED/$path" ] || die "$SHARED/$path is missing - create it first (deploy/README.md)."
        rm -f "$release/$path"
        ln -s "$SHARED/$path" "$release/$path"
    done
}

remove_unfinished_release() {
    [ -n "$UNFINISHED_RELEASE" ] || return 0
    log "Deploy failed - removing unfinished release $(basename "$UNFINISHED_RELEASE")"
    cd "$APP_BASE"
    rm -rf "${UNFINISHED_RELEASE:?}"
}

shopware_version() {
    "$PHP" -r 'require "vendor/autoload.php"; echo \Composer\InstalledVersions::getVersion("shopware/core");'
}

cmd_deploy() {
    local id="${1:-}"
    [[ "$id" =~ ^[A-Za-z0-9._-]+$ ]] || die "Invalid release id '$id'."

    local archive="$UPLOADS/$id.tgz"
    local release="$RELEASES/$id"
    [ -f "$archive" ] || die "Artifact $archive not found."

    mkdir -p "$RELEASES" "$SHARED"
    # Home is 711 and public_html is reached through a symlink, so LiteSpeed
    # needs execute permission on every directory on the way to current/public.
    chmod 711 "$APP_BASE" "$RELEASES"
    [ -e "$release" ] && die "Release $release already exists - redeploy needs a new id."

    # A release that fails before going live is removed, so a re-run of the same
    # commit starts clean and rollback can never land on a half-built tree.
    UNFINISHED_RELEASE="$release"
    trap remove_unfinished_release EXIT

    log "Unpacking $id"
    mkdir -p "$release"
    tar -xzf "$archive" -C "$release"
    rm -f "$archive"

    link_shared "$release"
    cd "$release"

    local previous_version new_version
    previous_version="$(cat "$SHARED/.shopware-version" 2>/dev/null || true)"
    new_version="$(shopware_version)"
    log "Shopware $new_version (previously deployed: ${previous_version:-none})"

    # Every step below runs against the live database while the old release is
    # still serving. Keep migrations backward compatible (blue/green style).
    console plugin:refresh
    console database:migrate --all core
    console database:migrate-destructive --all core --version-selection-mode=safe
    if [ "$previous_version" != "$new_version" ]; then
        log "Core version changed - running system:update:finish"
        console system:update:finish --skip-migrations --skip-asset-build
    fi
    # Runs update() and the migrations of every plugin whose composer version is
    # higher than the installed one - bump the plugin version to ship a migration.
    console plugin:update:all
    console assets:install
    console theme:compile --active-only
    console translation:install --locales=sl-SI || log "translation:install failed - keeping the installed translations"
    console cache:clear
    console cache:warmup

    log "Switching current -> $id"
    switch_current "$release"
    UNFINISHED_RELEASE=""
    echo "$id" >> "$HISTORY"
    echo "$new_version" > "$SHARED/.shopware-version"

    # Pages cached while the old release was still answering are stale now.
    console cache:clear:http

    cleanup_releases
    log "Done: $id is live."
}

cmd_rollback() {
    [ -L "$CURRENT" ] || die "$CURRENT is not a symlink - nothing to roll back."
    local live previous
    live="$(basename "$(readlink "$CURRENT")")"
    # Newest release that went live before the current one and still exists.
    previous=""
    local id
    while read -r id; do
        [ "$id" = "$live" ] && continue
        [ -d "$RELEASES/$id" ] && { previous="$id"; break; }
    done < <(tac "$HISTORY" 2>/dev/null | awk '!seen[$0]++')
    [ -n "$previous" ] || die "No previous release to roll back to."

    log "Rolling back $live -> $previous (database is NOT rolled back)"
    switch_current "$RELEASES/$previous"
    # Drop the bad release from history so a second rollback goes further back.
    grep -vx "$live" "$HISTORY" > "$HISTORY.tmp" || true
    mv "$HISTORY.tmp" "$HISTORY"
    echo "$previous" >> "$HISTORY"
    cd "$RELEASES/$previous"
    console cache:clear
    console cache:clear:http
    log "Done: $previous is live."
}

cleanup_releases() {
    # Keep the KEEP_RELEASES most recent live releases, remove everything else.
    local keep old
    keep="$(tac "$HISTORY" | awk '!seen[$0]++' | head -n "$KEEP_RELEASES")"
    for old in $(ls -1 "$RELEASES"); do
        grep -qx "$old" <<< "$keep" && continue
        log "Removing old release $old"
        rm -rf "${RELEASES:?}/$old"
    done
}

case "${1:-}" in
    deploy)   shift; cmd_deploy "$@" ;;
    rollback) cmd_rollback ;;
    *)        die "Usage: $0 deploy <release-id> | rollback" ;;
esac
