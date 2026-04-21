# Database Persistence Guide

## Overview

OPBX uses **Docker named volumes** for MySQL data persistence. This ensures your database data survives container restarts, updates, and even container removals.

## Current Configuration

MySQL is configured in `docker-compose.yml` with:

```yaml
volumes:
  - mysql_data:/var/lib/mysql
```

The `mysql_data` volume is a **named Docker volume** that stores data on the host filesystem at:
- Linux: `/var/lib/docker/volumes/opbxcloudonixcom_mysql_data/_data`
- macOS: `~/Library/Containers/com.docker.docker/Data/volumes/opbxcloudonixcom_mysql_data/_data`
- Windows: `\\wsl$\docker-desktop-data\data\docker\volumes\opbxcloudonixcom_mysql_data\_data`

## ⚠️ DANGER: Commands That WILL Delete Your Data

### NEVER run these unless you intentionally want to erase everything:

```bash
# DESTROYS all data - removes volumes!
docker compose down -v

# DESTROYS only MySQL data
docker volume rm opbxcloudonixcom_mysql_data

# DESTROYS all unused volumes (including mysql_data if container is stopped)
docker volume prune

# Docker Desktop "Clean / Purge data" or "Reset to factory defaults"
```

### Safe commands (preserves data):

```bash
# Stops containers but keeps volumes
docker compose down

# Restarts containers (preserves all data)
docker compose restart

# Recreates containers (preserves volumes)
docker compose up -d --force-recreate
```

## Backup Strategy

### Automated Daily Backups

Set up a cron job for daily backups:

```bash
# Edit crontab
crontab -e

# Add this line for daily backups at 2 AM
0 2 * * * cd /path/to/opbx.cloudonix.com && ./scripts/backup-database.sh daily

# Add this line for weekly backups on Sundays at 3 AM
0 3 * * 0 cd /path/to/opbx.cloudonix.com && ./scripts/backup-database.sh weekly
```

### Manual Backup

```bash
# Create a timestamped backup
./scripts/backup-database.sh

# Create daily backup (overwrites previous daily)
./scripts/backup-database.sh daily

# Create weekly backup (overwrites previous weekly)
./scripts/backup-database.sh weekly
```

Backups are stored in `./backups/` and are NOT stored in Docker volumes (they persist on your host filesystem).

### Restore from Backup

```bash
# List available backups
ls -la backups/

# Restore from a specific backup
./scripts/restore-database.sh backups/opbx-backup-20260115_120000.sql.gz

# Or restore the daily backup
./scripts/restore-database.sh backups/opbx-daily.sql.gz
```

## Data Verification

To check if your data is intact:

```bash
# Check database contents
docker compose exec mysql mysql -u root -p -e "USE opbx; SELECT COUNT(*) as extensions FROM extensions; SELECT COUNT(*) as campaigns FROM auto_dialer_campaigns;"

# Check volume info
docker volume inspect opbxcloudonixcom_mysql_data
```

## Migration Safety

Database migrations are safe and do NOT delete data. They only:
- Add new columns
- Modify column types
- Create new tables
- Drop old columns (after data migration)

Always backup before running migrations on production data:

```bash
./scripts/backup-database.sh
docker compose exec app php artisan migrate
```

## Troubleshooting

### "Database is empty after restart"

1. Check if volume still exists: `docker volume ls | grep mysql`
2. If missing, it was likely deleted by `docker compose down -v`
3. Restore from backup: `./scripts/restore-database.sh backups/opbx-daily.sql.gz`

### "Permission denied on volume"

On Linux/macOS, Docker volumes may have permission issues:

```bash
# Fix ownership (Linux only)
sudo chown -R $USER:$USER /var/lib/docker/volumes/opbxcloudonixcom_mysql_data
```

### "Container won't start"

```bash
# Check logs
docker compose logs mysql

# Check if port is already in use
lsof -i :3306
```

## Best Practices

1. **Always backup before major changes** (migrations, updates, etc.)
2. **Never use `docker compose down -v`** unless you explicitly want to erase data
3. **Set up automated backups** via cron
4. **Test your restores** periodically
5. **Keep backups outside the project directory** for extra safety

## Docker Volume Persistence Test

To verify persistence is working:

```bash
# 1. Create some test data
docker compose exec mysql mysql -u root -p -e "CREATE TABLE IF NOT EXISTS opbx.persistence_test (id INT); INSERT INTO opbx.persistence_test VALUES (1);"

# 2. Stop and remove the container (NOT the volume!)
docker compose down

# 3. Start it again
docker compose up -d mysql

# 4. Check if data is still there
docker compose exec mysql mysql -u root -p -e "SELECT * FROM opbx.persistence_test;"

# 5. Clean up test table
docker compose exec mysql mysql -u root -p -e "DROP TABLE opbx.persistence_test;"
```

If the test data survives, your persistence is working correctly.
