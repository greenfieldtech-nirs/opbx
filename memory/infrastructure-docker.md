# Infrastructure & Docker

## Overview
11-service Docker Compose stack: PHP-FPM app, Nginx reverse proxy, React frontend (Vite), MySQL 8, Redis 7, MinIO (S3), Soketi (WebSocket), Go dialer worker, queue worker, scheduler, and ngrok tunnel.

## Source Files
| File | Purpose |
|------|---------|
| `docker-compose.yml` | Main orchestration |
| `docker-compose.testing.yml` | Test environment |
| `docker/php/Dockerfile` | PHP 8.4-FPM Alpine image |
| `docker/php/entrypoint.sh` | Container startup (security validation, migrations) |
| `docker/php/scheduler.sh` | Laravel scheduler runner |
| `docker/php/php.ini` | PHP configuration |
| `docker/php/opcache.ini` | OPcache configuration |
| `docker/nginx/nginx.conf` | Nginx base config |
| `docker/nginx/conf.d/default.conf` | Reverse proxy routes |
| `docker/mysql/my.cnf` | MySQL configuration |
| `docker/scripts/validate-env.sh` | Environment validation |

## Docker Services

| Service | Container | Image | Port | Purpose |
|---------|-----------|-------|------|---------|
| `app` | opbx_app | PHP 8.4-FPM (custom) | 9000 (internal) | Laravel backend |
| `queue-worker` | opbx_queue_worker | Same PHP image | - | Queue processing (auto-dialer, default) |
| `scheduler` | opbx_scheduler | Same PHP image | - | Laravel task scheduler |
| `frontend` | opbx_frontend | node:20-alpine | 3000 (internal) | Vite dev server |
| `nginx` | opbx_nginx | nginx:alpine | **80** | Reverse proxy |
| `mysql` | opbx_mysql | mysql:8.0 | (not exposed) | Database |
| `redis` | opbx_redis | redis:7-alpine | (not exposed) | Cache + queues + locks |
| `minio` | opbx_minio | minio/minio:latest | (not exposed) | S3-compatible storage |
| `soketi` | opbx_websocket | quay.io/soketi/soketi | 6001 (internal) | WebSocket server |
| `dialer-worker` | opbx_dialer_worker | Go custom build | 8081 (internal) | Auto-dialer worker |
| `ngrok` | opbx_ngrok | ngrok/ngrok:latest | 4040 | Tunnel (dev only) |

## Nginx Routing (docker/nginx/conf.d/default.conf)

| Location | Target | Notes |
|----------|--------|-------|
| `/app/` | `soketi:6001` | WebSocket proxy (Upgrade headers, 7-day timeout) |
| `/api/` | `app:9000` (PHP-FPM) | Laravel API via `try_files` -> `index.php` |
| `*.php$` | `app:9000` | PHP-FPM, 300s timeout, buffering disabled |
| `/` | `opbx_frontend:3000` | React Vite dev server (with HMR WebSocket support) |
| Static files | Direct serve | Images/CSS/JS/fonts/audio, 30d cache, Cache-Control public/immutable |
| `/.` | Deny | Hidden files blocked |
| `.env`, `.git`, etc. | Deny | Sensitive files blocked |

## PHP Dockerfile (docker/php/Dockerfile)
- Base: `php:8.4-fpm-alpine`
- Extensions: pdo_mysql, mbstring, zip, exif, pcntl, bcmath, opcache, intl, redis (PECL)
- Composer: copied from `composer:latest` multi-stage
- Dependency caching: `composer.json`/`composer.lock` copied first, then `composer install --no-dev`
- Runs as `www-data` user
- Optimized autoloader: `composer dump-autoload --optimize --no-dev`

## Entrypoint (docker/php/entrypoint.sh)
1. **Security validation**: Checks DB_PASSWORD, DB_ROOT_PASSWORD, MINIO keys against weak defaults
2. **File permissions**: `chmod -R 755`
3. **Environment validation**: Runs `validate-env.sh` if exists
4. **MySQL wait**: Loops `php artisan db:show` until MySQL responds
5. **Cache clearing**: route, config, view caches
6. **Migrations**: Only if `RUN_MIGRATIONS=true` (default). Runs migrate, seed, storage:initialize, config:validate
7. **Startup**: `exec "$@"` (php-fpm)

## Persistent Volumes
| Volume | Mount Point | Purpose |
|--------|------------|---------|
| `mysql_data` | `/var/lib/mysql` | Database persistence |
| `redis_data` | `/data` | Redis AOF persistence |
| `volumes/minio/` | `/data` | MinIO object storage |

## Network
Single `opbx` bridge network. All services communicate internally.

## Security Notes
- MySQL, Redis, MinIO ports are NOT exposed externally
- Redis requires password and has protected mode enabled
- Entrypoint validates passwords against weak defaults
- Nginx blocks access to hidden files, `.env`, `.git`, `composer.json`
- `DisableKeepAlives: true` in Go worker prevents stale connections after nginx restart

## Queue Worker Configuration
```bash
php artisan queue:work redis --queue=auto-dialer,default --sleep=3 --tries=3 --timeout=90
```
- Processes `auto-dialer` queue first (higher priority), then `default`
- 3 retry attempts, 90s timeout per job

## Startup Order
1. `mysql` + `redis` (health checks)
2. `app` (depends on mysql healthy + redis)
3. `nginx` (depends on app healthy)
4. `frontend` (depends on app + nginx + soketi)
5. `dialer-worker` (depends on nginx + redis)

**Important**: After restarting containers, wait **120 seconds** for the app container to fully initialize before testing.

## AMD Worker Service
- TypeScript-based AMD (Answer Machine Detection) WebSocket audio processor
- Source: `amd-worker/` directory (Node.js/TypeScript)
- No exposed ports directly; proxied through nginx on `/ws/amd/` -> `amd-worker:8082`
- Uses existing ngrok tunnel (no separate tunnel needed)
- Feature spec: `docs/feature-specification/voicemail-detection/SPECIFICATION.md`

## Related Modules
- [Dialer Worker](dialer-worker.md) - Go service configuration
- [WebSocket Real-Time](websocket-realtime.md) - Soketi service
- [Recordings](recordings-announcements.md) - MinIO storage
- [Settings & Cloudonix](settings-cloudonix.md) - Webhook URLs need proper base URL
