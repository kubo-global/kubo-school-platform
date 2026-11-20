#!/bin/bash
# Serve a fresh demo school for the browser tests: its own SQLite file, seeded
# from scratch, demo mode on, no Kolibri. Started by playwright.demo.config.ts.
set -e
cd "$(dirname "$0")/../.."

export DEMO_MODE=true
export KOLIBRI_URL=
export DB_CONNECTION=sqlite
export DB_DATABASE="$PWD/storage/framework/testing/demo-browser.sqlite"

mkdir -p "$(dirname "$DB_DATABASE")"
rm -f "$DB_DATABASE"
touch "$DB_DATABASE"

php artisan migrate:fresh --force --seed
exec php artisan serve --host=127.0.0.1 --port="${DEMO_BROWSER_PORT:-8791}"
