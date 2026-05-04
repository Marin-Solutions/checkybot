#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "${ROOT_DIR}"

git reset --hard
git pull origin master

composer install --no-interaction --prefer-dist --optimize-autoloader

npm ci --legacy-peer-deps
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:clear
php artisan migrate --force
sudo service php8.3-fpm reload
php artisan telescope:prune
php artisan horizon:terminate

echo "🚀 Application deployed!"
