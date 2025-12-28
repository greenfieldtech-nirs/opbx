#!/bin/bash
set -e

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
until php artisan db:show 2>/dev/null | grep -q "mysql"; do
    sleep 1
done

echo "MySQL is ready!"

# Run migrations only from the main app container (not from queue-worker or scheduler)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force --no-interaction || echo "Migration failed or already up to date"

    # Run database seeders on fresh installations (creates default admin user)
    echo "Checking if database seeding is needed..."
    php artisan db:seed --force --no-interaction || echo "Seeding skipped or already completed"
else
    echo "Skipping migrations (RUN_MIGRATIONS=false)..."
fi

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
