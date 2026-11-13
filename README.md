# OPBX - Open Source Business PBX

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Laravel](https://img.shields.io/badge/Laravel-12-red.svg)](https://laravel.com)
[![React](https://img.shields.io/badge/React-18-blue.svg)](https://reactjs.org)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue.svg)](https://docs.docker.com/compose/)

A modern, containerized business PBX application built on top of the [Cloudonix CPaaS](https://cloudonix.com) platform. OPBX provides enterprise-grade call routing, ring groups, IVR menus, business hours management, AI assistant integration, automated outbound dialing, and real-time call monitoring — all without the complexity of managing SIP infrastructure.

> **Documentation**: [User Guide](https://developers.cloudonix.com/opbx) | [REST API Reference](https://developers.cloudonix.com/opbxRestOpenAPI)

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Built With](#built-with)
- [Installation](#installation)
- [Configuration](#configuration)
- [Security & Monitoring](#security--monitoring)
- [API Reference](#api-reference)
- [Testing](#testing)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

---

## Features

### Call Routing & Management
- **Direct Extension Calling**: Extension-to-extension dialing with SIP URI generation
- **Outbound Calling**: E.164 international dialing with whitelist-based trunk routing
- **Ring Groups**: Distribute calls with simultaneous, round-robin, or sequential strategies
- **IVR Menus**: Interactive voice menus with DTMF input, TTS, and configurable destinations
- **Business Hours**: Time-based routing with weekly schedules, holiday exceptions, and public holiday import
- **AI Assistant Integration**: Route calls to 17 AI-powered voice providers via SIP or WebSocket
- **AI Load Balancers**: Distribute calls across multiple AI assistants with round-robin, priority, or percentage strategies and automatic failover
- **Conference Rooms**: Multi-party conference calls with PIN protection, recording, and host waiting

### Auto Dialer (Outbound Campaigns)
- **Campaign Management**: Create, schedule, and monitor outbound calling campaigns
- **Distribution Lists**: CSV-based phone number lists with validation and versioning
- **Rate Limiting**: Concurrent Active Calls (CAC, 1-50) + Calls Per Second (CPS, 1-5) for precise call pacing
- **Answering Machine Detection**: Java/Vert.x 5 AMD worker with ML + energy-based tone detection for voicemail beep detection
- **Caller ID Pooling**: Multi-DID caller ID rotation with round-robin, random, and LRU strategies
- **Real-Time Monitor**: Command-center dashboard with bird's-eye campaign cards and drill-down views
- **Go Dialer Worker**: Dedicated microservice for rate-limited call execution with Redis-based CAC counters
- **Campaign Scheduling**: Weekly calendar with per-day time ranges, timezone support, and date ranges

### Phone Number Management
- **DID Management**: Full support for Direct Inward Dialing numbers in E.164 format
- **Flexible Routing**: Route DIDs to extensions, ring groups, IVR menus, AI assistants, conference rooms, AI load balancers, or business hours schedules
- **Outbound Whitelist**: Control outbound calling with country/prefix-based rules and trunk selection
- **Inbound Blacklist**: Block unwanted callers with exact, prefix, or wildcard matching and configurable rejection strategies

### Real-Time Monitoring
- **Live Call Dashboard**: Real-time call presence via WebSockets with automatic stale record cleanup
- **Auto Dialer Monitor**: Real-time campaign monitoring with active calls, disposition pie charts, and KPI cards
- **Call Detail Records (CDR)**: Complete call history with search, filtering, and streaming CSV export; older records move to a warm archive (6 to 12 months) and then to compressed cold files kept 7 years by default
- **Call Statistics**: Volume, duration, and disposition metrics
- **Call Notifications**: Webhook-based notifications for call events with SSRF protection and retry logic
- **Centralized Logging**: Optional Loki + Grafana stack aggregating structured logs from all services, with per-call audit trails searchable by Cloudonix session token ([docs](docs/LOG-AGGREGATION.md))

### Performance & Reliability
- **Redis Caching Layer**: 50-90% faster routing lookups with automatic cache invalidation
- **Idempotent Webhooks**: Redis-based deduplication prevents duplicate processing
- **Distributed Locking**: Prevents race conditions on concurrent calls
- **Queue Workers**: Async job processing for non-blocking operations
- **Service Architecture**: Modular voice routing with dedicated services for outbound, business hours, and IVR handling
- **CAC Counter Reconciliation**: Laravel reconciles the auto dialer's Redis CAC counters every minute, healing drift from missed or duplicate call records
- **Circuit Breaker**: Fault tolerance for Cloudonix API calls with automatic fallback
- **Resilient Cache**: Graceful degradation from Redis to database when Redis is unavailable

### Multi-Tenant Architecture
- **Organization Isolation**: Complete data separation between tenants via global query scopes
- **Role-Based Access Control (RBAC)**:
  - **Owner**: Full organization control
  - **PBX Admin**: Manage users, extensions, ring groups, business hours, campaigns
  - **PBX User**: Access own extension and basic features
  - **Reporter**: Read-only access to reports and call logs
- **Platform Management**: Cross-tenant administration for service providers hosting multiple organizations

---

## Architecture

OPBX separates concerns into distinct planes for scalability and maintainability.

### High-Level Architecture

```mermaid
graph TB
    subgraph "External Services"
        CX[Cloudonix CPaaS]
        NGROK[ngrok Tunnel]
    end

    subgraph "Frontend"
        REACT[React SPA<br/>Port 3000]
    end

    subgraph "Load Balancer"
        NGINX[nginx<br/>Port 80]
    end

    subgraph "Application Layer"
        APP[Laravel App<br/>PHP-FPM]
        QUEUE[Queue Worker]
        SCHEDULER[Task Scheduler]
    end

    subgraph "Dialer Service"
        GOWORKER[Go Dialer Worker<br/>10s Poll Cycle]
    end

    subgraph "AMD Service"
        AMD[AMD Worker<br/>Java/Vert.x 5]
    end

    subgraph "ACD Service"
        ACD[Call Queue Worker<br/>Java/Vert.x 5]
    end

    subgraph "Voice Routing Services"
        VRM[VoiceRoutingManager]
        ORS[OutboundRoutingService]
        BHRS[BusinessHoursRoutingService]
        IVR[IVRRoutingStrategy]
    end

    subgraph "Data Layer"
        MYSQL[(MySQL 8.4 LTS<br/>Port 3306)]
        REDIS[(Redis 7<br/>Port 6379)]
        SEAWEEDFS[(SeaweedFS S3<br/>Port 8333)]
    end

    subgraph "Real-Time"
        SOKETI[Soketi<br/>WebSocket Server<br/>Port 6001]
    end

    subgraph "Observability (optional, profile: logs)"
        ALLOY[Alloy Collector]
        LOKI[(Loki Log Store)]
        GRAFANA[Grafana UI<br/>at /logs/]
    end

    CX -->|Webhooks| NGROK
    NGROK --> NGINX
    REACT --> NGINX
    NGINX --> APP
    APP --> VRM
    VRM --> ORS
    VRM --> BHRS
    VRM --> IVR
    APP --> MYSQL
    APP --> REDIS
    APP --> SEAWEEDFS
    APP --> SOKETI
    APP --> CX
    GOWORKER -->|Poll API| APP
    GOWORKER --> REDIS
    AMD -->|Audio Stream| CX
    AMD -->|AMD Result| APP
    ACD -->|Queue State| REDIS
    APP -->|Queue Decisions| ACD
    APP & GOWORKER & AMD & ACD -.->|JSON stdout| ALLOY
    ALLOY --> LOKI
    LOKI --> GRAFANA
    NGINX --> GRAFANA
    QUEUE --> REDIS
    QUEUE --> MYSQL
    SCHEDULER --> APP
    SOKETI --> REACT
```

### Inbound Call Flow

```mermaid
sequenceDiagram
    participant Caller
    participant Cloudonix
    participant OPBX
    participant Redis
    participant MySQL
    participant UI

    Caller->>Cloudonix: Inbound Call
    Cloudonix->>OPBX: POST /voice/route
    OPBX->>Redis: Acquire Lock (call_id)
    OPBX->>Redis: Check Idempotency
    OPBX->>Redis: Cache Lookup (routing)
    alt Cache Miss
        OPBX->>MySQL: Query DID/Extension Config
        OPBX->>MySQL: Check Business Hours
        OPBX->>Redis: Store in Cache
    end
    OPBX->>MySQL: Log Call
    OPBX->>Cloudonix: CXML Response (<Dial>, <Gather>)
    OPBX->>Redis: Broadcast Event
    Redis->>UI: Real-time Update
```

### Outbound Call Flow

```mermaid
sequenceDiagram
    participant Ext as Extension
    participant OPBX
    participant OW as Outbound Whitelist
    participant CX as Cloudonix

    Ext->>OPBX: Dial External Number
    OPBX->>OPBX: Validate Extension Active
    OPBS->>OW: Match Destination
    alt Whitelist Match Found
        OW->>OPBX: Return Trunk Configuration
        OPBX->>CX: CXML <Dial trunks="Trunk-X">
    else No Match
        OPBX->>Ext: Reject Call
    end
```

### Control Plane vs Execution Plane

| Aspect | Control Plane | Execution Plane |
|--------|---------------|-----------------|
| **Purpose** | Configuration management | Real-time call processing |
| **Components** | REST API, React SPA | Webhooks, Queue Workers |
| **Data Store** | MySQL (source of truth) | Redis (cache, locks, state) |
| **Latency** | Standard web latency | Sub-100ms response required |

---

## Built With

### Backend
| Technology | Version | Purpose |
|------------|---------|---------|
| [Laravel](https://laravel.com) | 12 | PHP application framework |
| [PHP](https://php.net) | 8.4+ | Server-side language |
| [Go](https://go.dev) | 1.21+ | Dialer worker microservice |
| [Java](https://openjdk.org) | 21 | AMD & ACD (call queue) workers (Vert.x 5) |
| [MySQL](https://mysql.com) | 8.4 LTS | Relational database |
| [Redis](https://redis.io) | 7 | Cache, queues, CAC counters |
| [Laravel Sanctum](https://laravel.com/docs/sanctum) | - | API authentication |

### Frontend
| Technology | Version | Purpose |
|------------|---------|---------|
| [React](https://reactjs.org) | 18 | UI framework |
| [TypeScript](https://typescriptlang.org) | 5 | Type-safe JavaScript |
| [Vite](https://vitejs.dev) | - | Build tool |
| [Tailwind CSS](https://tailwindcss.com) | 3 | Utility-first CSS |
| [Radix UI](https://radix-ui.com) | - | Accessible components |
| [React Query](https://tanstack.com/query) | - | Server state management |

### Infrastructure
| Technology | Purpose |
|------------|---------|
| [Docker](https://docker.com) | Containerization |
| [nginx](https://nginx.org) | Web server / reverse proxy |
| [Soketi](https://soketi.app) | WebSocket server (Laravel Echo compatible) |
| [SeaweedFS](https://github.com/seaweedfs/seaweedfs) | S3-compatible object storage for recordings and the CDR archive |
| [ngrok](https://ngrok.com) | Webhook tunneling for local development |
| [Grafana Loki](https://grafana.com/oss/loki/) | Log aggregation store (optional, `logs` profile) |
| [Grafana](https://grafana.com) | Log viewer at `/logs/` (optional, `logs` profile) |
| [Grafana Alloy](https://grafana.com/docs/alloy/) | Container log collector (optional, `logs` profile) |

---

## Installation

### Prerequisites

- **Docker** (20.10+) and **Docker Compose** (2.0+)
- **Cloudonix CPaaS Account**: [Sign up at cloudonix.com](https://cloudonix.com)
- **ngrok Account** (for local development): [Get authtoken](https://dashboard.ngrok.com/get-started/your-authtoken)

### Quick Start

The host needs only Docker with the Compose plugin (or Podman with a Docker-compatible socket). Node.js, PHP and
Composer are not needed on the host: the installer runs in a container.

```bash
git clone https://github.com/greenfieldtech-nirs/opbx.cloudonix.com.git
cd opbx.cloudonix.com
./install.sh
```

The installer asks for the deployment type (production or development), the public address, whether the server
hosts one company or many organizations, what the site root shows (the sign-in page by default, or the OPBX homepage), the company and owner details, the email provider (Mailgun, Mailjet,
MailerLite, Brevo (Sendinblue) or none), optional Auth0 sign-in, reCAPTCHA and UserCheck, whether to collect logs in
Grafana, and whether to put the SafeLine WAF in front. It then generates all secrets, writes `.env` and the compose files, starts the stack,
creates the platform owner and runs a few checks. At the end it prints the URLs, the owner login and, for production,
a reverse proxy snippet.

- **Production needs an `https://` address.** OPBX does not terminate TLS. Your own reverse proxy or HTTPS accelerator
  must terminate TLS and forward one domain to the OPBX nginx port (`APP_PORT`, default 80). The installer prints a
  ready-to-paste proxy snippet. If you give it the proxy IP or CIDR (`TRUSTED_PROXY_CIDR`), nginx uses the real client
  IP from `X-Forwarded-For`. Without it, rate limits, logs and WAF events see the proxy's address.
- **WAF (SafeLine CE)** can be off, monitor (log only) or block. The installer shows the host steps it needs and waits
  for you to confirm each one. The WAF console is served by the OPBX nginx on a port you choose (default 9443,
  `WAF_CONSOLE_PORT`), so your proxy must forward that port too (a second block in the snippet). If the proxy runs on
  the same server, pick a console port the proxy does not listen on. The console is limited to the proxy IP when you
  gave one; otherwise only SafeLine's own login protects it.
- **Owner account.** The installer creates the organization owner (who is also a platform manager) with a generated
  password. The password is shown once and saved to `opbx-install-summary.txt` (mode 600, git-ignored). Store the
  password, then delete that file. The seeded `admin@example.com` account does not exist on installer-made systems.
- **Development from another machine.** The Vite dev server only answers to host names it knows (`localhost` always works).
  A development install asks for the other names you use to open it (a Tailscale or other VPN name, the machine's IP, an
  ngrok domain; `.ts.net` allows every name under it) and writes them to `VITE_ALLOWED_HOSTS`.
- **Everyday use.** The installer writes `COMPOSE_FILE`, `COMPOSE_PROFILES` and `COMPOSE_PARALLEL_LIMIT=2` to `.env`, so
  starting the stack afterwards is `docker compose up -d`.

Other modes:

```bash
./install.sh                # install; on an existing install offers Change settings / Start over / Exit.
                            # An interrupted install is resumed.
./install.sh reconfigure    # change the public address, the front page, the allowed host names (development),
                            # or turn the WAF on/off or switch its mode
./install.sh reset          # delete all data and generated config (see below)
```

`reset` lists what it will destroy, offers a database backup into `./backups/`, and asks you to type the domain
(production) or `RESET` (development). It then deletes `volumes/mysql`, `volumes/redis`, `volumes/seaweedfs`,
`volumes/amd-dumps`, the Loki and Grafana volumes, the SafeLine `resources/` and `logs/` directories under its state
directory (a host step, shown for you to run) and the generated config files. Afterwards it offers to reuse your
previous answers.

To set a new owner password later:

```bash
docker compose exec app php artisan user:reset-password <email>
```

The old `./opbx_bootstrap.sh` now just runs `./install.sh`. See [installer/README.md](installer/README.md) for how the
installer works.

### Advanced: manual setup

Use this only if you do not want the installer. It starts the stack with the default (development) compose file.

1. **Clone the repository**
   ```bash
   git clone https://github.com/greenfieldtech-nirs/opbx.cloudonix.com.git
   cd opbx.cloudonix.com
   ```

2. **Prepare the configuration (before the first start)**
   ```bash
   cp docker-compose.yml.example docker-compose.yml   # git-ignored
   cp .env.example .env
   docker/scripts/generate-secrets.sh .env            # worker tokens, Pusher/Soketi secret, Grafana, S3 keys
   ```
   Both this script and the `APP_KEY` value need `openssl` on the host. Set `APP_KEY` in `.env` to `base64:` followed
   by the output of `openssl rand -base64 32`. The containers refuse to start with a missing `APP_KEY` or placeholder secrets.

   Then edit `.env` and set the values the script cannot choose for you. Use strong random passwords; `secret`,
   `rootsecret`, `password`, `minioadmin` and `admin` are rejected at startup:
   ```env
   NGROK_AUTHTOKEN=your_ngrok_authtoken_here   # local development only
   DB_DATABASE=opbx
   DB_USERNAME=opbx
   DB_PASSWORD=...        # openssl rand -base64 32
   DB_ROOT_PASSWORD=...   # openssl rand -base64 32
   REDIS_PASSWORD=...     # openssl rand -base64 32
   ```

3. **Install the PHP dependencies** (the repository is bind-mounted into the containers, and the `app` container
   exits without `vendor/autoload.php`)
   ```bash
   docker compose run --rm --no-deps --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
     --entrypoint composer app install --no-interaction --prefer-dist --optimize-autoloader
   ```
   `--user` keeps `vendor/` owned by you instead of root, and `COMPOSER_HOME` points Composer at a writable
   directory for that user. The installer adds `--no-scripts` (and `--no-dev` for production), then runs
   `php artisan package:discover` as root, because after the first start `bootstrap/cache` belongs to `www-data`.

4. **Start all services**
   ```bash
   docker compose up -d
   ```

5. **Initialize the database**
   ```bash
   docker compose exec app php artisan migrate --seed
   ```
   The seeder creates the default `admin@example.com` / `password` account (see Default Credentials). Change it
   before exposing the system.

6. **Access the application**
   - **Frontend**: http://localhost:3000
   - **API**: http://localhost/api/v1
   - **ngrok Dashboard**: http://localhost:4040 (after `docker compose --profile dev up -d ngrok`)
   - **SeaweedFS master UI**: http://localhost:9333 (requires `-f docker-compose.ports.yml`)

### Upgrading an existing installation

After pulling a version that includes the guided installer:

- Run `./scripts/update-compose.sh` to bring your `docker-compose.yml` up to date. The nginx service now starts
  through `docker/nginx/render.sh` and reads `TRUSTED_PROXY_CIDR`, `WAF_CONSOLE_ENABLED` and `WAF_CONSOLE_ALLOW`; an old
  generated `docker-compose.yml` combined with the new `docker-compose.waf.yml` loses the WAF hook.
- Production (`docker-compose.prod.yml`) no longer publishes Soketi's ports 6001 and 9601. Browsers reach WebSockets
  through the nginx `/app/` proxy: set `VITE_WS_PORT=443` and `VITE_WS_SCHEME=https`, then rebuild the frontend.
- `./opbx_bootstrap.sh` is now interactive (it runs `./install.sh`). Automation that called it must use the manual
  setup steps above instead.
- `/up` now returns Laravel's health page instead of the SPA.
- SafeLine's own `127.0.0.1:9443` console mapping is gone; the OPBX nginx serves the console on `WAF_CONSOLE_PORT`
  (with `WAF_CONSOLE_ENABLED=true`).
- `./install.sh reconfigure` only works on installations the installer set up (`OPBX_INSTALLER_MANAGED=true`). For a
  manual installation, edit `.env` and run `docker compose up -d`.

### ⚠️ Database Persistence

MySQL data is stored in the host bind mount `./volumes/mysql` that **survives container restarts and recreation**. However, it **will be deleted** if you run:

```bash
# NEVER run this unless you want to erase all data!
docker compose down -v
```

**Always backup before major changes:**
```bash
# Create a backup
./scripts/backup-database.sh

# Restore from backup (if needed)
./scripts/restore-database.sh backups/opbx-daily.sql.gz
```

### Database Backups

`./scripts/backup-database.sh` dumps the MySQL database (via `mysqldump` inside the container) to `./backups/` on the **host** filesystem. Every backup is verified to contain a valid dump.

```bash
./scripts/backup-database.sh           # Timestamped backup (keeps last 10)
./scripts/backup-database.sh daily     # Rotating daily backup (overwrites)
./scripts/backup-database.sh weekly    # Rotating weekly backup (overwrites)
./scripts/backup-database.sh pre-test  # Safety backup (runs automatically before ./run-tests.sh)
```

**Scheduling (production):** trigger backups from the **host**, not a container — a broken stack must not stop your backups. Example cron entry:

```cron
0 3 * * * cd /path/to/opbx && ./scripts/backup-database.sh daily >> /var/log/opbx-backup.log 2>&1
```

Backups in `./backups/` live on the same disk as the database — copy them off-box (rsync/rclone/S3) for real disaster protection.

See [docs/DATABASE-PERSISTENCE.md](docs/DATABASE-PERSISTENCE.md) for full details.

### Docker Services

| Service | Description | Port |
|---------|-------------|------|
| `frontend` | React SPA (Vite dev server) | 3000 (dev only) |
| `nginx` | Web server / API gateway | `APP_PORT` (default 80) |
| `app` | Laravel PHP-FPM application | - |
| `queue-worker` | Laravel queue processor | - |
| `scheduler` | Laravel cron scheduler | - |
| `dialer-worker` | Go auto-dialer worker | 8181 |
| `amd-worker` | Java/Vert.x AMD worker | - |
| `acd-worker` | Java/Vert.x call queue worker | 8084 |
| `mcp-server` | MCP server for AI agents ([docs](mcp-server/README.md)) | 8080 |
| `mysql` | MySQL 8.4 LTS database | 3306 |
| `redis` | Redis 7 cache/queue/CAC | 6379 |
| `seaweedfs` | S3-compatible storage (SeaweedFS) | 8333, 9333 (loopback, opt-in) |
| `soketi` | WebSocket server (reached through nginx at `/app/`) | 6001 (dev only) |
| `nginx` WAF console | SafeLine console, only with the WAF overlay and `WAF_CONSOLE_ENABLED=true` (`WAF_CONSOLE_PORT`) | 9443 |
| `ngrok` | Webhook tunnel — *profile `dev`* | 4040 |
| `loki` | Log store — *profile `logs`* | - |
| `alloy` | Log collector — *profile `logs`* | - |
| `grafana` | Log viewer via nginx at `/logs/` — *profile `logs`* | - |

### Default Credentials

On a system made by `./install.sh` there are no default credentials. The installer creates the owner you specified and
shows the generated password once (it is also saved to `opbx-install-summary.txt`; delete that file after storing the
password). A lost owner password is reset with `docker compose exec app php artisan user:reset-password <email>`.

Only the manual setup seeds an account (`php artisan migrate --seed`):

- **Email**: `admin@example.com`
- **Password**: `password`

> ⚠️ **Change these credentials immediately in production!**

---

## Configuration

### Environment Variables

See `.env.example` for all available configuration options. Key variables:

| Variable | Description |
|----------|-------------|
| `REDIS_PASSWORD` | Redis authentication password |
| `DB_PASSWORD` | MySQL database password |
| `NGROK_AUTHTOKEN` | ngrok authentication token |
| `LOG_STACK_ENABLED` | Enable centralized logging (use with `--profile logs`) |
| `GRAFANA_ADMIN_PASSWORD` | Grafana admin password for the log viewer |
| `GRAFANA_ROOT_URL` | Public URL of the log viewer (`<APP_URL>/logs/`); must match or Grafana 403s queries |
| `LOKI_RETENTION_HOURS` | Log retention (default 168 = 7 days) |

### Cloudonix Webhook Configuration

Webhooks are configured per-organization in **Settings → Cloudonix** in the admin UI. Configure these webhook URLs in your Cloudonix portal (replace `{YOUR_WEBHOOK_URL}` with your organization's webhook base URL):

| Event | URL |
|-------|-----|
| Voice Application | `{YOUR_WEBHOOK_URL}/api/voice/route` |
| IVR Input | `{YOUR_WEBHOOK_URL}/api/voice/ivr-input` |
| CDR | `{YOUR_WEBHOOK_URL}/api/webhooks/cloudonix/cdr` |
| Session Update | `{YOUR_WEBHOOK_URL}/api/webhooks/cloudonix/session-update` |
| Call Status | `{YOUR_WEBHOOK_URL}/api/webhooks/cloudonix/call-status` |

---

## Security & Monitoring

### Security Features

- **Multi-Tenant Isolation**: Global query scopes enforce organization boundaries
- **RBAC Authorization**: Policy-based access control at all layers
- **API Authentication**: Laravel Sanctum with token rotation
- **Webhook Security**: 
  - HMAC signature verification for CDR/status webhooks
  - Bearer token authentication for voice routing
  - Idempotency protection against duplicate processing
- **Rate Limiting**: Configurable per-endpoint limits
- **Security Headers**: CSP, HSTS, X-Frame-Options, and more
- **API Key Masking**: Cloudonix API keys are encrypted and masked in API responses

### Monitoring

**Health Check Endpoint:**
```bash
curl http://localhost/api/health
```

**Platform Status:** platform managers see every component's health, with a 24-hour history, at `/ui/platform/status`. The public status page at `/status` shows coarse group health without login. See the [user guide](docs/opbx-userguide/administration/platform-management.mdx).

**Application Logs:**
```bash
docker compose logs -f app
```

**Centralized Logs (optional):**
```bash
# .env: LOG_STACK_ENABLED=true, GRAFANA_ADMIN_PASSWORD=<password>, GRAFANA_ROOT_URL=<APP_URL>/logs/
docker compose --profile logs up -d
# Open http://localhost/logs/ — provisioned dashboards "OPBX Call Flow Logs"
# (session-token audit trail) and "OPBX Platform Logs", both with text
# search and click-to-inspect detail view.
```
See [docs/LOG-AGGREGATION.md](docs/LOG-AGGREGATION.md) for details, or
[SUBMITTING_LOGS.md](SUBMITTING_LOGS.md) for the end-user guide (setup,
dashboards, session-token filtering, exporting logs for bug reports).

**Queue Monitoring:**
```bash
docker compose exec app php artisan queue:monitor
```

**Cache Statistics:**
```bash
docker compose exec redis redis-cli -a $REDIS_PASSWORD INFO stats
```

**Cleanup Stale Session Updates:**
```bash
# Dry run to see what would be cleaned
docker compose exec app php artisan session-updates:cleanup --hours=2 --dry-run

# Actually clean stale records
docker compose exec app php artisan session-updates:cleanup --hours=2
```

For detailed security implementation, see [`docs/architecture/security-implementation.md`](docs/architecture/security-implementation.md).

---

## API Reference

### Authentication

```bash
# Login
curl -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "<your-email>", "password": "<your-password>"}'

# Response: {"token": "YOUR_TOKEN", "user": {...}}

# Use token in subsequent requests
curl http://localhost/api/v1/extensions \
  -H "Authorization: Bearer YOUR_TOKEN"
```

#### Scoped API Keys

Organization Owners can issue long-lived, revocable **scoped API keys** (prefixed `opbxk_`) for
integrations and automation. Each key carries its own per-resource `read`/`write` permission set,
which is the sole authorization gate for requests made with it — independent of the user role model.
The plaintext value is shown only once at creation.

#### MCP Server (AI Agents)

OPBX includes an optional [MCP server](mcp-server/README.md) (`mcp-server` compose service) that
exposes a curated, safety-gated [Model Context Protocol](https://modelcontextprotocol.io) interface
over the REST API — letting AI agents manage extensions, routing, campaigns, and configuration
audits with role-based access and confirmation-gated destructive operations.

```bash
# Create a key (Owner session token). The plaintext key is returned ONCE.
curl -X POST http://localhost/api/v1/api-keys \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "CI deploy key",
    "permissions": [
      {"resource": "business-hours", "level": "read"},
      {"resource": "extensions", "level": "write"}
    ]
  }'

# Response: {"data": {...}, "key": "opbxk_XXXXXXXX..."}

# Use the key like any other bearer token
curl http://localhost/api/v1/business-hours \
  -H "Authorization: Bearer opbxk_XXXXXXXX..."
```

See the [API Keys module guide](docs/opbx-userguide/modules/api-keys.mdx) for details.

### Extensions

```bash
# List extensions
curl http://localhost/api/v1/extensions \
  -H "Authorization: Bearer YOUR_TOKEN"

# Create extension
curl -X POST http://localhost/api/v1/extensions \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "extension_number": "1001",
    "type": "user",
    "name": "John Doe",
    "user_id": 1
  }'

# Update extension routing
curl -X PUT http://localhost/api/v1/extensions/1001 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "call_forwarding_enabled": true,
    "configuration": {
      "forwarding_number": "+1234567890"
    }
  }'
```

### Phone Numbers (DIDs)

```bash
# List phone numbers
curl http://localhost/api/v1/phone-numbers \
  -H "Authorization: Bearer YOUR_TOKEN"

# Create with extension routing
curl -X POST http://localhost/api/v1/phone-numbers \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "phone_number": "+12125551234",
    "friendly_name": "Main Line",
    "routing_type": "extension",
    "routing_config": {"extension_id": "1"}
  }'

# Route to AI Assistant
curl -X PUT http://localhost/api/v1/phone-numbers/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "routing_type": "ai_assistant",
    "routing_config": {"ai_assistant_id": "5"}
  }'

# Route to IVR Menu
curl -X PUT http://localhost/api/v1/phone-numbers/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "routing_type": "ivr_menu",
    "routing_config": {"ivr_menu_id": "3"}
  }'
```

### Outbound Whitelist

```bash
# Create whitelist entry for US calls via Trunk-1
curl -X POST http://localhost/api/v1/outbound-whitelist \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "US Calls",
    "destination_country": "US",
    "destination_prefix": "1212",
    "outbound_trunk_name": "Trunk-1"
  }'
```

### AI Assistant Load Balancers

```bash
# Create ALB with fallback
curl -X POST http://localhost/api/v1/ai-assistant-load-balancers \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Customer Service LB",
    "strategy": "round_robin",
    "fallback_action": "extension",
    "fallback_config": {"extension_id": "1"},
    "members": [
      {"ai_assistant_id": 1, "weight": 50},
      {"ai_assistant_id": 2, "weight": 50}
    ]
  }'
```

### Live Calls

```bash
# Get active calls
curl http://localhost/api/v1/session-updates/active \
  -H "Authorization: Bearer YOUR_TOKEN"

# Get active call statistics
curl http://localhost/api/v1/session-updates/active/stats \
  -H "Authorization: Bearer YOUR_TOKEN"

# Disconnect a session
curl -X DELETE http://localhost/api/v1/session-updates/12345/disconnect \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Key Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/extensions` | List extensions |
| `POST` | `/api/v1/extensions` | Create extension |
| `GET` | `/api/v1/ring-groups` | List ring groups |
| `POST` | `/api/v1/ring-groups` | Create ring group |
| `GET` | `/api/v1/business-hours` | List schedules |
| `POST` | `/api/v1/business-hours` | Create schedule |
| `GET` | `/api/v1/ivr-menus` | List IVR menus |
| `POST` | `/api/v1/ivr-menus` | Create IVR menu |
| `GET` | `/api/v1/phone-numbers` | List DIDs |
| `POST` | `/api/v1/phone-numbers` | Create DID |
| `GET` | `/api/v1/ai-assistants` | List AI assistants |
| `GET` | `/api/v1/ai-assistant-load-balancers` | List AI load balancers |
| `GET` | `/api/v1/auto-dialer-campaigns` | List auto-dialer campaigns |
| `POST` | `/api/v1/auto-dialer-campaigns` | Create campaign |
| `PATCH` | `/api/v1/auto-dialer-campaigns/{id}/start` | Start campaign |
| `PATCH` | `/api/v1/auto-dialer-campaigns/{id}/pause` | Pause campaign |
| `GET` | `/api/v1/auto-dialer-campaigns/monitor/summary` | Real-time monitor |
| `GET` | `/api/v1/auto-dialer-campaigns/lists` | List distribution lists |
| `GET` | `/api/v1/call-detail-records` | List CDR records |
| `GET` | `/api/v1/session-updates/active` | Active calls |
| `GET` | `/api/v1/settings/cloudonix` | Get Cloudonix settings |

For the complete REST API reference (162 endpoints), see the [OpenAPI specification](docs/opbx-openapi/openapi.yaml).

---

## Testing

### Run All Tests

```bash
./run-tests.sh                             # All tests (runs inside Docker)
./run-tests.sh --filter=TestClassName      # Single test class
```

### Frontend

```bash
cd frontend && npm run build               # Production build
cd frontend && npm run lint                # ESLint
cd frontend && npm run type-check          # TypeScript check
```

### Code Quality

```bash
vendor/bin/pint                            # PHP lint (PSR-12)
vendor/bin/pint --dirty                    # Lint changed files only
```

### Go Worker

```bash
cd dialer-worker && docker compose build dialer-worker   # Build
```

---

## Contributing

We welcome contributions from the community! Here's how to get started:

### Development Setup

1. Fork the repository
2. Clone your fork locally
3. Follow the [Installation](#installation) instructions
4. Create a feature branch: `git checkout -b feature/your-feature-name`

### Guidelines

- **Code Style**: Follow PSR-12 for PHP and ESLint/Prettier for TypeScript
- **Testing**: Add tests for new features and bug fixes
- **Documentation**: Update relevant docs for any changes
- **Commits**: Use conventional commit messages (`feat:`, `fix:`, `docs:`, etc.)

### Pull Request Process

1. Ensure all tests pass: `./run-tests.sh` (never run `php artisan test` directly in the container — it must go through `run-tests.sh`, which targets the `opbx_test` database and takes a pre-test backup)
2. Run code quality checks: `vendor/bin/pint` and frontend linting
3. Update `CHANGELOG.md` with your changes
4. Open a PR with a clear description of changes
5. Address any review feedback

### Reporting Issues

- Use GitHub Issues for bug reports and feature requests
- Include steps to reproduce for bugs
- Check existing issues before creating new ones

---

## Credits

### Created By

- **Nir Simionovich** ([@greenfieldtech-nirs](https://github.com/greenfieldtech-nirs)) - Lead Architect

### Created Using

- **Claude Code** ([@claudecode](https://claude.com/product/claude-code)) - Backend Developer
- **Grok Code Fast 1** ([@grokcode](https://x.ai/news/grok-code-fast-1)) - Frontend Developer
- **Google Gemini** ([@gemini](https://gemini.google.com/app)) - Code Reviewer and Expert Debugger
- **Google Antigravity** ([@antigravity](https://antigravity.google.com/app))
- **OpenCode** ([@opencode](https://opencode.ai/))

### Built With Support From

- [Cloudonix](https://cloudonix.com) - CPaaS platform powering all telephony
- [Laravel](https://laravel.com) - PHP application framework
- The open source community

### Special Thanks

- All contributors who have helped improve this project
- The Laravel, React, and Docker communities for their excellent documentation

---

## License

This project is licensed under the **MIT License**.

```
MIT License

Copyright (c) 2025-2026 Nir Simionovich / Greenfield Technologies Ltd.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## Documentation

### User & Admin Guides
Comprehensive Docusaurus-compatible documentation (30 pages):
- [User Guide](docs/opbx-userguide/) — Installation, modules, call routing, AI assistants, auto dialer, administration

### API Reference
- [OpenAPI 3.1.0 Specification](docs/opbx-openapi/openapi.yaml) — 162 endpoints, multi-file spec
- [REST API (online)](https://developers.cloudonix.com/opbxRestOpenAPI) — Interactive API explorer

### Architecture
- [Architecture Overview](docs/architecture/architecture-overview.md)
- [Security Implementation](docs/architecture/security-implementation.md)
- [Database Schema](docs/architecture/database-schema.md)
- [Docker Setup](docs/architecture/docker-setup.md)

### Module Documentation
- [Backend (Laravel)](app/) — API controllers, models, services, middleware
- [Frontend (React)](frontend/) — React SPA with TypeScript, TanStack Query, shadcn/ui
- [Dialer Worker (Go)](dialer-worker/) — Auto-dialer campaign execution microservice
- [AMD Worker (Java)](amd-worker/) — Stream-based voicemail detection microservice
- [Docker Infrastructure](docker/) — Docker Compose stack, Nginx, PHP-FPM configs
- [Tests](tests/) — PHPUnit test suite with feature, unit, and integration tests

### Feature Specifications
- [Auto Dialer Worker v2.0](docs/specifications/auto-dialer-worker-v2.md)
- [CPS Parameter](docs/specifications/auto-dialer-cps-parameter.md)
- [Real-Time Monitor](docs/specifications/auto-dialer-realtime-monitor.md)

---

## Recent Updates

### Auto Dialer & Real-Time Monitor (Latest)
- Auto Dialer campaign management with scheduling, AMD, and retry logic
- Go-based dialer worker with 10-second poll cycle and Redis CAC counters
- CPS (Calls Per Second) parameter for independent rate control (1-5 calls/sec)
- Caller ID pooling with multi-DID rotation (round-robin, random, LRU strategies)
- Real-time monitor with card-row campaign view, drill-down KPIs, and disposition pie charts
- Distribution list management with CSV upload, validation, and versioning
- Comprehensive documentation: 30-page user guide + OpenAPI spec (162 endpoints)

### AMD Worker (Answering Machine Detection)
- Java/Vert.x 5 microservice for stream-based voicemail detection
- ML-based beep detection (ONNX Runtime) + energy-based tone detection
- WebSocket audio streaming from Cloudonix `<Start><Stream>` CXML verb
- Automatic action execution (HANGUP, CONTINUE, URL redirect) based on detection result
- AMD results stored in Cloudonix session profile and displayed in Call Logs

### Call Notifications
- Webhook notifications for call events (initiated, answered, ended, etc.)
- Per-organization configuration with event filtering
- SSRF protection, rate limiting, and retry with exponential backoff
- Delivery audit logging with request/response capture

### UI/UX Improvements
- Campaign Manager with status toggle, archive workflow, and edit gating
- Distribution Lists page aligned with Campaign Manager layout
- Full timezone selector (65+ timezones grouped by region)
- Weekly calendar schedule with `expandHeight` for full visibility

### Bug Fixes & Reliability
- Redis key prefix mismatch resolved (prefix-free `dialer` connection)
- CDR session token path fixed (`session.token` not `session_token`)
- OrganizationScope bypass for webhook handlers
- Duplicate call prevention via `sync.Map` concurrency guard
- Stale session cleanup on campaign pause
- AMD mode mapping corrected (`Enabled` to `Enable`)
- WebSocket URL placeholder substitution fixed

<p align="center">
  Made with ❤️ by <a href="https://github.com/greenfieldtech-nirs">Greenfield Technologies</a>&nbsp;&nbsp;Empowered by <a href="https://developers.cloudonix.com">Cloudonix</a>
</p>
