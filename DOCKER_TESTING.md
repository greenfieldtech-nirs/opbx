# OPBX Docker Testing Guide

This guide explains how to test the OPBX application (backend + frontend) using Docker Compose.

## Prerequisites

- Docker Desktop (Mac/Windows) or Docker Engine (Linux)
- Docker Compose v2.0+
- At least 4GB RAM available for Docker
- Ports 80, 3000, 3306, 6001, 6379 available on your host

## Quick Start

### 1. Clone and Setup

```bash
cd /Users/nirs/Documents/repos/opbx.cloudonix.com

# Copy environment file
cp .env.example .env

# Generate Laravel application key (if not done already)
# docker-compose run --rm app php artisan key:generate
```

### 2. Configure Environment

Edit `.env` and set your Cloudonix API token:

```bash
CLOUDONIX_API_TOKEN=your_actual_token_here
```

All other defaults should work for local Docker testing.

### 3. Start the Stack

```bash
# Build and start all services
docker-compose up -d

# Check status
docker-compose ps
```

Expected services:
- ✅ `opbx_frontend` - React dev server (port 3000)
- ✅ `opbx_nginx` - Nginx reverse proxy (port 80)
- ✅ `opbx_app` - Laravel PHP-FPM
- ✅ `opbx_queue_worker` - Laravel queue worker
- ✅ `opbx_scheduler` - Laravel task scheduler
- ✅ `opbx_mysql` - MySQL 8.0 database (port 3306)
- ✅ `opbx_redis` - Redis cache/queue (port 6379)
- ✅ `opbx_websocket` - Soketi WebSocket server (port 6001)

### 4. Initialize Database

```bash
# Run migrations
docker-compose exec app php artisan migrate

# (Optional) Seed test data
docker-compose exec app php artisan db:seed
```

### 5. Access the Application

**Frontend (React UI):**
- URL: http://localhost:3000
- Direct access to Vite dev server
- Hot-reload enabled for development

**Backend API:**
- URL: http://localhost/api/v1
- Proxied through nginx

**WebSocket:**
- URL: ws://localhost:6001
- Soketi server for real-time updates

## Testing the Frontend

### 1. Verify Frontend is Running

```bash
# Check frontend container logs
docker-compose logs -f frontend

# You should see:
# VITE v5.x.x ready in XXX ms
# ➜ Local:   http://localhost:3000/
```

### 2. Access the Login Page

Open your browser to: http://localhost:3000

You should see:
- OPBX login page
- Email and password fields
- Clean, modern UI with shadcn/ui components

### 3. Test Authentication

If you've seeded the database:

```bash
# Create a test user manually
docker-compose exec app php artisan tinker

# In tinker:
$org = \App\Models\Organization::create(['name' => 'Test Org', 'status' => 'active', 'timezone' => 'UTC']);
$user = \App\Models\User::create([
    'organization_id' => $org->id,
    'name' => 'Test Admin',
    'email' => 'admin@test.com',
    'password' => bcrypt('password'),
    'role' => 'owner',
    'status' => 'active'
]);
```

Then login with:
- Email: `admin@test.com`
- Password: `password`

### 4. Navigate the UI

After login, test these pages:
- ✅ Dashboard - Overview and stats
- ✅ Users - User management
- ✅ Extensions - Extension configuration
- ✅ DIDs - Phone number routing
- ✅ Ring Groups - Group calling
- ✅ Business Hours - Time-based routing
- ✅ Call Logs - Call history
- ✅ Live Calls - Real-time call monitoring

### 5. Test Hot Reload

The frontend has hot-reload enabled:

1. Edit any file in `/frontend/src/`
2. Save the file
3. Browser automatically refreshes
4. Changes appear immediately

Example:
```bash
# Edit a component
vim frontend/src/pages/Dashboard.tsx

# Save and watch the browser auto-refresh
```

## Service Status Checks

### Check All Services

```bash
docker-compose ps
```

All services should show "Up" status.

### Check Logs

```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f frontend
docker-compose logs -f app
docker-compose logs -f nginx
docker-compose logs -f soketi
```

### Health Checks

```bash
# Frontend health
curl http://localhost:3000

# Backend API health
curl http://localhost/api/v1/health

# WebSocket health
curl http://localhost:9601/ready
```

## Troubleshooting

### Frontend Won't Start

