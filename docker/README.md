# Docker Infrastructure for OpBX

This directory contains the complete Docker-based infrastructure for the **OpBX** open-source business PBX platform. It defines the container orchestration, service configurations, and runtime environment for all platform components.

## Overview

OpBX is a multi-tenant business PBX built on **Laravel 12 (PHP 8.4)** and **React 18 (TypeScript)**, using **Cloudonix CPaaS** for VoIP services. The Docker stack orchestrates 11 services across a single bridge network, providing the full application runtime including the web frontend, API backend, queue workers, real-time WebSocket messaging, object storage, and specialized workers for outbound dialing and voicemail detection.

---

## Architecture

```mermaid
graph TB
    subgraph "Docker Network: opbx"
        direction TB

        Nginx["nginx<br/>(Reverse Proxy)"]

        subgraph "PHP Application Tier"
            App["app<br/>(PHP-FPM)"]
            Queue["queue-worker"]
            Scheduler["scheduler"]
        end

        subgraph "Frontend Tier"
            Frontend["frontend<br/>(React/Vite)"]
        end

        subgraph "Data Tier"
            MySQL[("mysql<br/>(MySQL 8.0)")]
            Redis[("redis<br/>(Redis 7)")]
            MinIO[("minio<br/>(S3-compatible)")]
        end

        subgraph "Real-Time Tier"
            Soketi["soketi<br/>(WebSocket Server)"]
        end

        subgraph "Worker Tier"
            Dialer["dialer-worker<br/>(Go)"]
            AMD["amd-worker<br/>(Java/Vert.x)"]
        end

        subgraph "Tunnel Tier"
            Ngrok["ngrok<br/>(Secure Tunnel)"]
        end
    end

    Internet(("Internet / Cloudonix")) -->|HTTP/WebSocket| Nginx
    Ngrok -->|Public URL| Nginx

    Nginx -->|/api/| App
    Nginx -->|/| Frontend
    Nginx -->|/app/ (WS)| Soketi
    Nginx -->|/ws/amd/ (WS)| AMD

    App -->|SQL| MySQL
    App -->|Cache/Queue| Redis
    App -->|S3 API| MinIO

    Queue -->|SQL| MySQL
    Queue -->|Redis Queue| Redis

    Scheduler -->|SQL| MySQL

    Frontend -->|API Calls| Nginx
    Frontend -->|WebSocket| Soketi

    Dialer -->|API| Nginx
    Dialer -->|Redis| Redis

    AMD -->|Redis| Redis

    style Nginx fill:#e1f5fe
    style App fill:#fff3e0
    style Queue fill:#fff3e0
    style Scheduler fill:#fff3e0
    style Frontend fill:#e8f5e9
    style MySQL fill:#fce4ec
    style Redis fill:#fce4ec
    style MinIO fill:#fce4ec
    style Soketi fill:#f3e5f5
    style Dialer fill:#e0f2f1
    style AMD fill:#e0f2f1
    style Ngrok fill:#fff9c4
```

---

## Service Descriptions

| # | Service | Image / Build | Purpose | Exposed Ports |
|---|---------|--------------|---------|--------------|
| 1 | **app** | `docker/php/Dockerfile` (PHP 8.4-FPM) | Main Laravel API application server | Internal only (9000) |
| 2 | **queue-worker** | Same as `app` | Background job processor for Laravel queues (`auto-dialer`, `default`) | None |
| 3 | **scheduler** | Same as `app` | Runs Laravel's task scheduler every 60 seconds | None |
| 4 | **frontend** | `frontend/Dockerfile.dev` (React/Vite) | React SPA development server with HMR | `3000` |
| 5 | **nginx** | `nginx:alpine` | Reverse proxy and static file server; routes traffic to appropriate backend | `80` |
| 6 | **mysql** | `mysql:8.0` | Primary relational database for all tenant data | `3306` (optional via `DB_EXPOSE_PORT`) |
| 7 | **redis** | `redis:7-alpine` | Caching, session store, queue backend, and pub/sub | `6379` (optional via `REDIS_EXPOSE_PORT`) |
| 8 | **minio** | `minio/minio:latest` | S3-compatible object storage for call recordings | `9000`, `9001` (optional via `MINIO_EXPOSE_PORT`) |
| 9 | **soketi** | `quay.io/soketi/soketi:latest-16-alpine` | WebSocket server for real-time frontend updates | `6001`, `9601` |
| 10 | **dialer-worker** | `dialer-worker/Dockerfile` (Go) | Outbound campaign dialer; polls for jobs and handles CDR webhooks | `8081` |
| 11 | **amd-worker** | `amd-worker/Dockerfile` (Java/Vert.x 5) | Stream-based voicemail/beep detection via WebSocket | Internal only |
| 12 | **ngrok** | `ngrok/ngrok:latest` | Secure tunnel for Cloudonix webhook development | `4040` (web UI) |

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
│   ├── nginx.conf          # Main Nginx configuration (gzip, logging, worker settings)
│   └── conf.d/
│       └── default.conf    # Virtual host: routing rules for /api/, /app/, /ws/amd/, /
├── mysql/
│   └── my.cnf              # MySQL 8.0 performance and auth settings
├── scripts/
│   └── validate-env.sh     # Security validation for critical environment variables
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
# 1. Start the entire stack
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
| `/ws/amd/` | `amd-worker:8082` | WebSocket | AMD audio stream from Cloudonix |
| `/api/` | `app:9000` | HTTP | Laravel API endpoints |
| `/` | `frontend:3000` | HTTP / WebSocket | React SPA and Vite HMR |

