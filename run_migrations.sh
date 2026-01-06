#!/bin/bash
# Docker Migration Helper Script for OPBX
# Run this script from your project root directory

echo "🔧 OPBX Docker Migration Helper"
echo "================================"

# Check if we're in a Docker environment
if [ -f "docker-compose.yml" ]; then
    echo "✅ Found docker-compose.yml"

    # Check if containers are running
    if docker-compose ps | grep -q "Up"; then
        echo "✅ Docker containers are running"

        # Run migrations inside the app container
        echo "🚀 Running Laravel migrations..."
        docker-compose exec app php artisan migrate --force

        if [ $? -eq 0 ]; then
            echo "✅ Migrations completed successfully!"
            echo ""
            echo "📋 Next steps:"
            echo "1. Test the IVR Menus page: http://your-domain/ivr-menus"
            echo "2. Create your first IVR menu"
            echo "3. Configure DID routing to use IVR menus"
        else
            echo "❌ Migration failed!"
            echo ""
            echo "🔍 Troubleshooting:"
            echo "1. Check database connectivity: docker-compose exec app php artisan tinker --execute=\"DB::connection()->getPdo()\""
            echo "2. Check migration status: docker-compose exec app php artisan migrate:status"
            echo "3. View Laravel logs: docker-compose exec app tail -f storage/logs/laravel.log"
        fi
    else
        echo "❌ Docker containers are not running"
        echo "   Start them with: docker-compose up -d"
        exit 1
    fi
else
    echo "❌ No docker-compose.yml found in current directory"
    echo "   Make sure you're in the project root directory"
    exit 1
fi