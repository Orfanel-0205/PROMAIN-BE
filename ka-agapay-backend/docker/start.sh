#!/usr/bin/env bash
set -e

echo "Preparing Laravel directories..."
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "Clearing Laravel cache..."
php artisan optimize:clear || true

echo "Creating storage link..."
php artisan storage:link || true

echo "Skipping database migrations on Render startup..."
# php artisan migrate --force

echo "Caching Laravel config..."
php artisan config:cache

echo "Starting Apache..."
apache2-foreground