```bash
# Check frontend logs
docker-compose logs frontend

# Rebuild frontend container
docker-compose build --no-cache frontend
docker-compose up -d frontend
```

### Can't Connect to Backend API

1. Check nginx is running:
   ```bash
   docker-compose ps nginx
   ```

2. Check nginx logs:
   ```bash
   docker-compose logs nginx
   ```

3. Verify backend is responding:
   ```bash
   docker-compose exec app php artisan route:list
   ```

### Database Connection Errors

```bash
# Check MySQL is running
docker-compose ps mysql

# Check MySQL logs
docker-compose logs mysql

# Test connection
docker-compose exec app php artisan tinker
# In tinker: DB::connection()->getPdo();
```

### WebSocket Not Connecting

```bash
# Check Soketi is running
docker-compose ps soketi

# Check Soketi logs
docker-compose logs soketi

# Verify port 6001 is accessible
curl http://localhost:6001
```

### Port Conflicts

If ports are already in use, edit `.env`:

```bash
# Change default ports
FRONTEND_PORT=3001
APP_PORT=8080
DB_PORT=3307
REDIS_PORT=6380
SOKETI_PORT=6002
```

Then restart:
```bash
docker-compose down
docker-compose up -d
```

## Development Workflow

### Making Frontend Changes

1. Edit files in `/frontend/src/`
2. Changes hot-reload automatically
3. Check browser for updates
4. Check console for errors

### Making Backend Changes

1. Edit files in `/app/` or `/routes/`
2. Changes are reflected immediately (no rebuild needed)
3. For config changes, clear cache:
   ```bash
   docker-compose exec app php artisan config:clear
   ```

### Installing Frontend Dependencies

```bash
# Add a new npm package
docker-compose exec frontend npm install <package-name>

# Or rebuild with new packages
docker-compose build --no-cache frontend
docker-compose up -d frontend
```

### Installing Backend Dependencies

```bash
# Add a new composer package
docker-compose exec app composer require <package-name>
```

### Running Tests

```bash
# Backend tests
docker-compose exec app php artisan test

# Frontend tests (if configured)
docker-compose exec frontend npm test
```

## Stopping the Stack

```bash
# Stop all services (keeps data)
docker-compose stop

# Stop and remove containers (keeps data volumes)
docker-compose down

# Stop, remove containers, and delete data
docker-compose down -v
```

## Performance Tips

### Speed Up Frontend Hot Reload

The frontend uses volume mounts which can be slow on Mac/Windows. For better performance:

1. Ensure Docker Desktop uses VirtioFS (Settings > General > VirtioFS)
2. Or use `:cached` mount option (already configured)

### Reduce Docker Memory Usage

Edit `docker-compose.yml` to add memory limits:

```yaml
services:
  frontend:
    mem_limit: 1g
  app:
    mem_limit: 512m
```

## Useful Commands Reference

```bash
# View running containers
docker-compose ps

# View all logs
docker-compose logs -f

# View specific service logs
docker-compose logs -f frontend

# Execute command in container
docker-compose exec frontend sh
docker-compose exec app bash

# Restart a service
docker-compose restart frontend

# Rebuild a service
docker-compose build --no-cache frontend

# Scale queue workers (run 3 workers)
docker-compose up -d --scale queue-worker=3

# Check resource usage
docker stats
```

## Next Steps

After verifying the Docker stack works:

1. **Complete Service Files**: Implement remaining 6 service files in `/frontend/src/services/`
2. **Test CRUD Operations**: Create/edit/delete users, extensions, DIDs
3. **Test Real-time Features**: Make a test call and watch Live Calls page
4. **Configure Cloudonix**: Set up webhook URLs and test inbound call routing
5. **Production Build**: Use `Dockerfile` instead of `Dockerfile.dev` for production

## Additional Resources

- **Frontend Setup**: `/frontend/SETUP_INSTRUCTIONS.md`
- **Service Interface**: `/SERVICE_INTERFACE.md`
- **Backend Docs**: `/README.md`
- **Frontend Docs**: `/frontend/README.md`
- **Cloudonix Docs**: https://developers.cloudonix.com/

## Support

If you encounter issues:

1. Check logs: `docker-compose logs -f`
2. Verify all services are up: `docker-compose ps`
3. Review this guide's Troubleshooting section
4. Check GitHub issues (if open source repo)
