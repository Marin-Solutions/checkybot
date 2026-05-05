#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "${ROOT_DIR}"

git reset --hard
git pull origin master

composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader

# npm ci has been unreliable on the Ploi host because Rollup's optional
# native package can be skipped; reinstalling dependencies keeps the
# committed lockfile while avoiding the broken node_modules state.
rm -rf node_modules
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
