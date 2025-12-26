#!/bin/bash
set -e

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
until php artisan db:show 2>/dev/null | grep -q "mysql"; do
    sleep 1
done

echo "MySQL is ready!"

# Run migrations (safe approach - won't fail if already run)
echo "Running database migrations..."
php artisan migrate --force --no-interaction || echo "Migration failed or already up to date"

# Cache configuration for production
if [ "$APP_ENV" = "production" ]; then
    echo "Caching configuration..."
    php artisan config:cache
    # Note: route:cache disabled due to Laravel 12 CompiledRouteCollection compatibility issue
    # php artisan route:cache
    php artisan view:cache
fi

echo "Starting PHP-FPM..."
exec "$@"
