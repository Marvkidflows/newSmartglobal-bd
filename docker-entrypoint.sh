#!/bin/sh
set -e

echo "Clearing config and cache..."
php artisan config:clear
php artisan cache:clear

echo "Running migrations..."
# Ignore migration errors if table already exists
php artisan migrate --force || echo "Some tables already exist, skipping..."

echo "Starting PHP server..."
exec php -S 0.0.0.0:${PORT:-8000} -t public