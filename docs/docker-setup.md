# Docker Setup and Environment Configuration

## Overview

OpBX uses Docker Compose for complete containerized development and deployment. The setup includes all necessary services: Laravel application, React frontend, databases, caches, and development tools like ngrok for webhook tunneling.

## Docker Architecture

### Service Overview

```yaml
services:
  frontend       # React SPA (port 3000)
  nginx         # Web server with PHP-FPM (port 80)
  app           # Laravel application container
  queue-worker  # Laravel queue processing
  scheduler     # Laravel cron jobs
  mysql         # Database (port 3306)
  redis         # Cache/Queue (port 6379)
  minio         # File storage (ports 9000/9001)
  soketi        # WebSocket server (port 6001)
  ngrok         # Webhook tunneling (port 4040)
```

## Docker Compose Configuration

### Main docker-compose.yml

```yaml
version: '3.8'

services:
  # React Frontend
  frontend:
    build:
      context: ./frontend
      dockerfile: Dockerfile
    ports:
      - "3000:3000"
    volumes:
      - ./frontend:/app
      - /app/node_modules
    environment:
      - VITE_API_URL=http://localhost/api/v1
    depends_on:
      - nginx

  # Nginx Web Server
  nginx:
    build:
      context: .
      dockerfile: docker/nginx/Dockerfile
    ports:
      - "80:80"
    volumes:
      - .:/var/www/html
    depends_on:
      - app
      - mysql
      - redis

  # Laravel Application
  app:
    build:
      context: .
      dockerfile: docker/app/Dockerfile
    volumes:
      - .:/var/www/html
    environment:
      - APP_ENV=local
      - DB_HOST=mysql
      - REDIS_HOST=redis
    depends_on:
      - mysql
      - redis

  # Queue Worker
  queue-worker:
    build:
      context: .
      dockerfile: docker/app/Dockerfile
    command: php artisan queue:work --tries=3 --timeout=90
    volumes:
      - .:/var/www/html
    environment:
      - APP_ENV=local
      - DB_HOST=mysql
      - REDIS_HOST=redis
    depends_on:
      - mysql
      - redis
      - app

  # Scheduler (Cron Jobs)
  scheduler:
    build:
      context: .
      dockerfile: docker/app/Dockerfile
    command: /bin/bash -c "while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done"
    volumes:
      - .:/var/www/html
    environment:
      - APP_ENV=local
      - DB_HOST=mysql
      - REDIS_HOST=redis
    depends_on:
      - mysql
      - redis
      - app

  # MySQL Database
  mysql:
    image: mysql:8.0
    ports:
      - "3306:3306"
    environment:
      - MYSQL_ROOT_PASSWORD=rootpassword
      - MYSQL_DATABASE=opbx
      - MYSQL_USER=opbx
      - MYSQL_PASSWORD=password
    volumes:
      - mysql_data:/var/lib/mysql
      - ./docker/mysql/init.sql:/docker-entrypoint-initdb.d/init.sql
    command: --default-authentication-plugin=mysql_native_password

  # Redis Cache/Queue
  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    command: redis-server --appendonly yes

  # MinIO Object Storage
  minio:
    image: minio/minio
    ports:
      - "9000:9000"
      - "9001:9001"
    environment:
      - MINIO_ROOT_USER=minioadmin
      - MINIO_ROOT_PASSWORD=minioadmin
    volumes:
      - minio_data:/data
    command: server /data --console-address ":9001"

  # Soketi WebSocket Server
  soketi:
    image: quay.io/soketi/soketi:1.4
    ports:
      - "6001:6001"
      - "9601:9601"
    environment:
      - SOKETI_APP_ID=opbx
      - SOKETI_APP_KEY=opbx-key
      - SOKETI_APP_SECRET=opbx-secret
      - SOKETI_DEFAULT_APP_ENABLE_CLIENT_MESSAGES=false
      - SOKETI_DATABASE=redis
      - SOKETI_DATABASE_REDIS_HOST=redis
      - SOKETI_DATABASE_REDIS_PORT=6379

  # ngrok for Webhook Development
  ngrok:
    image: ngrok/ngrok:latest
    ports:
      - "4040:4040"
    environment:
      - NGROK_AUTHTOKEN=${NGROK_AUTHTOKEN}
    command: http nginx:80 --log=stdout

volumes:
  mysql_data:
  redis_data:
  minio_data:
```

