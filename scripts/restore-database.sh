#!/bin/bash
#
# Database Restore Script for OPBX
#
# Usage:
#   ./scripts/restore-database.sh backups/opbx-backup-20260115_120000.sql.gz
#   ./scripts/restore-database.sh backups/opbx-daily.sql.gz
#
# WARNING: This will OVERWRITE all existing data in the database!
#

set -e

# Load environment variables from .env if it exists
if [ -f .env ]; then
    set -a
    source .env
    set +a
fi

if [ -z "$1" ]; then
    echo "ERROR: No backup file specified!"
    echo "Usage: $0 <backup-file.sql.gz>"
    echo ""
    echo "Available backups:"
    ls -1h ./backups/*.sql.gz 2>/dev/null || echo "  No backups found in ./backups/"
    exit 1
fi

BACKUP_FILE="$1"
DB_NAME="${DB_DATABASE:-opbx}"
DB_USER="root"
DB_PASSWORD="${DB_ROOT_PASSWORD:-${DB_PASSWORD:-secret}}"
CONTAINER_NAME="opbx_mysql"

# Check if backup file exists
if [ ! -f "${BACKUP_FILE}" ]; then
    echo "ERROR: Backup file not found: ${BACKUP_FILE}"
    exit 1
fi

# Check if MySQL container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "ERROR: MySQL container '${CONTAINER_NAME}' is not running!"
    echo "Start the container first: docker compose up -d mysql"
    exit 1
fi

# Get current record counts for comparison
echo "Current database state:"
docker compose exec -T mysql mysql \
    -u "${DB_USER}" \
    -p"${DB_PASSWORD}" \
    -e "USE ${DB_NAME}; SELECT COUNT(*) as extensions FROM extensions; SELECT COUNT(*) as campaigns FROM auto_dialer_campaigns; SELECT COUNT(*) as dids FROM did_numbers; SELECT COUNT(*) as users FROM users;" 2>/dev/null || echo "  (database appears empty or inaccessible)"

echo ""
echo "WARNING: This will OVERWRITE all data in database '${DB_NAME}'!"
echo "Backup file: ${BACKUP_FILE}"
echo ""
read -p "Are you sure you want to continue? (yes/no): " CONFIRM

if [ "${CONFIRM}" != "yes" ]; then
    echo "Restore cancelled."
    exit 0
fi

echo ""
echo "Restoring database from ${BACKUP_FILE}..."

# Restore based on file extension
if [[ "${BACKUP_FILE}" == *.gz ]]; then
    gunzip < "${BACKUP_FILE}" | docker compose exec -T mysql mysql \
        -u "${DB_USER}" \
        -p"${DB_PASSWORD}"
else
    docker compose exec -T mysql mysql \
        -u "${DB_USER}" \
        -p"${DB_PASSWORD}" \
        < "${BACKUP_FILE}"
fi

echo ""
echo "SUCCESS: Database restored!"
echo ""
echo "Verifying restored data:"
docker compose exec -T mysql mysql \
    -u "${DB_USER}" \
    -p"${DB_PASSWORD}" \
    -e "USE ${DB_NAME}; SELECT COUNT(*) as extensions FROM extensions; SELECT COUNT(*) as campaigns FROM auto_dialer_campaigns; SELECT COUNT(*) as dids FROM did_numbers; SELECT COUNT(*) as users FROM users;" 2>/dev/null || echo "  (verification failed)"
