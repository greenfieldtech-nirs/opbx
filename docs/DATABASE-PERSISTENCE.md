# Database Persistence Guide

This document describes how OPBX persists data in Docker and how to protect, back up, and recover that data.

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

OPBX uses Docker Compose to run its services. Three services store state that must persist across container restarts, host reboots, and deployments:

| Service | Technology | Purpose |
|---------|-----------|---------|
| **MySQL** | MySQL 8.4 LTS | Primary relational database for all application data |
| **Redis** | Redis 7 (Alpine) | Caching, queues, sessions, real-time state |
| **SeaweedFS** | SeaweedFS 4.47 | S3-compatible object storage for call recordings, files, and the CDR archive (replaces MinIO) |

Each service uses a different persistence strategy. Understanding these strategies is essential for operating OPBX in production and avoiding accidental data loss.

---

## Persistence Architecture

```mermaid
flowchart TB
    subgraph Host["Host Machine"]
        subgraph HostBindMounts["Host Bind Mounts"]
            mysql_data[("./volumes/mysql\n/var/lib/mysql")]
            redis_data[("./volumes/redis\n/data")]
            seaweedfs_data[("./volumes/seaweedfs\n/data")]
        end
        subgraph Backups["Backups (Host Filesystem)"]
            backup_files["./backups/*.sql.gz"]
        end
    end

    subgraph Containers["Docker Containers"]
        mysql["MySQL 8.4\nopbx_mysql"]
        redis["Redis 7\nopbx_redis"]
        seaweedfs["SeaweedFS\nopbx_seaweedfs"]
    end

    mysql_data -->|"mounted at"| mysql
    redis_data -->|"mounted at"| redis
    seaweedfs_data -->|"mounted at"| seaweedfs
    backup_files -.->|"restore from"| mysql
    mysql -.->|"backup to"| backup_files
```

### Key Design Decisions

1. **MySQL, Redis, and SeaweedFS all use host bind mounts** (`./volumes/mysql`, `./volumes/redis`, `./volumes/seaweedfs`). Bind mounts make data directly visible on the host filesystem, simplifying backups, debugging, and recovery. The `volumes/` directory is gitignored so it is never committed.

2. **Redis AOF persistence** is enabled (`appendonly yes` in the generated config file) so Redis data survives normal container restarts. Treat Redis as operational cache/queue storage rather than a primary source of truth.

3. **Neither MySQL nor Redis expose ports by default** (opt in, loopback-only, with `-f docker-compose.ports.yml`). External access is disabled unless `docker-compose.ports.yml` is added; `DB_EXPOSE_PORT` and `REDIS_EXPOSE_PORT` only change its loopback port numbers. Both remain fully accessible within the Docker network.

---

## Volume Details

### MySQL

| Attribute | Value |
|-----------|-------|
| **Image** | `mysql:8.4` (LTS) |
| **Container Name** | `opbx_mysql` |
| **Volume Type** | Host bind mount |
| **Host Path** | `./volumes/mysql` |
| **Container Path** | `/var/lib/mysql` |
| **Port Exposure** | Not exposed by default (`DB_EXPOSE_PORT` is empty) |
| **Restart Policy** | `unless-stopped` |
| **Custom Config** | `docker/mysql/my.cnf` (mounted read-only) |

The bind-mounted directory stores all table data, indexes, user accounts, and schema definitions. It resides in your project directory, so it is included in normal filesystem backups (but excluded from git).

`my.cnf` sets `mysql_native_password=ON` because MySQL 8.4 removed `default-authentication-plugin`. New accounts default to `caching_sha2_password`; the legacy plugin stays available for older clients. The file also sets `innodb_buffer_pool_size=256M`, `max_connections=100`, and the slow query log. The container has a 1G memory limit.

