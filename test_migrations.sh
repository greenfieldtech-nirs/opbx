# Test IVR Migrations in Docker

echo "🧪 Testing IVR Migrations..."

# Option 1: Fresh migration (if no data to preserve)
echo "📋 Option 1: Fresh Migration"
echo "docker-compose exec app php artisan migrate:fresh --seed"

# Option 2: Rollback and rerun (if data exists)
echo "📋 Option 2: Rollback and Rerun"
echo "docker-compose exec app php artisan migrate:rollback --step=2"
echo "docker-compose exec app php artisan migrate"

# Option 3: Check current status
echo "📋 Option 3: Check Status"
echo "docker-compose exec app php artisan migrate:status | grep ivr"

# Option 4: Manual verification
echo "📋 Option 4: Manual Verification"
echo "docker-compose exec db mysql -u opbx_user -p opbx_password -e 'SHOW TABLES LIKE \"ivr_%\";' opbx_database"
echo "docker-compose exec db mysql -u opbx_user -p opbx_password -e 'DESCRIBE ivr_menus;' opbx_database"
echo "docker-compose exec db mysql -u opbx_user -p opbx_password -e 'DESCRIBE ivr_menu_options;' opbx_database"

echo "✅ Migration fix applied - duplicate up() method removed"