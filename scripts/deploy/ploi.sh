#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "${ROOT_DIR}"

cleanup_stale_node_modules() {
    find "${ROOT_DIR}" -maxdepth 1 -type d -name 'node_modules.*.stale' -exec rm -rf {} + 2>/dev/null || true
}

cleanup_stale_node_modules
trap cleanup_stale_node_modules EXIT

git reset --hard
git pull origin master

composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader

# npm ci has been unreliable on the Ploi host because Rollup's optional
# native package can be skipped; reinstalling dependencies keeps the
# committed lockfile while avoiding the broken node_modules state. Rename
# node_modules before deleting it so transient filesystem cleanup issues do not
# block the fresh install.
if [ -d node_modules ]; then
    STALE_NODE_MODULES="node_modules.$(date +%s).stale"
    mv node_modules "${STALE_NODE_MODULES}"
    rm -rf "${STALE_NODE_MODULES}" &
fi
npm install --include=dev --legacy-peer-deps
npm run build

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan migrate --force
sudo service php8.3-fpm reload
php artisan queue:restart
php artisan horizon:terminate

echo "🚀 Application deployed!"
