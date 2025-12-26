# Deployment Guide

This guide covers deployment of the OPBX application to production environments.

## Production Environment Requirements

- **Operating System**: Ubuntu 22.04 LTS or similar Linux distribution
- **Docker**: 24.0+ with Docker Compose V2
- **Memory**: Minimum 2GB RAM (4GB+ recommended)
- **CPU**: 2+ cores recommended
- **Disk Space**: 20GB+ free space
- **Network**: Static IP or domain name with SSL certificate
- **Cloudonix Account**: Active CPaaS account with API token

## Pre-Deployment Checklist

### 1. Domain & SSL

Set up your domain and SSL certificate:

```bash
# Using Let's Encrypt with Certbot
sudo apt-get update
sudo apt-get install certbot python3-certbot-nginx

# Generate certificate
sudo certbot certonly --standalone -d your-domain.com
```

### 2. Server Setup

Install Docker and Docker Compose:

```bash
# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER

# Install Docker Compose
sudo apt-get install docker-compose-plugin

# Verify installation
docker --version
docker compose version
```

### 3. Clone Repository

```bash
cd /opt
sudo git clone <repository-url> opbx
cd opbx
sudo chown -R $USER:$USER .
```

## Production Configuration

### 1. Environment Variables

Copy and configure the production environment file:

```bash
cp .env.example .env
nano .env
```

Critical production settings:

```env
# Application
APP_NAME="OPBX - Business PBX"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_PORT=443

# Generate a secure key
APP_KEY=base64:RANDOM_32_CHARACTER_STRING

# Database - Use strong passwords
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=opbx_prod
DB_USERNAME=opbx_prod
DB_PASSWORD=GENERATE_SECURE_PASSWORD
DB_ROOT_PASSWORD=GENERATE_SECURE_ROOT_PASSWORD

# Redis - Set a strong password
REDIS_HOST=redis
REDIS_PASSWORD=GENERATE_SECURE_PASSWORD
REDIS_PORT=6379

# Cloudonix
CLOUDONIX_API_TOKEN=your_production_api_token
WEBHOOK_BASE_URL=https://your-domain.com

# Cache & Queue
CACHE_STORE=redis
QUEUE_CONNECTION=redis
BROADCAST_DRIVER=redis
```

Generate secure passwords:

```bash
# Generate APP_KEY
php artisan key:generate --show

# Generate random passwords
openssl rand -base64 32
```

### 2. Docker Compose for Production

Create a production docker-compose override:

```bash
nano docker-compose.prod.yml
```

```yaml
version: '3.8'

services:
  nginx:
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /etc/letsencrypt:/etc/letsencrypt:ro
      - ./docker/nginx/nginx-ssl.conf:/etc/nginx/nginx.conf
      - ./docker/nginx/conf.d:/etc/nginx/conf.d
    restart: always

  app:
    restart: always
    environment:
      - APP_ENV=production

  queue-worker:
    restart: always
    deploy:
      replicas: 3

  scheduler:
    restart: always

  mysql:
    restart: always
    volumes:
      - mysql_data:/var/lib/mysql
      - ./backups/mysql:/backups

  redis:
    restart: always
    command: redis-server --appendonly yes --requirepass ${REDIS_PASSWORD}
```

### 3. Nginx SSL Configuration

Create SSL-enabled nginx config:

```bash
nano docker/nginx/nginx-ssl.conf
```

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/html/public;
    index index.php;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # ... rest of nginx config from docker/nginx/conf.d/default.conf
}
```

## Deployment Steps

### 1. Initial Deployment

```bash
# Set production environment
export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml

# Build and start containers
docker compose up -d --build

# Verify all services are running
docker compose ps

# Check logs
docker compose logs -f
```

### 2. Database Migration

Migrations run automatically on startup, but you can verify:

```bash
docker compose exec app php artisan migrate:status
```

### 3. Cache Optimization

```bash
# Cache configuration, routes, and views
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
```

### 4. Create First Organization

```bash
docker compose exec app php artisan tinker

# In tinker:
$org = App\Models\Organization::create([
    'name' => 'Your Company Name',
    'slug' => 'your-company',
    'status' => 'active',
    'timezone' => 'America/New_York',
]);

$user = App\Models\User::create([
    'organization_id' => $org->id,
    'name' => 'Admin User',
    'email' => 'admin@your-company.com',
    'password' => bcrypt('SECURE_PASSWORD'),
    'role' => 'owner',
    'status' => 'active',
]);

