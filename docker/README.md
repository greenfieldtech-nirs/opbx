# Docker Infrastructure for OPBX

This directory contains the complete Docker-based infrastructure for the **OPBX** open-source business PBX platform. It defines the container orchestration, service configurations, and runtime environment for all platform components.

## Overview

OPBX is a multi-tenant business PBX built on **Laravel 12 (PHP 8.4)** and **React 18 (TypeScript)**, using **Cloudonix CPaaS** for VoIP services. The Docker stack runs 14 services on a single bridge network: the web frontend, API backend, queue workers, scheduler, real-time WebSocket messaging, the data stores, an MCP server, and workers for outbound dialing, voicemail detection and call queues. Five more are optional and start only with a profile: `ngrok` (profile `dev`) and the log stack `loki`, `alloy`, `grafana`, `docker-socket-proxy` (profile `logs`).

---

## Architecture

```mermaid
graph TB
    subgraph "Docker Network: opbx"
        direction TB

        Nginx["nginx<br/>(Reverse Proxy)"]

        subgraph "PHP Application Tier"
            App["app<br/>(PHP-FPM)"]
            Queue["queue-worker<br/>queue-worker-long"]
            Scheduler["scheduler"]
        end

        subgraph "Frontend Tier"
            Frontend["frontend<br/>(React/Vite)"]
        end

        subgraph "Data Tier"
            MySQL[("mysql<br/>(MySQL 8.4 LTS)")]
            Redis[("redis<br/>(Redis 7)")]
            SeaweedFS[("seaweedfs<br/>(S3-compatible)")]
        end

        subgraph "Real-Time Tier"
            Soketi["soketi<br/>(WebSocket Server)"]
        end

        subgraph "Worker Tier"
            Dialer["dialer-worker<br/>(Go)"]
            AMD["amd-worker<br/>(Java/Vert.x)"]
            ACD["acd-worker<br/>(Java/Vert.x)"]
            MCP["mcp-server<br/>(Node.js)"]
        end

        subgraph "Optional: profile dev"
            Ngrok["ngrok<br/>(Secure Tunnel)"]
        end

        subgraph "Optional: profile logs"
            Alloy["alloy"] --> Loki[("loki")]
            Grafana["grafana"] --> Loki
            Proxy["docker-socket-proxy"]
            Alloy --> Proxy
        end
    end

    Internet(("Internet / Cloudonix")) -->|HTTP/WebSocket| Nginx
    Ngrok -->|Public URL| Nginx

    Nginx -->|/api/| App
    Nginx -->|/| Frontend
    Nginx -->|/app/ (WS)| Soketi
    Nginx -->|/ws/amd/ (WS)| AMD
    Nginx -->|/mcp| MCP
    Nginx -->|/logs/| Grafana

    App -->|SQL| MySQL
    App -->|Cache/Queue| Redis
    App -->|S3 API| SeaweedFS
    App -->|HTTP| ACD

    Queue -->|SQL| MySQL
    Queue -->|Redis Queue| Redis

    Scheduler -->|SQL| MySQL

    Dialer -->|API| Nginx
    Dialer -->|Redis| Redis

    AMD -->|Callbacks| Nginx
    ACD -->|Queue state| Redis
    MCP -->|REST API| Nginx
```

---

## Service Descriptions

