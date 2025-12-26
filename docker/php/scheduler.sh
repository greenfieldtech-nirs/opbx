#!/bin/bash
set -e

echo "Starting Laravel Scheduler..."

# Wait for app to be ready
sleep 10

while true; do
    php /var/www/html/artisan schedule:run --verbose --no-interaction &
    sleep 60
done