exit
```

### 5. Configure Cloudonix Webhooks

In your Cloudonix portal, set webhook URLs:

- **Call Initiated**: `https://your-domain.com/api/webhooks/cloudonix/call-initiated`
- **Call Status**: `https://your-domain.com/api/webhooks/cloudonix/call-status`
- **CDR**: `https://your-domain.com/api/webhooks/cloudonix/cdr`

### 6. Test the Deployment

```bash
# Health check
curl https://your-domain.com/health

# API login test
curl -X POST https://your-domain.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@your-company.com","password":"SECURE_PASSWORD"}'
```

## Monitoring & Maintenance

### Health Checks

Set up monitoring for:

- **Application Health**: `GET /health`
- **Queue Workers**: `docker compose ps queue-worker`
- **Disk Space**: `df -h`
- **Memory Usage**: `free -h`
- **Docker Stats**: `docker stats`

### Logging

View logs:

```bash
# All services
docker compose logs -f

# Specific service
docker compose logs -f app
docker compose logs -f queue-worker

# Laravel logs
docker compose exec app tail -f storage/logs/laravel.log
```

### Backups

#### Database Backups

```bash
# Create backup directory
mkdir -p backups/mysql

# Automated daily backup script
cat > backup-mysql.sh << 'EOF'
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/opt/opbx/backups/mysql"
docker compose exec -T mysql mysqldump -uroot -p$DB_ROOT_PASSWORD opbx_prod > $BACKUP_DIR/opbx_$DATE.sql
gzip $BACKUP_DIR/opbx_$DATE.sql
# Keep only last 30 days
find $BACKUP_DIR -name "*.sql.gz" -mtime +30 -delete
EOF

chmod +x backup-mysql.sh

# Add to crontab (daily at 2 AM)
(crontab -l 2>/dev/null; echo "0 2 * * * /opt/opbx/backup-mysql.sh") | crontab -
```

#### Redis Backups

Redis persistence is enabled by default with AOF (Append-Only File).

### Updates & Rollbacks

```bash
# Pull latest code
git pull origin main

# Rebuild containers
docker compose up -d --build

# Run migrations
docker compose exec app php artisan migrate --force

# Clear cache
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

#### Rollback

```bash
# Rollback to previous version
git checkout <previous-commit-hash>
docker compose up -d --build
docker compose exec app php artisan migrate:rollback
```

## Scaling

### Horizontal Scaling

Increase queue worker replicas:

```yaml
# In docker-compose.prod.yml
queue-worker:
  deploy:
    replicas: 5  # Increase for higher load
```

### Load Balancing

For high availability, deploy multiple app instances behind a load balancer:

1. Set up multiple servers with identical configuration
2. Use external MySQL and Redis (managed services recommended)
3. Configure nginx or HAProxy as load balancer
4. Use shared storage for Laravel sessions and uploads

## Security Hardening

### Firewall Configuration

```bash
# Allow only necessary ports
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### Regular Updates

```bash
# System updates
sudo apt-get update && sudo apt-get upgrade -y

# Docker images
docker compose pull
docker compose up -d
```

### SSL Certificate Renewal

```bash
# Certbot auto-renewal (runs twice daily)
sudo certbot renew --dry-run

# Manual renewal
sudo certbot renew
docker compose restart nginx
```

## Troubleshooting

### Container Issues

```bash
# Restart all services
docker compose restart

# Restart specific service
docker compose restart app

# Rebuild if code changes
docker compose up -d --build
```

### Database Connection Issues

```bash
# Check MySQL is running
docker compose exec mysql mysqladmin ping

# Check database connection from app
docker compose exec app php artisan db:show
```

### Queue Worker Issues

```bash
# Check queue worker status
docker compose ps queue-worker

# Restart queue workers
docker compose restart queue-worker

# Check for failed jobs
docker compose exec app php artisan queue:failed
```

### Redis Connection Issues

```bash
# Test Redis connection
docker compose exec redis redis-cli -a $REDIS_PASSWORD ping

# Clear Redis cache
docker compose exec app php artisan cache:clear
```

## Performance Tuning

### OpCache Configuration

Already optimized in `docker/php/opcache.ini`. Monitor with:

```bash
docker compose exec app php -i | grep opcache
```

### MySQL Optimization

For high traffic, consider increasing MySQL resources in docker-compose.yml:

```yaml
mysql:
  command: >
    --max_connections=500
    --innodb_buffer_pool_size=1G
    --query_cache_size=0
```

### Redis Memory

Monitor Redis memory:

```bash
docker compose exec redis redis-cli -a $REDIS_PASSWORD INFO memory
```

## Support

For production support:
- Check logs first: `docker compose logs -f`
- Review [README.md](README.md) for common issues
- Contact Cloudonix support for platform issues
- GitHub Issues for application bugs
