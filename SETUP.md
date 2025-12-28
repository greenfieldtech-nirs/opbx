# OPBX Setup Guide

## Quick Start

### 1. Clone the Repository

```bash
git clone <repository-url>
cd opbx.cloudonix.com
```

### 2. Configure Environment

```bash
cp .env.example .env
```

Edit `.env` if needed. The defaults should work for local development.

### 3. Start Docker Containers

```bash
docker compose up -d
```

This will start all required services:
- MySQL database
- Redis cache
- PHP-FPM application
- Nginx web server
- Queue worker
- Scheduler
- WebSocket server (Soketi)
- React frontend

### 4. Install Dependencies (First Time Only)

After starting containers for the first time, install PHP dependencies:

```bash
docker exec -u root opbx_app composer install --no-interaction --prefer-dist
docker exec -u root opbx_app chown -R www-data:www-data /var/www/html
```

### 5. Run Database Migrations and Seeders

```bash
docker exec opbx_app php artisan migrate --force
docker exec opbx_app php artisan db:seed --force
```

### 6. Generate Application Key (if not set)

```bash
docker exec opbx_app php artisan key:generate
```

## Default Admin Credentials

After running the seeder, you can login with:

- **Email**: `admin@example.com`
- **Password**: `password`

**⚠️ IMPORTANT**: Change the password immediately after first login!

## Accessing the Application

- **Frontend**: http://localhost:3000
- **API**: http://localhost/api
- **Backend Admin**: http://localhost

## Troubleshooting

### 502 Bad Gateway Error

If you see a 502 error:

1. Check if PHP-FPM is running:
   ```bash
   docker exec opbx_app ps aux | grep php-fpm
   ```

2. Verify database connection:
   ```bash
   docker exec opbx_app php artisan db:show
   ```

3. Check logs:
   ```bash
   docker compose logs app
   docker compose logs nginx
   ```

### Migration Failures

If migrations fail:

1. Reset the database:
   ```bash
   docker exec opbx_app php artisan migrate:fresh --force
   docker exec opbx_app php artisan db:seed --force
   ```

### Missing Vendor Directory

If you get "vendor/autoload.php not found" errors:

```bash
docker exec -u root opbx_app composer install --no-interaction --prefer-dist
docker exec -u root opbx_app chown -R www-data:www-data /var/www/html
docker compose restart app
```

## Development Workflow

### Running Migrations

```bash
docker exec opbx_app php artisan migrate
```

### Creating a New Migration

```bash
docker exec opbx_app php artisan make:migration create_example_table
```

### Running Tests

```bash
docker exec opbx_app php artisan test
```

### Accessing MySQL

```bash
docker exec -it opbx_mysql mysql -u opbx -psecret opbx
```

### Accessing Redis CLI

```bash
docker exec -it opbx_redis redis-cli
```

### Clearing Caches

```bash
docker exec opbx_app php artisan cache:clear
docker exec opbx_app php artisan config:clear
docker exec opbx_app php artisan view:clear
```

## Production Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for production deployment instructions.

## Additional Documentation

- [CLAUDE.md](CLAUDE.md) - Project architecture and development guidelines
- [CORE_ROUTING_SPECIFICATION.md](CORE_ROUTING_SPECIFICATION.md) - Call routing system specification
- [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) - UI design system and components
