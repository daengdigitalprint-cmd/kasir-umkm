#!/usr/bin/env bash
# Jalankan di VPS setelah upload/git pull (dari root proyek Laravel).
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> composer install (prod)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> migrate"
php artisan migrate --force

echo "==> clear caches + rebuild autoload (perbaiki 'Target class does not exist')"
php artisan optimize:clear
composer dump-autoload -o --no-interaction

echo "==> optional: cache config & route (abaikan jika masih error, ulang tanpa baris ini)"
# php artisan config:cache
# php artisan route:cache

echo "Selesai. Jika masih error, restart php-fpm: sudo systemctl restart php8.4-fpm"
