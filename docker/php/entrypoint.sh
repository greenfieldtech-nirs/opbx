#!/bin/bash

set -e

# Validate passwords are not using default/weak values
validate_password() {
    local var_name=$1
    local var_value=$2
    local weak_values="secret password rootsecret minioadmin admin 123456"

    for weak in $weak_values; do
        if [ "$var_value" = "$weak" ]; then
            echo "ERROR: $var_name is set to a default/weak value ('$weak'). Please set a strong password in .env file."
            echo "Generate a strong password with: openssl rand -base64 32"
            exit 1
        fi
    done
}

echo "Validating security settings..."
validate_password "DB_PASSWORD" "$DB_PASSWORD"
validate_password "DB_ROOT_PASSWORD" "$MYSQL_ROOT_PASSWORD"
validate_password "MINIO_ACCESS_KEY" "$MINIO_ACCESS_KEY"
validate_password "MINIO_SECRET_KEY" "$MINIO_SECRET_KEY"
echo "Security validation passed."

# The user the application should run as after privilege drop.
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"

# Ensure Laravel's runtime-writable directories exist and are owned by the app
# user. These live under the bind-mounted repo, so ownership on the host (often
# a different uid) would otherwise leave them unwritable by www-data. This must
# run as root; the entrypoint drops to $APP_USER before exec-ing the command.
echo "Preparing runtime directories..."
RUNTIME_DIRS="
storage/logs
storage/framework
storage/framework/cache
storage/framework/cache/data
storage/framework/sessions
storage/framework/views
storage/app
storage/app/public
bootstrap/cache
"
for dir in $RUNTIME_DIRS; do
    mkdir -p "/var/www/html/$dir"
done

if [ "$(id -u)" = "0" ]; then
    echo "Fixing ownership and permissions on runtime directories..."
    for dir in $RUNTIME_DIRS; do
        chown -R "$APP_USER:$APP_GROUP" "/var/www/html/$dir" || true
        chmod -R ug+rwX "/var/www/html/$dir" || true
    done
else
    echo "Not running as root; skipping ownership fix (relying on existing permissions)."
fi

# Run artisan (and other app commands) as the application user so any files they
# create (cached config/routes/views, logs) are owned by $APP_USER rather than
# root. Falls back to direct execution when not running as root.
run_as_app() {
    if [ "$(id -u)" = "0" ]; then
        su-exec "$APP_USER" "$@"
    else
        "$@"
    fi
}

# Run environment validation before starting application
echo "Validating environment variables..."
if [ -f /var/www/html/docker/scripts/validate-env.sh ]; then
    run_as_app bash /var/www/html/docker/scripts/validate-env.sh
    VALIDATION_EXIT_CODE=$?
    if [ $VALIDATION_EXIT_CODE -ne 0 ]; then
        echo "ERROR: Environment validation failed"
        exit 1
    fi
else
    echo "WARNING: Environment validation script not found, skipping..."
fi

# Fail fast if Composer dependencies are missing (e.g. host bind-mount without `composer install`)
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "ERROR: /var/www/html/vendor/autoload.php not found."
    echo "Composer dependencies are not installed. Run 'composer install' on the host"
    echo "(the repo is bind-mounted over the image's vendor/ directory)."
    exit 1
fi

# Wait for MySQL to be ready (bounded, surfaces the underlying error on failure)
echo "Waiting for MySQL to be ready..."
MYSQL_WAIT_TIMEOUT="${MYSQL_WAIT_TIMEOUT:-60}"
mysql_elapsed=0
until run_as_app php artisan db:show 2>/dev/null | grep -q "mysql"; do
    if [ "$mysql_elapsed" -ge "$MYSQL_WAIT_TIMEOUT" ]; then
        echo "ERROR: MySQL not ready after ${MYSQL_WAIT_TIMEOUT}s. Last error:"
        run_as_app php artisan db:show 2>&1 | head -20 || true
        exit 1
    fi
    sleep 1
    mysql_elapsed=$((mysql_elapsed + 1))
done

echo "MySQL is ready!"

# Clear stale caches to ensure routes and config are always fresh
echo "Clearing stale caches..."
run_as_app php artisan route:clear 2>/dev/null || echo "Route cache clear skipped"
run_as_app php artisan config:clear 2>/dev/null || echo "Config cache clear skipped"
run_as_app php artisan view:clear 2>/dev/null || echo "View cache clear skipped"

# Run migrations only from the main app container (not from queue-worker or scheduler)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Running database migrations..."
    run_as_app php artisan migrate --force --no-interaction || echo "Migration failed or already up to date"

    # Run database seeders on fresh installations (creates default admin user)
    echo "Checking if database seeding is needed..."
    run_as_app php artisan db:seed --force --no-interaction || echo "Seeding skipped or already completed"

    # Initialize storage (verifies MinIO bucket access).
    # The bucket itself is provisioned by the minio-init service; this must never
    # abort startup, so failures are logged and swallowed (matches migrate/seed).
    echo "Initializing storage..."
    run_as_app php artisan storage:initialize || echo "⚠️  Storage initialization failed (recordings uploads may not work until MinIO is reachable)."

    echo "Validating configuration..."
    run_as_app php artisan config:validate --silent || echo "⚠️  Configuration warnings detected. Run 'php artisan config:validate' for details."
fi

echo "Starting: $*"

# Launch the workload.
#
# php-fpm is special: its master process is designed to run as root and drop
# its WORKER processes to the user/group configured in www.conf (www-data).
# Running the master as root also lets it write to the container's stderr log
# (/proc/self/fd/2). So we must NOT su-exec php-fpm — doing so breaks logging
# and worker privilege separation.
#
# All other commands (queue:work, scheduler, one-off artisan) have no built-in
# privilege separation, so we drop to $APP_USER for them.
if [ "$(id -u)" = "0" ]; then
    case "$1" in
        php-fpm|*/php-fpm)
            exec "$@"
            ;;
        *)
            exec su-exec "$APP_USER" "$@"
            ;;
    esac
else
    exec "$@"
fi
