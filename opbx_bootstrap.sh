#!/bin/bash

################################################################################
# OPBX Bootstrap Script
#
# This script performs all pre-flight checks and setup required for first-time
# deployment of the OPBX application. Run this before 'docker compose up'.
#
# Usage:
#   ./opbx_bootstrap.sh
#
# What it does:
#   1. Verifies Docker and Docker Compose are installed
#   2. Creates .env file from .env.example if missing
#   3. Generates Laravel APP_KEY if not set
#   4. Creates missing config/broadcasting.php if needed
#   5. Sets proper permissions on storage and bootstrap/cache
#   6. Validates critical environment variables
#   7. Provides deployment guidance
################################################################################

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
print_header() {
    echo -e "\n${BLUE}═══════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

# Start bootstrap process
clear
echo -e "${GREEN}"
cat << "EOF"
   ___  ____  ______  __
  / _ \/ __ \/ _ \ \/ /
 / // / /_/ / ___/\  /
/____/ .___/_/    /_/
    /_/

Open Source Business PBX
Bootstrap Script v1.0
EOF
echo -e "${NC}"

print_header "Step 1: Checking Prerequisites"

# Check if Docker is installed
if command -v docker &> /dev/null; then
    DOCKER_VERSION=$(docker --version | cut -d ' ' -f3 | cut -d ',' -f1)
    print_success "Docker is installed (version $DOCKER_VERSION)"
else
    print_error "Docker is not installed"
    echo "Please install Docker from https://docs.docker.com/get-docker/"
    exit 1
fi

# Check if Docker Compose is installed
if docker compose version &> /dev/null; then
    COMPOSE_VERSION=$(docker compose version --short)
    print_success "Docker Compose is installed (version $COMPOSE_VERSION)"
elif command -v docker-compose &> /dev/null; then
    COMPOSE_VERSION=$(docker-compose --version | cut -d ' ' -f3 | cut -d ',' -f1)
    print_success "Docker Compose is installed (version $COMPOSE_VERSION)"
    print_warning "Using legacy docker-compose command. Consider upgrading to 'docker compose'"
else
    print_error "Docker Compose is not installed"
    echo "Please install Docker Compose from https://docs.docker.com/compose/install/"
    exit 1
fi

# Check if Docker daemon is running
if docker ps &> /dev/null; then
    print_success "Docker daemon is running"
else
    print_error "Docker daemon is not running"
    echo "Please start Docker and try again"
    exit 1
fi

# Check if we have required files
if [ ! -f "docker-compose.yml" ]; then
    print_error "docker-compose.yml not found. Are you in the OPBX root directory?"
    exit 1
fi
print_success "docker-compose.yml found"

print_header "Step 2: Environment Configuration"

# Check/Create .env file
if [ ! -f ".env" ]; then
    if [ ! -f ".env.example" ]; then
        print_error ".env.example file not found"
        exit 1
    fi

    print_info "Creating .env file from .env.example..."
    cp .env.example .env
    print_success ".env file created"
else
    print_success ".env file exists"
fi

# Check APP_KEY
APP_KEY=$(grep "^APP_KEY=" .env | cut -d '=' -f2-)

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
    print_info "APP_KEY is not set. Generating a new key..."

    # Generate a random 32-character key and base64 encode it
    RANDOM_KEY=$(openssl rand -base64 32 | tr -d '\n')

    # Update .env file
    if grep -q "^APP_KEY=" .env; then
        # Replace existing empty APP_KEY
        if [[ "$OSTYPE" == "darwin"* ]]; then
            sed -i '' "s|^APP_KEY=.*|APP_KEY=base64:$RANDOM_KEY|" .env
        else
            sed -i "s|^APP_KEY=.*|APP_KEY=base64:$RANDOM_KEY|" .env
        fi
    else
        # Add APP_KEY if it doesn't exist
        echo "APP_KEY=base64:$RANDOM_KEY" >> .env
    fi

    print_success "APP_KEY generated and saved to .env"
else
    print_success "APP_KEY is already set"
fi

print_header "Step 3: Configuration Files"

# Check/Create config/broadcasting.php
if [ ! -f "config/broadcasting.php" ]; then
    print_info "Creating missing config/broadcasting.php..."

    mkdir -p config

    cat > config/broadcasting.php << 'BROADCAST_EOF'
<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_DRIVER', env('BROADCAST_CONNECTION', 'pusher')),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    | For OPBX, we use Pusher protocol with Soketi (self-hosted WebSocket server)
    | for real-time call presence and updates.
    |
    */

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST', '127.0.0.1'),
                'port' => env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME', 'http'),
                'encrypted' => false,
                'useTLS' => env('PUSHER_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
BROADCAST_EOF

    print_success "config/broadcasting.php created"
else
    print_success "config/broadcasting.php exists"
fi

print_header "Step 4: Directory Permissions"

# Ensure storage and cache directories exist and are writable
DIRS_TO_CHECK=(
    "storage/app"
    "storage/framework/cache"
    "storage/framework/sessions"
    "storage/framework/views"
    "storage/logs"
    "bootstrap/cache"
)

for DIR in "${DIRS_TO_CHECK[@]}"; do
    if [ ! -d "$DIR" ]; then
        mkdir -p "$DIR"
        print_info "Created directory: $DIR"
    fi

    # Set permissions (777 is fine for Docker, as container user will own files)
    chmod -R 777 "$DIR" 2>/dev/null || true
done

print_success "Storage and cache directories are ready"

print_header "Step 5: Environment Validation"

# Check critical environment variables
REQUIRED_ENV_VARS=(
    "APP_NAME"
    "APP_KEY"
    "DB_DATABASE"
    "DB_USERNAME"
    "DB_PASSWORD"
)

MISSING_VARS=()

for VAR in "${REQUIRED_ENV_VARS[@]}"; do
    VALUE=$(grep "^$VAR=" .env | cut -d '=' -f2-)
    if [ -z "$VALUE" ]; then
        MISSING_VARS+=("$VAR")
    fi
done

if [ ${#MISSING_VARS[@]} -gt 0 ]; then
    print_warning "The following environment variables are not set:"
    for VAR in "${MISSING_VARS[@]}"; do
        echo "    - $VAR"
    done
    echo ""
    print_info "These will use default values, but you should review .env and set them appropriately"
else
    print_success "All required environment variables are set"
fi

# Check optional but important variables
print_info "Checking optional configuration..."

CLOUDONIX_TOKEN=$(grep "^CLOUDONIX_API_TOKEN=" .env | cut -d '=' -f2-)
if [ -z "$CLOUDONIX_TOKEN" ]; then
    print_warning "CLOUDONIX_API_TOKEN is not set (required for production PBX functionality)"
    echo "    Get your token from https://developers.cloudonix.com"
fi

WEBHOOK_URL=$(grep "^WEBHOOK_BASE_URL=" .env | cut -d '=' -f2-)
if [ "$WEBHOOK_URL" = "https://your-domain.com" ] || [ -z "$WEBHOOK_URL" ]; then
    print_warning "WEBHOOK_BASE_URL is not configured (required for production)"
    echo "    For local development, ngrok runs in Docker - see instructions below"
fi

print_header "Step 6: Pre-flight Summary"

print_success "All pre-flight checks passed!"
echo ""
print_info "Bootstrap complete. Your OPBX installation is ready to start."
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo ""
echo "  1. Review .env file and update any settings (especially Cloudonix API credentials)"
echo "  2. Start the application:"
echo ""
echo -e "     ${GREEN}docker compose up -d${NC}"
echo ""
echo "  3. Wait for services to become healthy (30-60 seconds)"
echo "  4. Access the application:"
echo ""
echo "     API:      http://localhost/api/v1"
echo "     Frontend: http://localhost:3000 (if built)"
echo "     Health:   http://localhost/up"
echo ""
echo "  5. Default admin credentials (auto-created on first run):"
echo ""
echo "     Email:    admin@example.com"
echo "     Password: password"
echo ""
echo -e "     ${RED}⚠ Change this password immediately after first login!${NC}"
echo ""
echo "  6. Monitor logs:"
echo ""
echo "     docker compose logs -f"
echo ""

print_header "Optional: Local Webhook Development"

echo "For local development with Cloudonix webhooks, ngrok is included in Docker"
echo "and will automatically expose your local server to the internet."
echo ""
echo "Steps to configure:"
echo ""
echo "  1. Ensure NGROK_AUTHTOKEN is set in your .env file"
echo "     Get your token from: https://dashboard.ngrok.com/get-started/your-authtoken"
echo ""
echo "  2. Start Docker services (ngrok will start automatically)"
echo "     docker compose up -d"
echo ""
echo "  3. Get your ngrok public URL:"
echo "     - Web UI: http://localhost:4040"
echo "     - API: curl -s http://localhost:4040/api/tunnels | jq -r '.tunnels[0].public_url'"
echo ""
echo "  4. Update WEBHOOK_BASE_URL in .env with the ngrok HTTPS URL"
echo ""
echo "  5. Restart Laravel app to pick up the new URL:"
echo "     docker compose restart app"
echo ""

print_success "Bootstrap script completed successfully!"
echo ""
