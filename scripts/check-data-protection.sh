#!/bin/bash
#
# Data Protection Check Script
# 
# This script runs when the app container starts to detect potentially
# empty databases and warn about data loss.
#

set -e

DB_HOST="${DB_HOST:-mysql}"
DB_NAME="${DB_DATABASE:-opbx}"
DB_USER="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-secret}"

# Wait for MySQL to be ready
echo "Checking database state..."
for i in $(seq 1 30); do
    if mysqladmin ping -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASSWORD}" --silent 2>/dev/null; then
        break
    fi
    sleep 1
done

# Check if critical tables have data
EXTENSIONS=$(mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASSWORD}" -D "${DB_NAME}" -N -e "SELECT COUNT(*) FROM extensions" 2>/dev/null || echo "0")
CAMPAIGNS=$(mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASSWORD}" -D "${DB_NAME}" -N -e "SELECT COUNT(*) FROM auto_dialer_campaigns" 2>/dev/null || echo "0")
USERS=$(mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASSWORD}" -D "${DB_NAME}" -N -e "SELECT COUNT(*) FROM users" 2>/dev/null || echo "0")

TOTAL_RECORDS=$((EXTENSIONS + CAMPAIGNS))

if [ "${TOTAL_RECORDS}" -eq 0 ] && [ "${USERS}" -le 1 ]; then
    echo ""
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║                    ⚠️  DATA LOSS WARNING  ⚠️                    ║"
    echo "╠════════════════════════════════════════════════════════════════╣"
    echo "║  The database appears to be empty or minimally populated.      ║"
    echo "║                                                                ║"
    echo "║  If you previously had data (extensions, campaigns, etc.),     ║"
    echo "║  it may have been lost by:                                     ║"
    echo "║    • Running 'docker compose down -v' (removes volumes)        ║"
    echo "║    • Docker Desktop reset or volume deletion                   ║"
    echo "║    • Manual deletion of Docker volumes                         ║"
    echo "║                                                                ║"
    echo "║  To restore from a backup:                                     ║"
    echo "║    ./scripts/restore-database.sh backups/opbx-daily.sql.gz    ║"
    echo "║                                                                ║"
    echo "║  To create a backup:                                           ║"
    echo "║    ./scripts/backup-database.sh                                ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo ""
fi

# Check if volume is actually mounted (not using anonymous volume)
VOLUME_CHECK=$(df /var/lib/mysql | grep -c "mysql_data" || echo "0")
if [ "${VOLUME_CHECK}" -eq 0 ]; then
    echo "WARNING: MySQL data directory may not be using a persistent volume!"
    echo "Check docker-compose.yml to ensure 'mysql_data' volume is mounted."
fi

echo "Database check complete."
