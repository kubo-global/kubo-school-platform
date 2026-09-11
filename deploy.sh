#!/usr/bin/env bash
#
# Deploy KUBO on a server (nginx + php-fpm).
#
# Pulls the latest committed code and its prebuilt front-end assets, installs
# PHP dependencies, runs migrations, warms the caches, and reloads php-fpm.
# Front-end assets are built in CI and committed (see
# .github/workflows/build-assets.yml), so this never needs Node on the server.
#
# Run as the app user (e.g. `kubo`) from anywhere:
#
#     ./deploy.sh              # deploy origin/main
#     ./deploy.sh <branch>     # deploy a specific branch
#
# The script is safe to re-run and always brings the site back up, even on error.
#
# For the school Pis only, which are plain checkouts. The public demo on Forge
# deploys itself on every push to main (zero-downtime releases under
# releases/<id>, `current` a symlink, one shared storage/); this script assumes a
# single checkout and must not run there. On 2026-09-11 it did: `artisan down`
# wrote the flag into the shared storage, the reset --hard hit one release while
# nginx served another, and no later `artisan up` could find the flag, so the
# demo stayed on "Down for maintenance" after the deploy had long finished.

set -euo pipefail

BRANCH="${1:-main}"
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[ -f artisan ] || { echo "error: run this from the KUBO app directory (no artisan found)."; exit 1; }

# A Forge site: releases/<id> with `current` as a symlink beside a shared storage/.
# The physical path, because `current` is that symlink and `$PWD` would hide it.
if [[ "$(pwd -P)" == */releases/* ]] || [ -n "${FORGE_SITE_PATH:-}" ]; then
    echo "error: this looks like a Forge site ($(pwd -P))."
    echo "       Forge deploys it on every push to main; do not run deploy.sh here."
    echo "       To redeploy now, use the Deploy button in Forge or push to main."
    exit 1
fi

log() { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }

log "Deploying KUBO  (branch: $BRANCH, dir: $PWD)"

# Maintenance mode for the switch; always lift it again, even if a step fails.
php artisan down --retry=15 >/dev/null 2>&1 || true
trap 'php artisan up >/dev/null 2>&1 || true' EXIT

log "Fetching and resetting to origin/$BRANCH  (discards local changes)"
git fetch --prune origin
git reset --hard "origin/$BRANCH"
git clean -fd public/build   # remove any stale, untracked built assets

log "Installing PHP dependencies (production)"
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

log "Running database migrations"
php artisan migrate --force

log "Rebuilding caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link >/dev/null 2>&1 || true

log "Reloading services (php-fpm clears opcache)"
for svc in $(systemctl list-units --type=service --state=active --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
    sudo systemctl reload "$svc" && echo "  reloaded $svc" || true
done
sudo systemctl reload nginx 2>/dev/null && echo "  reloaded nginx" || true

php artisan up >/dev/null 2>&1 || true
trap - EXIT

log "Done: $(git log --oneline -1)"