### PHP-FPM Configuration

- **Base image**: `php:8.4-fpm-alpine`
- **Extensions installed**: `pdo_mysql`, `mbstring`, `zip`, `exif`, `pcntl`, `bcmath`, `opcache`, `intl`, `redis`
- **OPcache**: Enabled with JIT tracing (`opcache.jit=tracing`, `opcache.jit_buffer_size=128M`)
- **Memory limit**: 256M per process
- **Upload limit**: 50M

### MySQL Configuration

- **Image**: `mysql:8.0`
- **Authentication**: `mysql_native_password` for compatibility
- **Performance**: `innodb_buffer_pool_size=256M`, `max_connections=100`
- **Persistence**: Named volume `mysql_data`

### Redis Configuration

- **Image**: `redis:7-alpine`
- **Persistence**: AOF enabled (`appendonly yes`)
- **Authentication**: Password-protected (`--requirepass`)
- **Persistence**: Named volume `redis_data`

### MinIO Configuration

- **Image**: `minio/minio:latest`
- **Buckets**: `recordings` (auto-initialized by Laravel on startup)
- **Path-style URLs**: Enabled for local compatibility
- **Persistence**: Bind mount `./volumes/minio`

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

- Placeholder values in `DB_PASSWORD`, `APP_KEY`, `CLOUDONIX_API_TOKEN`, `CLOUDONIX_WEBHOOK_SECRET`
- `APP_DEBUG=true` in production mode
- Weak passwords (fewer than 8 characters)
- Redis port exposure in production

### Port Exposure Policy

By default, **no database or internal service ports are exposed externally**:

| Service | Default Exposure | Override Variable |
|---------|-----------------|-------------------|
| MySQL | Disabled | `DB_EXPOSE_PORT` |
| Redis | Disabled | `REDIS_EXPOSE_PORT` |
| MinIO | Disabled | `MINIO_EXPOSE_PORT`, `MINIO_CONSOLE_EXPOSE_PORT` |

Only `nginx` (port 80), `frontend` (port 3000), `soketi` (port 6001), `dialer-worker` (port 8081), and `ngrok` (port 4040) are exposed by default.

### Security Headers

Nginx adds the following headers to all responses:

- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: geolocation=(), microphone=(), camera=()`

### Sensitive File Protection

Access to `.env`, `composer.json`, `package.json`, and `.git` directories is explicitly denied.

---

## Volume Persistence Warnings

### Critical Data Volumes

The following volumes persist data across container restarts. **Data loss will occur if these are removed**.

| Volume | Mount Point | Service | Data Stored |
|--------|-------------|---------|-------------|
| `mysql_data` | `/var/lib/mysql` | MySQL | All tenant databases, users, call records |
| `redis_data` | `/data` | Redis | Sessions, cache, queue jobs |
| `./volumes/minio` | `/data` | MinIO | Call recordings, uploaded files |

### Dangerous Commands

**NEVER run these commands without a verified backup:**

```bash
# ⚠️ DELETES ALL MySQL DATA PERMANENTLY
docker compose down -v

# ⚠️ DELETES ALL MySQL DATA PERMANENTLY
docker volume rm opbx_mysql_data

# ⚠️ DELETES ALL Redis DATA PERMANENTLY
docker volume rm opbx_redis_data
```

### Backup Recommendations

Before any destructive operation, create a backup:

```bash
# Backup the database
./scripts/backup-database.sh

# Backup MinIO data
cp -r ./volumes/minio ./volumes/minio-backup-$(date +%Y%m%d)
```

### Volume Inspection

```bash
# List all volumes
docker volume ls

# Inspect a specific volume
docker volume inspect opbx_mysql_data

# Check disk usage
docker system df -v
```

---

## Health Checks

All critical services define Docker health checks:

| Service | Check Method | Interval | Timeout | Retries |
|---------|-------------|----------|---------|---------|
| `app` | `pgrep php-fpm` | 10s | 5s | 5 |
| `mysql` | `mysqladmin ping` | 10s | 5s | 5 |
| `redis` | `redis-cli ping` | 10s | 5s | 5 |
| `minio` | `curl /minio/health/live` | 30s | 10s | 3 |
| `soketi` | `wget /ready` | 10s | 5s | 3 |
| `amd-worker` | `wget /health` | 30s | 10s | 3 |
| `dialer-worker` | `wget /webhooks/health` | 30s | 5s | 3 |

Nginx waits for the `app` service to be healthy before starting.

---

## Resource Limits

Memory limits are configured for select services to prevent container resource exhaustion:

| Service | Memory Limit | Memory Reservation |
|---------|-------------|-------------------|
| `app` | 512M | 256M |
| `queue-worker` | 512M | 256M |
| `scheduler` | 256M | 128M |
| `minio` | 512M | 256M |

---

## Troubleshooting

### Container Fails to Start

Check the entrypoint logs for validation errors:

```bash
docker compose logs app | head -n 50
```

Common causes:
- Weak/default passwords in `.env`
- Missing required environment variables
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
docker compose exec soketi wget -qO- http://localhost:9601/ready

# Check Nginx error logs for proxy errors
docker compose exec nginx cat /var/log/nginx/opbx_error.log
```

### Reset Everything (Destructive)

```bash
# Stop and remove all containers, networks, and volumes
docker compose down -v

# Rebuild from scratch
docker compose up -d --build
```

---

## License

This Docker infrastructure is part of the OpBX project and is released under the same open-source license.
