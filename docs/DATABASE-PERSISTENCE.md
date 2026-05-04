# Database Persistence Guide

This document describes how OpBX persists data in Docker and how to protect, back up, and recover that data.

## Table of Contents

1. [Overview](#overview)
2. [Persistence Architecture](#persistence-architecture)
3. [Volume Details](#volume-details)
4. [What Survives vs. What Does Not](#what-survives-vs-what-does-not)
5. [Backup Procedures](#backup-procedures)
6. [Restore Procedures](#restore-procedures)
7. [Migration Safety](#migration-safety)
8. [Troubleshooting](#troubleshooting)
9. [Disaster Recovery](#disaster-recovery)

---

## Overview

OpBX uses Docker Compose to run its services. Three services store state that must persist across container restarts, host reboots, and deployments:

| Service | Technology | Purpose |
|---------|-----------|---------|
| **MySQL** | MySQL 8.0 | Primary relational database for all application data |
| **Redis** | Redis 7 (Alpine) | Caching, queues, sessions, real-time state |
| **MinIO** | MinIO (latest) | S3-compatible object storage for call recordings and files |

Each service uses a different persistence strategy. Understanding these strategies is essential for operating OpBX in production and avoiding accidental data loss.

---

## Persistence Architecture

```mermaid
flowchart TB
    subgraph Host["Host Machine"]
        subgraph DockerVolumes["Docker Named Volumes"]
            mysql_data[("mysql_data\n/var/lib/mysql")]
            redis_data[("redis_data\n/data")]
        end
        subgraph HostBindMounts["Host Bind Mounts"]
            minio_data[("./volumes/minio\n/data")]
        end
        subgraph Backups["Backups (Host Filesystem)"]
            backup_files["./backups/*.sql.gz"]
        end
    end

    subgraph Containers["Docker Containers"]
        mysql["MySQL 8.0\nopbx_mysql"]
        redis["Redis 7\nopbx_redis"]
        minio["MinIO\nopbx_minio"]
    end

    mysql_data -->|"mounted at"| mysql
    redis_data -->|"mounted at"| redis
    minio_data -->|"mounted at"| minio
    backup_files -.->|"restore from"| mysql
    mysql -.->|"backup to"| backup_files
```

### Key Design Decisions

1. **MySQL and Redis use Docker named volumes** (`mysql_data`, `redis_data`) rather than bind mounts. This isolates database files from the host filesystem, prevents permission issues, and allows Docker to manage the storage lifecycle.

2. **MinIO uses a host bind mount** (`./volumes/minio:/data`) so that object storage files are directly visible and accessible on the host filesystem. This simplifies browsing, backup, and recovery of large binary objects.

3. **Neither MySQL nor Redis expose ports by default**. External access is disabled unless explicitly configured via `.env` variables (`DB_EXPOSE_PORT`, `REDIS_EXPOSE_PORT`). Both remain fully accessible within the Docker network.

---

## Volume Details

### MySQL

| Attribute | Value |
|-----------|-------|
| **Image** | `mysql:8.0` |
| **Container Name** | `opbx_mysql` |
| **Volume Name** | `mysql_data` |
| **Mount Point** | `/var/lib/mysql` |
| **Port Exposure** | Not exposed by default (`DB_EXPOSE_PORT` is empty) |
| **Restart Policy** | `unless-stopped` |
| **Custom Config** | `docker/mysql/my.cnf` |

**Configuration highlights** (`docker/mysql/my.cnf`):

```ini
[mysqld]
default-authentication-plugin=mysql_native_password
bind-address=0.0.0.0
innodb_buffer_pool_size=256M
innodb_log_file_size=64M
max_connections=100
```

The `mysql_data` named volume stores all table data, indexes, user accounts, and schema definitions. It is managed by the Docker daemon and typically resides in Docker's internal storage area on the host (e.g., `/var/lib/docker/volumes/` on Linux, or a Docker Desktop-managed location on macOS/Windows).

### Redis

| Attribute | Value |
|-----------|-------|
| **Image** | `redis:7-alpine` |
| **Container Name** | `opbx_redis` |
| **Volume Name** | `redis_data` |
| **Mount Point** | `/data` |
| **Port Exposure** | Not exposed by default (`REDIS_EXPOSE_PORT` is empty) |
| **Restart Policy** | No explicit restart policy set |
| **Persistence Mode** | AOF (Append-Only File) |

**Command-line flags**:

```yaml
command: >
  redis-server
  --appendonly yes
  --requirepass ${REDIS_PASSWORD}
  --bind 0.0.0.0
  --protected-mode yes
```

With `--appendonly yes`, Redis writes every mutating operation to an append-only file (`/data/appendonly.aof`). On restart, Redis replays this file to reconstruct the dataset. This provides durable persistence comparable to a write-ahead log.

### MinIO

| Attribute | Value |
|-----------|-------|
| **Image** | `minio/minio:latest` |
| **Container Name** | `opbx_minio` |
| **Volume Type** | Host bind mount |
| **Host Path** | `./volumes/minio` |
| **Container Path** | `/data` |
| **Port Exposure** | Not exposed by default (`MINIO_EXPOSE_PORT`, `MINIO_CONSOLE_EXPOSE_PORT` are empty) |
| **Restart Policy** | `unless-stopped` |

MinIO stores objects as regular files on disk inside the bind-mounted directory. This makes it trivial to browse, back up, or archive object data using standard host filesystem tools.

---

## What Survives vs. What Does Not

### Data Survives

| Event | MySQL | Redis | MinIO |
|-------|-------|-------|-------|
| Container restart (`docker compose restart`) | Yes | Yes | Yes |
| Container recreation (`docker compose up --force-recreate`) | Yes | Yes | Yes |
| Host reboot | Yes | Yes | Yes |
| Docker daemon restart | Yes | Yes | Yes |
| `docker compose down` (without `-v`) | Yes | Yes | Yes |
| Image update / pull new version | Yes | Yes | Yes |

### Data Is Lost

| Event | MySQL | Redis | MinIO |
|-------|-------|-------|-------|
| `docker compose down -v` | **Lost** | **Lost** | **Lost** |
| `docker volume rm mysql_data` | **Lost** | N/A | N/A |
| `docker volume rm redis_data` | N/A | **Lost** | N/A |
| `docker volume prune` | **Lost** | **Lost** | N/A |
| `rm -rf ./volumes/minio` | N/A | N/A | **Lost** |
| Docker Desktop "Clean / Purge Data" | **Lost** | **Lost** | **Lost** |
| Deleting the project directory (if MinIO data is inside) | N/A | N/A | **Lost** |

> **Critical Warning**: `docker compose down -v` is the most common cause of accidental data loss in development environments. The `-v` flag removes named volumes. Always verify you are not using `-v` unless you explicitly intend to destroy all data.

---

## Backup Procedures

OpBX includes an automated backup script at `scripts/backup-database.sh`.

### Creating a Timestamped Backup

```bash
./scripts/backup-database.sh
```

This creates a compressed SQL dump in `./backups/opbx-backup-YYYYMMDD_HHMMSS.sql.gz` and automatically retains the last 10 timestamped backups.

### Creating a Daily Backup

```bash
./scripts/backup-database.sh daily
```

This overwrites `./backups/opbx-daily.sql.gz` with the latest dump. Suitable for cron jobs.

### Creating a Weekly Backup

```bash
./scripts/backup-database.sh weekly
```

This overwrites `./backups/opbx-weekly.sql.gz`.

### What the Backup Script Does

1. Loads environment variables from `.env`
2. Verifies the MySQL container (`opbx_mysql`) is running
3. Runs `mysqldump` with:
   - `--single-transaction` (consistent snapshot without locking)
   - `--routines` (stored procedures and functions)
   - `--triggers`
   - `--databases` (includes `CREATE DATABASE` and `USE` statements)
4. Compresses output with `gzip`
5. Verifies the backup file is non-empty
6. Prints table row counts for confirmation
7. Cleans up old timestamped backups (keeps last 10)

### Backing Up MinIO Object Storage

Because MinIO uses a host bind mount, you can back it up with standard tools:

```bash
# Full archive
tar czf minio-backup-$(date +%Y%m%d).tar.gz ./volumes/minio

# Or sync to remote storage
rsync -avz ./volumes/minio/ user@backup-server:/backups/opbx-minio/
```

### Backing Up Redis

Redis AOF files live in the `redis_data` named volume. You can back them up via Docker:

```bash
# Create a tarball of the Redis volume
docker run --rm -v opbx_redis_data:/data -v $(pwd)/backups:/backup alpine \
  tar czf /backup/redis-backup-$(date +%Y%m%d).tar.gz -C /data .
```

> **Note**: On macOS/Windows with Docker Desktop, volume names may be prefixed with the project name (e.g., `opbxcloudonixcom_redis_data`). Run `docker volume ls` to confirm the exact name.

### Automated Backup Recommendations

For production environments, configure a cron job or CI/CD pipeline:

```cron
# Daily at 2:00 AM
0 2 * * * cd /path/to/opbx && ./scripts/backup-database.sh daily

# Weekly on Sundays at 3:00 AM
0 3 * * 0 cd /path/to/opbx && ./scripts/backup-database.sh weekly
```

Store backups off-site (S3, NFS, remote server) to protect against host-level failures.

---

## Restore Procedures

### Restoring MySQL from Backup

Use the provided restore script:

```bash
./scripts/restore-database.sh backups/opbx-backup-20260115_120000.sql.gz
```

The script will:
1. Verify the backup file exists
2. Verify the MySQL container is running
3. Show current database record counts
4. Prompt for confirmation (`yes` / `no`)
5. Restore the database (supports both `.sql.gz` and plain `.sql` files)
6. Verify restored data with record counts

**Manual restore** (if the script is unavailable):

```bash
# From a gzipped backup
gunzip < backups/opbx-backup-YYYYMMDD_HHMMSS.sql.gz | \
  docker compose exec -T mysql mysql -u root -p'YOUR_ROOT_PASSWORD'

# From a plain SQL file
docker compose exec -T mysql mysql -u root -p'YOUR_ROOT_PASSWORD' < backup.sql
```

### Restoring MinIO Object Storage

```bash
# Extract archive
tar xzf minio-backup-YYYYMMDD.tar.gz -C ./volumes/

# Or sync from remote
rsync -avz user@backup-server:/backups/opbx-minio/ ./volumes/minio/
```

Restart the MinIO container if it was running during the restore:

```bash
docker compose restart minio
```

### Restoring Redis

```bash
# Stop Redis
docker compose stop redis

# Extract backup into the volume
docker run --rm -v opbx_redis_data:/data -v $(pwd)/backups:/backup alpine \
  sh -c "cd /data && tar xzf /backup/redis-backup-YYYYMMDD.tar.gz"

# Start Redis
docker compose up -d redis
```

---

## Migration Safety

### Before Major Changes

Always create a backup before:

- Running database migrations (`php artisan migrate`)
- Upgrading MySQL or Redis images
- Modifying `docker-compose.yml` volume definitions
- Switching Docker contexts or moving to a new host
- Any `docker compose down -v` operation

### Safe Migration Checklist

1. **Back up**: `./scripts/backup-database.sh`
2. **Verify backup**: `ls -lh ./backups/`
3. **Document current state**: note running container versions
4. **Apply changes**: migrate, upgrade, or reconfigure
5. **Verify application**: run smoke tests
6. **Keep backup until confirmed**: do not delete the backup immediately

### Moving to a New Host

1. On the old host:
   ```bash
   ./scripts/backup-database.sh
   tar czf minio-backup.tar.gz ./volumes/minio
   docker run --rm -v opbx_redis_data:/data -v $(pwd):/backup alpine \
     tar czf /backup/redis-backup.tar.gz -C /data .
   ```

2. Transfer `./backups/`, `minio-backup.tar.gz`, and `redis-backup.tar.gz` to the new host.

3. On the new host:
   ```bash
   docker compose up -d mysql redis minio
   ./scripts/restore-database.sh backups/opbx-backup-YYYYMMDD_HHMMSS.sql.gz
   tar xzf minio-backup.tar.gz -C ./volumes/
   # Restore Redis volume as described above
   ```

---

## Troubleshooting

### MySQL Container Fails to Start

**Symptom**: `opbx_mysql` exits immediately or loops in restart.

**Check logs**:
```bash
docker compose logs mysql
```

**Common causes**:
- **Corrupted data**: Restore from backup.
- **Permission issues**: Ensure the named volume is not mounted over a host directory with conflicting permissions.
- **Port conflict**: If `DB_EXPOSE_PORT` is set, verify the port is not in use.

### Redis Data Disappears After Restart

**Symptom**: Keys are missing after container restart.

**Check AOF is enabled**:
```bash
docker compose exec redis redis-cli -a $REDIS_PASSWORD CONFIG GET appendonly
```

Expected output:
```
1) "appendonly"
2) "yes"
```

If AOF is disabled, data was stored only in memory and is lost on restart. Re-enable it in `docker-compose.yml` and restart.

### MinIO Data Not Visible

**Symptom**: Buckets or objects are missing.

**Check the bind mount**:
```bash
ls -la ./volumes/minio/
```

If the directory is empty, the bind mount may not be working. Verify the path in `docker-compose.yml` and ensure the directory exists on the host before starting MinIO.

### "Data Loss Warning" on Startup

The application runs `scripts/check-data-protection.sh` on startup. If you see:

```
╔════════════════════════════════════════════════════════════════╗
║                    ⚠️  DATA LOSS WARNING  ⚠️                    ║
╚════════════════════════════════════════════════════════════════╝
```

This means the database appears empty. Possible causes:
- First-time setup (expected)
- Previous `docker compose down -v`
- Volume was deleted or corrupted

**Action**: If this is unexpected, restore from backup immediately.

### Docker Volume Not Found

**Symptom**: `docker volume ls` does not show `mysql_data` or `redis_data`.

**Cause**: Docker Compose prefixes volume names with the project name. The actual name may be `opbxcloudonixcom_mysql_data`.

**List all volumes**:
```bash
docker volume ls
```

**Inspect a specific volume**:
```bash
docker volume inspect opbxcloudonixcom_mysql_data
```

---

## Disaster Recovery

### Scenario 1: Accidental `docker compose down -v`

1. **Do not panic**. The volumes are gone, but backups remain on the host filesystem.
2. **Verify backups exist**:
   ```bash
   ls -lh ./backups/
   ```
3. **Recreate volumes and containers**:
   ```bash
   docker compose up -d mysql redis minio
   ```
4. **Restore MySQL**:
   ```bash
   ./scripts/restore-database.sh backups/opbx-daily.sql.gz
   ```
5. **Restore MinIO** from your latest archive.
6. **Restore Redis** from your latest AOF backup (or accept loss of transient cache/queue data).
7. **Verify application functionality**.

### Scenario 2: Host Disk Failure

1. **Provision new host** or reinstall the OS.
2. **Clone the OpBX repository**.
3. **Copy backups** from off-site storage (S3, NAS, remote server) to `./backups/`.
4. **Follow the "Moving to a New Host"** procedure above.

### Scenario 3: Database Corruption

1. **Stop the MySQL container**:
   ```bash
   docker compose stop mysql
   ```
2. **Rename the corrupted volume** (do not delete yet):
   ```bash
   docker volume rename opbxcloudonixcom_mysql_data mysql_data_corrupted_$(date +%Y%m%d)
   ```
3. **Start a fresh MySQL container** (creates a new empty volume).
4. **Restore from backup**:
   ```bash
   ./scripts/restore-database.sh backups/opbx-daily.sql.gz
   ```
5. **Verify data integrity**.
6. **Delete the corrupted volume** only after confirming the restore is successful.

### Scenario 4: Ransomware or Malicious Deletion

1. **Isolate the affected host** from the network.
2. **Assess backup integrity**: verify backups were not also compromised.
3. **Restore from the most recent clean backup** to a new, clean host.
4. **Rotate all credentials** (database passwords, API tokens, MinIO keys).
5. **Review access logs** and patch any vulnerabilities.

### Recovery Time Objectives (RTO) and Recovery Point Objectives (RPO)

| Service | RPO Target | RTO Target | Strategy |
|---------|-----------|-----------|----------|
| MySQL | 24 hours (daily backups) | 1 hour | Daily automated backups + manual restore |
| Redis | Best effort (AOF) | 15 minutes | AOF persistence + periodic volume backups |
| MinIO | 24 hours | 1 hour | Host bind mount + periodic `tar` archives |

For stricter RPO/RTO requirements, consider:
- **MySQL**: Binary log replication to a secondary instance
- **Redis**: Redis Sentinel or Cluster for high availability
- **MinIO**: MinIO erasure coding or bucket replication

---

## Quick Reference

| Task | Command |
|------|---------|
| Back up database (timestamped) | `./scripts/backup-database.sh` |
| Back up database (daily) | `./scripts/backup-database.sh daily` |
| Restore database | `./scripts/restore-database.sh <file.sql.gz>` |
| List Docker volumes | `docker volume ls` |
| Inspect MySQL volume | `docker volume inspect <project>_mysql_data` |
| View MySQL logs | `docker compose logs mysql` |
| View Redis logs | `docker compose logs redis` |
| Restart all services | `docker compose restart` |
| Safe shutdown (keeps data) | `docker compose down` |
| Dangerous shutdown (destroys data) | `docker compose down -v` |

---

*Last updated: 2026-05-03*