## Dockerfile Configurations

### Frontend Dockerfile

```dockerfile
FROM node:18-alpine

WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm ci

# Copy source code
COPY . .

# Build application
RUN npm run build

# Expose port
EXPOSE 3000

# Start development server
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
```

### Nginx Dockerfile

```dockerfile
FROM nginx:alpine

# Copy nginx configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy SSL certificates (if needed)
# COPY docker/nginx/ssl /etc/nginx/ssl

# Create log directory
RUN mkdir -p /var/log/nginx

# Expose ports
EXPOSE 80 443

CMD ["nginx", "-g", "daemon off;"]
```

### Laravel App Dockerfile

```dockerfile
FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    mysql-client \
    redis

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application code
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

# Expose port
EXPOSE 9000

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
```

## Environment Configuration

### .env.example

```bash
# Application
APP_NAME=OpBX
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=opbx
DB_USERNAME=opbx
DB_PASSWORD=password

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0

# Cache
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Broadcasting
BROADCAST_DRIVER=redis
PUSHER_APP_ID=opbx
PUSHER_APP_KEY=opbx-key
PUSHER_APP_SECRET=opbx-secret
PUSHER_HOST=soketi
PUSHER_PORT=6001
PUSHER_SCHEME=http

# Cloudonix API
CLOUDONIX_API_BASE_URL=https://api.cloudonix.io
CLOUDONIX_API_TOKEN=
CLOUDONIX_WEBHOOK_SECRET=

# File Storage
FILESYSTEM_DISK=minio
MINIO_ENDPOINT=http://minio:9000
MINIO_ACCESS_KEY=minioadmin
MINIO_SECRET_KEY=minioadmin
MINIO_BUCKET=opbx

# ngrok (Development)
NGROK_AUTHTOKEN=

# Mail Configuration (Optional)
MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

# Logging
LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# Vite (Frontend)
VITE_APP_NAME="${APP_NAME}"
VITE_API_URL=http://localhost/api/v1
```

## Development Setup

### Prerequisites

- Docker Desktop or Docker Engine
- Docker Compose v2.0+
- At least 4GB RAM available
- ngrok account (for webhook development)

### Quick Start

1. **Clone the repository**
```bash
git clone https://github.com/your-org/opbx.git
cd opbx
```

2. **Create environment file**
```bash
cp .env.example .env
```

3. **Set up ngrok (optional but recommended)**
```bash
# Get your authtoken from https://ngrok.com
# Add it to .env:
echo "NGROK_AUTHTOKEN=your_ngrok_authtoken_here" >> .env
```

4. **Start all services**
```bash
docker compose up -d
```

5. **Run initial setup**
```bash
# Generate application key
docker compose exec app php artisan key:generate

# Run database migrations
docker compose exec app php artisan migrate

# Seed initial data (optional)
docker compose exec app php artisan db:seed

# Install frontend dependencies
docker compose exec frontend npm install

# Build frontend assets
docker compose exec frontend npm run build
```

### Access Points

- **Frontend**: http://localhost:3000
- **API**: http://localhost/api/v1
- **ngrok Web Interface**: http://localhost:4040
- **MinIO Console**: http://localhost:9001 (minioadmin/minioadmin)
- **MySQL**: localhost:3306 (opbx/password)
- **Redis**: localhost:6379
- **Soketi**: localhost:6001

## Webhook Development with ngrok

### ngrok Configuration

1. **Install ngrok CLI** (optional, already included in Docker)
```bash
# Or use the Docker container
docker compose exec ngrok ngrok http nginx:80
```

