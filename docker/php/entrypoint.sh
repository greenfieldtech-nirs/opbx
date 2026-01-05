#!/bin/bash

set -e

# Run environment validation before starting application
echo "Validating environment variables..."
if [ -f /docker/scripts/validate-env.sh ]; then
    /docker/scripts/validate-env.sh
    VALIDATION_EXIT_CODE=$?
    if [ $VALIDATION_EXIT_CODE -ne 0 ]; then
        echo "ERROR: Environment validation failed"
        exit 1
    fi
else
    echo "WARNING: Environment validation script not found, skipping..."
fi

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
