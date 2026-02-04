#!/bin/bash

set -e

# Fix file permissions for Laravel
echo "Fixing file permissions..."
chmod -R 755 /var/www/html 2>/dev/null || echo "Some files could not be modified (expected for .git files)"

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

# Clear stale caches to ensure routes and config are always fresh
echo "Clearing stale caches..."
php artisan route:clear 2>/dev/null || echo "Route cache clear skipped"
php artisan config:clear 2>/dev/null || echo "Config cache clear skipped"
php artisan view:clear 2>/dev/null || echo "View cache clear skipped"

# Run migrations only from the main app container (not from queue-worker or scheduler)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force --no-interaction || echo "Migration failed or already up to date"

    # Run database seeders on fresh installations (creates default admin user)
    echo "Checking if database seeding is needed..."
    php artisan db:seed --force --no-interaction || echo "Seeding skipped or already completed"

    # Initialize storage (MinIO bucket setup)
echo "Initializing storage..."
php artisan storage:initialize

echo "Validating configuration..."
php artisan config:validate --quiet || echo "⚠️  Configuration warnings detected. Run 'php artisan config:validate' for details."

echo "Starting PHP-FPM..."
exec "$@"