An existing MySQL 8.0 data directory is upgraded in place on the first start of 8.4. Back up before the first start (see [Migration Safety](#migration-safety)).

### Redis

| Attribute | Value |
|-----------|-------|
| **Image** | `redis:7-alpine` |
| **Container Name** | `opbx_redis` |
| **Volume Type** | Host bind mount |
| **Host Path** | `./volumes/redis` |
| **Container Path** | `/data` |
| **Port Exposure** | Not exposed by default (`REDIS_EXPOSE_PORT` is empty) |
| **Restart Policy** | No explicit restart policy set |
| **Persistence Mode** | AOF (Append-Only File) |

**Start command**: the password is not passed as `--requirepass` (that would expose it in `docker inspect` and `ps`). The command writes a root-only config file from the `REDISCLI_AUTH` environment variable (set to `${REDIS_PASSWORD}`) and starts Redis from it:

```yaml
command:
  - sh
  - -c
  - |
    umask 077
    esc=$$(printf '%s' "$$REDISCLI_AUTH" | sed 's/[\\"]/\\&/g')
    printf 'requirepass "%s"\nappendonly yes\nbind 0.0.0.0\nprotected-mode yes\n' "$$esc" > /tmp/redis.conf
    exec redis-server /tmp/redis.conf
```

The config lives in the container's `/tmp`, not on the data volume. The password is still an environment variable of the container (needed by `redis-cli` in the healthcheck).

With `appendonly yes`, Redis writes every mutating operation to an append-only file (`/data/appendonly.aof`). On restart, Redis replays this file to reconstruct the dataset. This provides durable persistence comparable to a write-ahead log.

### SeaweedFS

| Attribute | Value |
|-----------|-------|
| **Image** | `chrislusf/seaweedfs:4.47` |
| **Container Name** | `opbx_seaweedfs` |
| **Volume Type** | Host bind mount |
| **Host Path** | `./volumes/seaweedfs` |
| **Container Path** | `/data` |
| **Port Exposure** | Not published by default. Opt-in loopback ports (S3 API `8333`, master UI `9333`) via `docker-compose.ports.yml` (`MINIO_EXPOSE_PORT`, `MINIO_CONSOLE_EXPOSE_PORT`) |
| **Restart Policy** | `unless-stopped` |

SeaweedFS runs as a single `weed server` process (master, volume server, and S3 API). The S3 identity is written inside the container at start from `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY`. The app still reads the `MINIO_*` variables, which are only the S3 endpoint and keys. On AWS, point them at real S3 instead.

SeaweedFS packs objects into volume files inside the bind-mounted directory. Browse objects through an S3 client, not the host filesystem. Back up the whole directory while the container is stopped.

#### Upgrading from MinIO

Installations created before SeaweedFS kept their objects (call recordings, IVR/announcement audio, the cold CDR archive) in MinIO under `./volumes/minio`. The new `docker-compose.yml` has no MinIO service, so copy the content across before switching the app:

```bash
./scripts/backup-database.sh                        # back up first
cp docker-compose.yml.example docker-compose.yml    # or merge the SeaweedFS service into your compose file
./scripts/migrate-minio-to-seaweedfs.sh --dry-run   # lists buckets and object counts, copies nothing
./scripts/migrate-minio-to-seaweedfs.sh             # copies and verifies every bucket
```

The script starts SeaweedFS, runs a temporary MinIO container on `./volumes/minio` with the same `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY`, mirrors every bucket (all organisations, since each one is a folder inside the bucket), and verifies each bucket with `mc diff` and object counts. It exits non-zero on any mismatch and is safe to re-run. `./volumes/minio` is never modified, so it stays as a fallback.

When it reports success, switch the app over (`./scripts/upgrade.sh` runs the copy and these steps for you):

```bash
# .env
MINIO_ENDPOINT=http://seaweedfs:8333

./scripts/upgrade.sh      # starts app, waits for its migrations and bucket checks, then the rest
```

Copied objects get the migration date as their last-modified time (S3 cannot keep the original), so recording retention counts from the migration date.

---

## Storage Responsibilities

### MySQL — Source of Truth

MySQL is the authoritative store for all durable application data:

- Organizations, users, roles, and permissions
- Extensions, DIDs, ring groups, IVR menus, business hours, conference rooms
- Call detail records (CDRs), call logs, session updates
- Auto-dialer campaigns, destinations, and call sessions
- Platform audit logs, settings, and notification configurations

### Redis — Ephemeral Runtime State

Redis supports fast, transient state. AOF persistence survives normal restarts, but Redis should not be treated as a source of truth. Redis is used for:

- Sessions (`SESSION_DRIVER=redis`)
- Job queues (`QUEUE_CONNECTION=redis`)
- Application cache (`CACHE_STORE=redis`)
- Rate-limit counters (`rate_limit:org:{id}:{type}`)
- Webhook idempotency keys (`idem:webhook:{key}`)
- Distributed locks (`lock:call:{call_id}`)
- IVR call state and dialer CAC counters (`dialer:cac:{campaign_id}:active`)

### SeaweedFS — Object Storage

SeaweedFS stores binary objects in two private buckets, `recordings` and `cdr-archive`:

- Call recordings
- IVR prompts and announcements
- Auto-dialer audio assets

External access to recordings is gated through HMAC-signed URLs generated by the Laravel backend. See [Security](/opbx/architecture/security) for details.

---

## What Survives vs. What Does Not

### Data Survives

| Event | MySQL | Redis | SeaweedFS |
|-------|-------|-------|-----------|
| Container restart (`docker compose restart`) | Yes | Yes | Yes |
| Container recreation (`docker compose up --force-recreate`) | Yes | Yes | Yes |
| Host reboot | Yes | Yes | Yes |
| Docker daemon restart | Yes | Yes | Yes |
| `docker compose down` (with or without `-v`) | Yes | Yes | Yes |
| Image update / pull new version | Yes | Yes | Yes |

### Data Is Lost

| Event | MySQL | Redis | SeaweedFS |
|-------|-------|-------|-----------|
| `rm -rf ./volumes/mysql` | **Lost** | N/A | N/A |
| `rm -rf ./volumes/redis` | N/A | **Lost** | N/A |
| `rm -rf ./volumes/seaweedfs` | N/A | N/A | **Lost** |
| Docker Desktop "Clean / Purge Data" | **Lost** | **Lost** | **Lost** |
| Deleting the project directory | **Lost** | **Lost** | **Lost** |

> **Note**: `docker compose down -v` removes only named and anonymous volumes. The data stores use bind mounts under `./volumes/`, which it does not touch; the `logs` profile's `loki_data` and `grafana_data` named volumes are removed. Deleting a `./volumes/*` directory is what destroys data.

---

## Backup Procedures

OPBX includes an automated backup script at `scripts/backup-database.sh`.

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

### Backing Up SeaweedFS Object Storage

Stop the container first so volume files are not written mid-copy:

```bash
docker compose stop seaweedfs
tar czf seaweedfs-backup-$(date +%Y%m%d).tar.gz ./volumes/seaweedfs
docker compose start seaweedfs

# Or sync the stopped directory to remote storage
rsync -avz ./volumes/seaweedfs/ user@backup-server:/backups/opbx-seaweedfs/
```

Alternatively, mirror the buckets with an S3 client while the stack runs (requires `docker-compose.ports.yml`; use the `MINIO_*` keys from `.env`):

```bash
aws s3 sync s3://recordings ./backups/recordings --endpoint-url http://127.0.0.1:8333
aws s3 sync s3://cdr-archive ./backups/cdr-archive --endpoint-url http://127.0.0.1:8333
```

### Backing Up Redis

Redis AOF files live in `./volumes/redis`. Back them up with standard tools:

```bash
# Create a tarball of the Redis data directory
tar czf redis-backup-$(date +%Y%m%d).tar.gz -C ./volumes/redis .
```

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

### Restoring SeaweedFS Object Storage

```bash
# Stop SeaweedFS so nothing writes during the restore
docker compose stop seaweedfs

# Extract archive
tar xzf seaweedfs-backup-YYYYMMDD.tar.gz -C ./volumes/

# Or sync from remote
rsync -avz user@backup-server:/backups/opbx-seaweedfs/ ./volumes/seaweedfs/

docker compose start seaweedfs
```

### Restoring Redis

```bash
# Stop Redis
docker compose stop redis

# Extract backup into the bind mount
tar xzf redis-backup-YYYYMMDD.tar.gz -C ./volumes/redis

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
- Deleting or moving anything under `./volumes/`

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
   docker compose stop seaweedfs
   tar czf seaweedfs-backup.tar.gz ./volumes/seaweedfs
   tar czf redis-backup.tar.gz -C ./volumes/redis .
   ```

2. Transfer `./backups/`, `seaweedfs-backup.tar.gz`, and `redis-backup.tar.gz` to the new host.

3. On the new host:
   ```bash
   docker compose up -d mysql redis seaweedfs
   ./scripts/restore-database.sh backups/opbx-backup-YYYYMMDD_HHMMSS.sql.gz
   tar xzf seaweedfs-backup.tar.gz -C ./volumes/
   tar xzf redis-backup.tar.gz -C ./volumes/redis
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
- **Permission issues**: Ensure `./volumes/mysql` is writable by the container user.
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

### SeaweedFS Data Not Visible

**Symptom**: Buckets or objects are missing.

**Check the bind mount and the buckets**:
```bash
ls -la ./volumes/seaweedfs/
echo "s3.bucket.list" | docker compose exec -T seaweedfs weed shell
```

If the directory is empty after uploads, the bind mount may not be working. Verify the path in `docker-compose.yml` and ensure the directory exists on the host before starting SeaweedFS. If a bucket is missing, create it as described in [docker/README.md](../docker/README.md#creating-the-buckets).

### "Data Loss Warning" on Startup

`scripts/check-data-protection.sh` prints this warning when the database looks empty (no extensions and no auto-dialer campaigns, and at most one user). No container runs it at startup; run it yourself where `mysql` and `mysqladmin` can reach the database, with `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` set. If you see:

```
╔════════════════════════════════════════════════════════════════╗
║                    ⚠️  DATA LOSS WARNING  ⚠️                    ║
╚════════════════════════════════════════════════════════════════╝
```

This means the database appears empty. Possible causes:
- First-time setup (expected)
- The `./volumes/mysql` directory was deleted, moved or corrupted
- An older compose file that still used named volumes (`docker compose down -v` removes those)

**Action**: If this is unexpected, restore from backup immediately.

### Docker Volume Commands Do Not Find Volumes

Because the current compose file uses bind mounts, `docker volume ls` will not show `mysql_data` or `redis_data`. Use the host paths instead:

```bash
ls -la ./volumes/mysql
ls -la ./volumes/redis
ls -la ./volumes/seaweedfs
```

---

## Disaster Recovery

### Scenario 1: Data Directories Deleted

1. **Do not panic**. The `./volumes/*` directories are gone, but backups remain on the host filesystem.
2. **Verify backups exist**:
   ```bash
   ls -lh ./backups/
   ```
3. **Recreate containers**:
   ```bash
   docker compose up -d mysql redis seaweedfs
   ```
4. **Restore MySQL**:
   ```bash
   ./scripts/restore-database.sh backups/opbx-daily.sql.gz
   ```
5. **Restore SeaweedFS** from your latest archive.
6. **Restore Redis** from your latest AOF backup (or accept loss of transient cache/queue data).
7. **Verify application functionality**.

### Scenario 2: Host Disk Failure

1. **Provision new host** or reinstall the OS.
2. **Clone the OPBX repository**.
3. **Copy backups** from off-site storage (S3, NAS, remote server) to `./backups/`.
4. **Follow the "Moving to a New Host"** procedure above.

### Scenario 3: Database Corruption

1. **Stop the MySQL container**:
   ```bash
   docker compose stop mysql
   ```
2. **Rename the corrupted bind mount** (do not delete yet):
   ```bash
   mv ./volumes/mysql ./volumes/mysql_corrupted_$(date +%Y%m%d)
   ```
3. **Start a fresh MySQL container** (creates a new empty directory).
4. **Restore from backup**:
   ```bash
   ./scripts/restore-database.sh backups/opbx-daily.sql.gz
   ```
5. **Verify data integrity**.
6. **Delete the corrupted directory** only after confirming the restore is successful.

### Scenario 4: Ransomware or Malicious Deletion

1. **Isolate the affected host** from the network.
2. **Assess backup integrity**: verify backups were not also compromised.
3. **Restore from the most recent clean backup** to a new, clean host.
4. **Rotate all credentials** (database passwords, API tokens, `MINIO_*` S3 keys).
5. **Review access logs** and patch any vulnerabilities.

### Recovery Time Objectives (RTO) and Recovery Point Objectives (RPO)

| Service | RPO Target | RTO Target | Strategy |
|---------|-----------|-----------|----------|
| MySQL | 24 hours (daily backups) | 1 hour | Daily automated backups + manual restore |
| Redis | Best effort (AOF) | 15 minutes | AOF persistence + periodic directory backups |
| SeaweedFS | 24 hours | 1 hour | Host bind mount + periodic `tar` archives |

For stricter RPO/RTO requirements, consider:
- **MySQL**: Binary log replication to a secondary instance
- **Redis**: Redis Sentinel or Cluster for high availability
- **SeaweedFS**: Mirror buckets to a second S3 endpoint, or use AWS S3 (set `MINIO_ENDPOINT` and keys) with its replication features

---

## Migration and Model Notes

- All tenant models include `created_at` and `updated_at` timestamps.
- Foreign keys follow the `{singular_table}_id` convention.
- MySQL is the source of truth for schema state; run migrations with `docker compose exec app php artisan migrate`.
- Redis does not require schema migrations, but changes to key formats between application versions may temporarily coexist during rolling updates.
- On startup with `RUN_MIGRATIONS=true`, `storage:initialize --wait` (`app/Console/Commands/InitializeStorage.php`) waits for the S3 endpoint, creates the `recordings` and `cdr-archive` buckets if they are missing (private), and verifies both are writable. They can also be created by hand (see [docker/README.md](../docker/README.md#creating-the-buckets)).

---

## Quick Reference

| Task | Command |
|------|---------|
| Back up database (timestamped) | `./scripts/backup-database.sh` |
| Back up database (daily) | `./scripts/backup-database.sh daily` |
| Restore database | `./scripts/restore-database.sh <file.sql.gz>` |
| Back up SeaweedFS | `docker compose stop seaweedfs && tar czf seaweedfs-backup-$(date +%Y%m%d).tar.gz ./volumes/seaweedfs && docker compose start seaweedfs` |
| Back up Redis | `tar czf redis-backup-$(date +%Y%m%d).tar.gz -C ./volumes/redis .` |
| View MySQL logs | `docker compose logs mysql` |
| View Redis logs | `docker compose logs redis` |
| Restart all services | `docker compose restart` |
| Safe shutdown (keeps data) | `docker compose down` |
| Remove the log stack's named volumes | `docker compose down -v` (data stores in `./volumes/` are kept) |
| Destroy data | `rm -rf ./volumes/<store>` |

---

*Last updated: 2026-10-13*