2. **Get the public URL**
Visit http://localhost:4040 to see your ngrok URL:
```
https://abc123.ngrok.io
```

3. **Update Cloudonix webhook URLs**
In your Cloudonix portal, configure these webhook URLs:
- Voice Webhook: `https://abc123.ngrok.io/webhooks/cloudonix/call-initiated`
- Status Webhook: `https://abc123.ngrok.io/webhooks/cloudonix/call-status`
- CDR Webhook: `https://abc123.ngrok.io/webhooks/cloudonix/cdr`

4. **Environment variable**
Update your `.env` file:
```bash
WEBHOOK_BASE_URL=https://abc123.ngrok.io
```

### Testing Webhooks

1. **Check ngrok logs**
```bash
docker compose logs ngrok
```

2. **Verify webhook delivery**
```bash
# Check Laravel logs
docker compose logs app

# Or tail the log file
docker compose exec app tail -f storage/logs/laravel.log
```

3. **Test with Cloudonix**
Make a test call to your DID number to trigger webhooks.

## Production Deployment

### Production docker-compose.prod.yml

```yaml
version: '3.8'

services:
  # Same services but with production optimizations
  nginx:
    image: opbx/nginx:latest
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx/ssl:/etc/nginx/ssl:ro
    environment:
      - NGINX_ENVSUBST_TEMPLATE_DIR=/etc/nginx/templates
      - NGINX_ENVSUBST_OUTPUT_DIR=/etc/nginx/conf.d

  app:
    image: opbx/app:latest
    environment:
      - APP_ENV=production
      - APP_KEY=${APP_KEY}
      - DB_HOST=${DB_HOST}
      - REDIS_HOST=${REDIS_HOST}
    secrets:
      - db_password
      - redis_password
      - cloudonix_token

  # Add SSL termination, load balancer, etc.
```

### Production Considerations

1. **SSL/TLS Configuration**
```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;

    # SSL security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options DENY always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
```

2. **Environment Variables**
- Use Docker secrets for sensitive data
- External configuration management (AWS Systems Manager, etc.)
- Separate environments (staging, production)

3. **Scaling**
- Multiple app containers behind load balancer
- Redis cluster for high availability
- Database read replicas

4. **Monitoring**
- Health check endpoints
- Application performance monitoring (APM)
- Log aggregation (ELK stack, etc.)

## Troubleshooting

### Common Issues

1. **Port conflicts**
```bash
# Check what's using ports
lsof -i :3000
lsof -i :80

# Change ports in docker-compose.yml
ports:
  - "3001:3000"  # Change host port
```

2. **Database connection issues**
```bash
# Check MySQL container
docker compose logs mysql

# Test connection
docker compose exec mysql mysql -u opbx -p opbx
```

3. **Redis connection issues**
```bash
# Check Redis
docker compose exec redis redis-cli ping

# Clear Redis data
docker compose exec redis redis-cli FLUSHALL
```

4. **Permission issues**
```bash
# Fix Laravel permissions
docker compose exec app chown -R www-data:www-data /var/www/html/storage
docker compose exec app chmod -R 755 /var/www/html/storage
```

5. **ngrok connection issues**
```bash
# Check ngrok status
docker compose logs ngrok

# Restart ngrok
docker compose restart ngrok
```

### Logs and Debugging

```bash
# View all logs
docker compose logs

# Follow specific service logs
docker compose logs -f app

# View last 100 lines
docker compose logs --tail=100 app

# Laravel application logs
docker compose exec app tail -f storage/logs/laravel.log

# PHP-FPM logs
docker compose exec app tail -f /var/log/php8.4-fpm.log
```

### Performance Monitoring

```bash
# Check container resource usage
docker stats

# MySQL performance
docker compose exec mysql mysql -u opbx -p -e "SHOW PROCESSLIST;"

# Redis info
docker compose exec redis redis-cli INFO

# Laravel cache/horizon metrics
docker compose exec app php artisan tinker
```

This Docker setup provides a complete development and production environment for the OpBX application with proper service orchestration, development tools, and production-ready configurations.