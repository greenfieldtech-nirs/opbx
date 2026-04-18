#!/bin/bash
#
# Database Backup Script for OPBX
#
# Usage:
#   ./scripts/backup-database.sh              # Creates timestamped backup
#   ./scripts/backup-database.sh daily        # Creates daily backup (overwrites)
#   ./scripts/backup-database.sh weekly       # Creates weekly backup (overwrites)
#
# Backups are stored in ./backups/ directory
# IMPORTANT: Backups are NOT stored in Docker volumes - they persist on host filesystem
#

set -e

# Load environment variables from .env if it exists
if [ -f .env ]; then
    set -a
    source .env
    set +a
fi

BACKUP_DIR="./backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
DB_NAME="${DB_DATABASE:-opbx}"
DB_USER="root"
DB_PASSWORD="${DB_ROOT_PASSWORD:-${DB_PASSWORD:-secret}}"
CONTAINER_NAME="opbx_mysql"

# Determine backup filename
if [ "$1" == "daily" ]; then
    BACKUP_FILE="${BACKUP_DIR}/opbx-daily.sql.gz"
    echo "Creating daily backup..."
elif [ "$1" == "weekly" ]; then
    BACKUP_FILE="${BACKUP_DIR}/opbx-weekly.sql.gz"
    echo "Creating weekly backup..."
else
    BACKUP_FILE="${BACKUP_DIR}/opbx-backup-${TIMESTAMP}.sql.gz"
    echo "Creating timestamped backup: opbx-backup-${TIMESTAMP}.sql.gz"
fi

# Create backup directory
mkdir -p "${BACKUP_DIR}"

# Check if MySQL container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "ERROR: MySQL container '${CONTAINER_NAME}' is not running!"
    echo "Start the container first: docker compose up -d mysql"
    exit 1
fi

# Create backup
echo "Dumping database '${DB_NAME}'..."
docker compose exec -T mysql mysqldump \
    -u "${DB_USER}" \
    -p"${DB_PASSWORD}" \
    --single-transaction \
    --routines \
    --triggers \
    --databases "${DB_NAME}" \
    | gzip > "${BACKUP_FILE}"

# Verify backup
if [ -f "${BACKUP_FILE}" ] && [ -s "${BACKUP_FILE}" ]; then
    FILE_SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)
    echo "SUCCESS: Backup created at ${BACKUP_FILE} (${FILE_SIZE})"
    
    # Show record counts
    echo ""
    echo "Database contents:"
    docker compose exec -T mysql mysql \
        -u "${DB_USER}" \
        -p"${DB_PASSWORD}" \
        -e "USE ${DB_NAME}; SHOW TABLE STATUS;" \
        | awk 'NR>1 {print "  " $1 ": " $5 " rows"}' 2>/dev/null || true
else
    echo "ERROR: Backup failed or is empty!"
    exit 1
fi

# Clean up old timestamped backups (keep last 10)
if [ "$1" == "" ]; then
    echo ""
    echo "Cleaning up old backups (keeping last 10)..."
    ls -t ${BACKUP_DIR}/opbx-backup-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -v
fi

echo ""
echo "To restore from this backup, run:"
echo "  gunzip < ${BACKUP_FILE} | docker compose exec -T mysql mysql -u ${DB_USER} -p'${DB_PASSWORD}'"