| # | Service | Image / Build | Purpose | Host Ports |
|---|---------|--------------|---------|------------|
| 1 | **app** | `docker/php/Dockerfile` (PHP 8.4-FPM) | Main Laravel API application server | None (FPM 9000 internal) |
| 2 | **queue-worker** | Same as `app` | Laravel queue worker (`auto-dialer`, `default` queues) | None |
| 3 | **queue-worker-long** | Same as `app` | Long jobs on the `auto-dialer-long` queue (list uploads, hold-music segmentation), 2-hour timeout | None |
| 4 | **scheduler** | Same as `app` | Runs `schedule:run` every 60 seconds | None |
| 5 | **frontend** | `frontend/Dockerfile.dev` (React/Vite) | React SPA development server with HMR | `3000` |
| 6 | **nginx** | `nginx:1.27-alpine` | Reverse proxy; routes traffic to the right backend | `80` |
| 7 | **mysql** | `mysql:8.4` (LTS) | Primary relational database | None (`3306` loopback via `docker-compose.ports.yml`) |
| 8 | **redis** | `redis:7-alpine` | Cache, sessions, queues, locks, worker state | None (`6379` loopback via `docker-compose.ports.yml`) |
| 9 | **seaweedfs** | `chrislusf/seaweedfs:4.47` | S3-compatible object storage for recordings and the CDR archive (replaces MinIO) | None (S3 `8333`, master UI `9333` loopback via `docker-compose.ports.yml`) |
| 10 | **soketi** | `quay.io/soketi/soketi:1.6-16-alpine` | WebSocket server for real-time frontend updates | `6001`, `9601` (metrics) |
| 11 | **dialer-worker** | `dialer-worker/Dockerfile` (Go) | Outbound campaign dialer; polls Laravel. Health endpoint only, no webhooks. One replica | None (`8181` → 8081 loopback via `docker-compose.ports.yml`) |
| 12 | **amd-worker** | `amd-worker/Dockerfile` (Java/Vert.x 5) | Stream-based voicemail/beep detection via WebSocket | None |
| 13 | **acd-worker** | `acd-worker/Dockerfile` (Java/Vert.x 5) | Call queue engine (FIFO, agent states) | None |
| 14 | **mcp-server** | `mcp-server/Dockerfile` (Node.js) | MCP facade over the REST API for AI assistants | None (`8080` loopback via `docker-compose.ports.yml`; public via nginx `/mcp`) |
| 15 | **ngrok** | `ngrok/ngrok:3-alpine` | Tunnel for Cloudonix webhook development. Profile `dev` | `4040` (web UI) |
| 16 | **loki** | `grafana/loki:3.4.2` | Log store. Profile `logs` | None |
| 17 | **alloy** | `grafana/alloy:v1.8.3` | Log collector. Profile `logs` | None |
| 18 | **grafana** | `grafana/grafana:12.4.12` | Log viewer at `/logs/`. Profile `logs` | None |
| 19 | **docker-socket-proxy** | `tecnativa/docker-socket-proxy:v0.4.2` | Read-only Docker API for Alloy. Profile `logs` | None |

The dialer, AMD and ACD worker images run as a non-root user (uid 10001).

---

## Directory Structure

```
docker/
├── php/
│   ├── Dockerfile          # PHP 8.4-FPM image build
│   ├── entrypoint.sh       # Container startup: validation, migrations, cache clear
│   ├── scheduler.sh        # Laravel scheduler loop (runs every 60s)
│   ├── php.ini             # PHP runtime configuration
│   ├── opcache.ini         # OPcache + JIT settings for production
│   └── zz-custom.conf      # PHP-FPM pool listen configuration
├── nginx/
│   ├── nginx.conf          # Main Nginx configuration (gzip, logging, worker settings, embed header maps)
│   ├── conf.d/
│   │   └── default.conf    # Dev virtual host: /api/, /app/, /ws/amd/, /mcp, /logs/, /embed/, /
│   └── conf.d.prod/
│       └── default.conf    # Production virtual host (static SPA), used by docker-compose.prod.yml
├── mysql/
│   └── my.cnf              # MySQL 8.4 performance and auth settings
├── loki/                   # Loki config (profile logs)
├── alloy/                  # Alloy collector config (profile logs)
├── grafana/                # Grafana data source and dashboard provisioning (profile logs)
├── ngrok/                  # ngrok config (profile dev)
├── scripts/
│   ├── validate-env.sh     # Security validation for critical environment variables
│   └── generate-secrets.sh # Fills in missing shared secrets in .env
└── README.md               # This file
```

---

## Build Instructions

### Prerequisites

- Docker Engine 24.0+ and Docker Compose v2
- A valid `.env` file in the project root (see `.env.example`)
- For ngrok: a valid `NGROK_AUTHTOKEN`

### Quick Start

```bash
# 0. Configuration and secrets (first time)
cp .env.example .env && cp docker-compose.yml.example docker-compose.yml
docker/scripts/generate-secrets.sh

# 1. Start the stack (optional services: --profile dev, --profile logs)
docker compose up -d

# 2. View logs for a specific service
docker compose logs -f app

# 3. Run Laravel Artisan commands
docker compose exec app php artisan migrate

# 4. Stop all services
docker compose down
```

### Rebuilding After Code Changes

```bash
# Rebuild the PHP application image
docker compose build app

# Rebuild and restart everything
docker compose up -d --build
```

