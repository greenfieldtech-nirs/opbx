#!/bin/bash
set -e

echo "Starting Laravel Scheduler..."

# Wait for app to be ready
sleep 10

while true; do
    php /var/www/html/artisan schedule:run --verbose --no-interaction &
    # Heartbeat for the compose healthcheck: the loop is alive.
    touch /tmp/scheduler.heartbeat
    sleep 60
done