---

## Configuration Details

### Nginx Routing

The reverse proxy handles all inbound traffic and routes it based on path:

| Path Prefix | Destination | Protocol | Purpose |
|-------------|-------------|----------|---------|
| `/app/` | `soketi:6001` | WebSocket | Soketi real-time connections |
| `/ws/amd/` | `amd-worker:8082` (rewritten to `/ws/`) | WebSocket | AMD audio stream from Cloudonix |
| `/api/` | `app:9000` | HTTP | Laravel API endpoints |
| `/mcp` | `mcp-server:8080` | HTTP | MCP server |
| `/logs/` | `grafana:3000` | HTTP | Grafana (profile `logs`) |
| `/embed/loader.js`, `/embed/dialer` | Laravel | HTTP | Embeddable dialer (may be framed by other sites) |
| `/` | `frontend:3000` | HTTP / WebSocket | React SPA and Vite HMR |

### PHP-FPM Configuration

- **Base image**: `php:8.4-fpm-alpine`
- **Extensions installed**: `pdo_mysql`, `mbstring`, `zip`, `exif`, `pcntl`, `bcmath`, `opcache`, `intl`, `redis`
- **OPcache**: Enabled with JIT tracing (`opcache.jit=tracing`, `opcache.jit_buffer_size=128M`)
- **Memory limit**: 256M per process
- **Upload limit**: 50M

### MySQL Configuration

- **Image**: `mysql:8.4` (LTS)
- **Config file**: `docker/mysql/my.cnf`, mounted read-only at `/etc/mysql/conf.d/opbx.cnf`
- **Authentication**: `mysql_native_password=ON` is set in `my.cnf` for older clients (MySQL 8.4 removed `default-authentication-plugin`). New accounts default to `caching_sha2_password`.
- **Performance**: `innodb_buffer_pool_size=256M`, `max_connections=100`, slow query log on (`long_query_time=1`)
- **Memory**: limit 1G, reservation 512M
- **Upgrading from 8.0**: back up first (see [Backup Recommendations](#backup-recommendations)); an 8.0 data directory is upgraded in place on the first 8.4 start.
- **Persistence**: Bind mount `./volumes/mysql`

### Redis Configuration

- **Image**: `redis:7-alpine`
- **Persistence**: AOF enabled (`appendonly yes`)
- **Authentication**: Password-protected. The container start command writes `requirepass` from `REDIS_PASSWORD` (passed as `REDISCLI_AUTH`) into `/tmp/redis.conf`, so the password is not in `docker inspect` command args or `ps`. The healthcheck's `redis-cli` reads `REDISCLI_AUTH` too.
- **Persistence**: Bind mount `./volumes/redis` (mounted at `/data`)
- **Memory**: limit 512M, reservation 128M

### Updating an existing installation's docker-compose.yml

After pulling a new version, bring your `docker-compose.yml` up to date with the new `docker-compose.yml.example` (all services, including the `logs` and `dev` profiles):

```bash
./scripts/update-compose.sh --dry-run   # report only: new, changed, yours-only and retired services
./scripts/update-compose.sh             # asks per changed service: take the new version, or keep yours
./scripts/update-compose.sh --yes       # unattended: take the new version of every changed service
```

New services are added, services that exist only in your file are always kept, and services removed upstream (e.g. `minio`) are removed only when you confirm (`--yes` keeps them). The result is validated with `docker compose config` before it is written, and the old file is kept as `docker-compose.yml.bak-<timestamp>`. Nothing is restarted; the script prints the `build` / `up -d` commands to run. Needs only Docker (YAML is handled by the `mikefarah/yq` image).

### SeaweedFS Configuration

> **Upgrading an install that used MinIO?** Run `./scripts/migrate-minio-to-seaweedfs.sh` before switching `MINIO_ENDPOINT`. It copies and verifies every bucket from `./volumes/minio`. See [Upgrading from MinIO](../docs/DATABASE-PERSISTENCE.md#upgrading-from-minio).

SeaweedFS replaces MinIO as the S3-compatible store. The app still reads the `MINIO_*` variables; they are only the S3 endpoint and keys. On AWS, point `MINIO_ENDPOINT` and the keys at real S3 instead.

- **Image**: `chrislusf/seaweedfs:4.47` (single `weed server` process: master, volume, and S3 API)
- **S3 endpoint** (inside the network): `http://seaweedfs:8333`, path-style URLs
- **Credentials**: `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY` from `.env`. The container writes them into an identity file inside the container at start, so they do not appear in command args. Weak values such as `minioadmin` are rejected by `entrypoint.sh`.
- **Persistence**: Bind mount `./volumes/seaweedfs` (mounted at `/data`)
- **Memory**: limit 512M, reservation 128M

#### Creating the Buckets

The app expects two private buckets: `recordings` (call recordings, IVR audio) and `cdr-archive` (cold CDR tier). Ops creates them; `storage:initialize` on startup only verifies they are reachable and writable.

```bash
# Option 1: SeaweedFS shell inside the container
echo "s3.bucket.create -name recordings" | docker compose exec -T seaweedfs weed shell
echo "s3.bucket.create -name cdr-archive" | docker compose exec -T seaweedfs weed shell

# Option 2: any S3 client via the loopback port (requires docker-compose.ports.yml)
aws s3 mb s3://recordings --endpoint-url http://127.0.0.1:8333
aws s3 mb s3://cdr-archive --endpoint-url http://127.0.0.1:8333
```

Option 2 uses the `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY` values from `.env` as the AWS credentials. Do not add a public-read policy to either bucket.

---

## Security Notes

### Password Validation

The `entrypoint.sh` script enforces strong passwords by rejecting common weak values:

```
secret, password, rootsecret, minioadmin, admin, 123456
```

If a weak password is detected, the container exits with an error. Generate a strong password with:

```bash
openssl rand -base64 32
```

### Environment Validation

The `validate-env.sh` script checks for:

- Empty or placeholder values in `DB_PASSWORD`, `APP_KEY`, `PUSHER_APP_SECRET`, `DIALER_WORKER_API_TOKEN`, `AMD_WORKER_API_TOKEN`, `ACD_WORKER_API_TOKEN`, plus `GRAFANA_ADMIN_PASSWORD` only when the log stack is in use (`LOG_STACK_ENABLED=true` or `logs` in `COMPOSE_PROFILES`)
- `APP_DEBUG=true` in production mode
- Weak passwords (fewer than 8 characters)
- Redis port exposure in production

### Port Exposure Policy

By default, **no database or internal service ports are exposed externally**:

| Service | Default Exposure | Override Variable |
|---------|-----------------|-------------------|
| MySQL | Disabled | `DB_EXPOSE_PORT` |
| Redis | Disabled | `REDIS_EXPOSE_PORT` |
| SeaweedFS | Disabled | `MINIO_EXPOSE_PORT` (S3 API, 8333), `MINIO_CONSOLE_EXPOSE_PORT` (master UI, 9333) |

Only `nginx` (port 80), `frontend` (port 3000) and `soketi` (ports 6001 and 9601) publish ports by default; `ngrok` (port 4040) does when the `dev` profile is started. The `*_EXPOSE_PORT` variables only change the loopback port numbers used by `docker-compose.ports.yml`:

```bash
docker compose -f docker-compose.yml -f docker-compose.ports.yml up -d   # never in production
```

### Security Headers

Nginx adds the following headers to all responses:

- `X-Frame-Options: SAMEORIGIN` (omitted on `/embed/loader.js` and `/embed/dialer`, which are meant to be framed)
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: geolocation=(), microphone=(self), camera=()` (`microphone=(self)` enables the WebRTC Web Phone)

### Sensitive File Protection

Access to `.env`, `composer.json`, `package.json`, and `.git` directories is explicitly denied.

---

## Volume Persistence Warnings

### Critical Data Volumes

The following volumes persist data across container restarts. **Data loss will occur if these are removed**.

| Volume | Mount Point | Service | Data Stored |
|--------|-------------|---------|-------------|
| `./volumes/mysql` | `/var/lib/mysql` | MySQL | All tenant databases, users, call records |
| `./volumes/redis` | `/data` | Redis | Sessions, cache, queue jobs, worker state |
| `./volumes/seaweedfs` | `/data` | SeaweedFS | Call recordings, uploaded files, CDR archive |
| `./volumes/amd-dumps` | `/tmp/amd-dumps` | AMD worker | Debug audio dumps (only with `AMD_DUMP_AUDIO=true`) |
| `loki_data` (named) | `/loki` | Loki | Logs (profile `logs`) |
| `grafana_data` (named) | `/var/lib/grafana` | Grafana | Grafana state (profile `logs`) |

The bind mounts survive `docker compose down -v`; only the named volumes are removed by it.

### Dangerous Commands

**NEVER run these commands without a verified backup:**

```bash
# ⚠️ DELETES ALL MySQL / Redis / SeaweedFS DATA PERMANENTLY
rm -rf ./volumes/mysql ./volumes/redis ./volumes/seaweedfs

# ⚠️ Removes the log stack's named volumes (Loki logs, Grafana state)
docker compose down -v
```

### Backup Recommendations

Before any destructive operation, create a backup:

```bash
# Backup the database
./scripts/backup-database.sh

# Backup SeaweedFS data: stop the container so files are not written mid-copy
docker compose stop seaweedfs
tar czf seaweedfs-backup-$(date +%Y%m%d).tar.gz ./volumes/seaweedfs
docker compose start seaweedfs
```

### Disk Usage

```bash
du -sh ./volumes/*
docker system df -v
```

---

## Health Checks

All critical services define Docker health checks:

| Service | Check Method | Interval | Timeout | Retries |
|---------|-------------|----------|---------|---------|
| `app` | `pgrep php-fpm` | 10s | 5s | 5 |
| `queue-worker`, `queue-worker-long` | `pgrep -f 'artisan queue:work'` | 30s | 5s | 3 |
| `scheduler` | heartbeat file touched within 3 minutes | 30s | 5s | 3 |
| `mysql` | `mysqladmin ping` | 10s | 5s | 5 |
| `redis` | `redis-cli ping` | 10s | 5s | 5 |
| `seaweedfs` | `wget http://localhost:9333/cluster/status` | 30s | 10s | 3 |
| `soketi` | `wget http://127.0.0.1:9601/ready` | 10s | 5s | 3 |
| `amd-worker` | `wget http://localhost:8083/health` | 30s | 10s | 3 |
| `acd-worker` | `wget http://localhost:8085/health` (fails when Redis is down) | 30s | 10s | 3 |
| `dialer-worker` | `wget http://127.0.0.1:8081/webhooks/health` | 30s | 5s | 3 |
| `mcp-server` | `wget http://127.0.0.1:8080/health` | 30s | 5s | 3 |
| `loki`, `grafana` | `wget /ready`, `/api/health` | 10s | 5s | 12 |

`alloy` has no healthcheck (distroless image).

Nginx waits for the `app` service to be healthy before starting.

---

## Resource Limits

Memory limits are configured for select services to prevent container resource exhaustion:

| Service | Memory Limit | Memory Reservation |
|---------|-------------|-------------------|
| `app` | 512M | 256M |
| `queue-worker` | 512M | 256M |
| `queue-worker-long` | 1G | 256M |
| `scheduler` | 256M | 128M |
| `mysql` | 1G | 512M |
| `redis` | 512M | 128M |
| `seaweedfs` | 512M | 128M |

---

## Troubleshooting

### Container Fails to Start

Check the entrypoint logs for validation errors:

```bash
docker compose logs app | head -n 50
```

Common causes:
- Weak/default passwords or S3 keys in `.env` (`minioadmin` is rejected; `.env.example` ships the S3 keys empty and `generate-secrets.sh` fills them)
- Missing required environment variables
- Missing `vendor/` on the host (run `composer install`; the repository is bind-mounted over the image)
- MySQL not ready (health check failure)

### Database Connection Issues

```bash
# Test MySQL connectivity from the app container
docker compose exec app php artisan db:show

# Check MySQL logs
docker compose logs mysql
```

### WebSocket Connection Issues

```bash
# Verify Soketi is healthy
docker compose exec soketi wget -qO- http://127.0.0.1:9601/ready

# Check Nginx error logs for proxy errors
docker compose exec nginx cat /var/log/nginx/opbx_error.log
```

### Reset Everything (Destructive)

```bash
# Stop and remove all containers and networks (bind-mounted data in ./volumes is kept)
docker compose down

# Deleting the data as well needs the bind-mount directories removed by hand
# rm -rf ./volumes/mysql ./volumes/redis ./volumes/seaweedfs

# Rebuild from scratch
docker compose up -d --build
```

---

## License

This Docker infrastructure is part of the OPBX project and is released under the same open-source license.
