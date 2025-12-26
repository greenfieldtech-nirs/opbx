#!/bin/bash

# WebSocket Setup Script
# Installs dependencies and configures WebSocket infrastructure

set -e

echo "========================================"
echo "OPBX WebSocket Setup"
echo "========================================"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if running from project root
if [ ! -f "composer.json" ]; then
    echo -e "${RED}Error: Must run from project root directory${NC}"
    exit 1
fi

echo "Step 1: Installing backend dependencies..."
echo "----------------------------------------"
if command -v composer &> /dev/null; then
    composer require pusher/pusher-php-server
    echo -e "${GREEN}✓ Backend dependencies installed${NC}"
else
    echo -e "${YELLOW}⚠ Composer not found. Please install manually:${NC}"
    echo "  composer require pusher/pusher-php-server"
fi
echo ""

echo "Step 2: Installing frontend dependencies..."
echo "----------------------------------------"
if command -v npm &> /dev/null; then
    cd frontend
    npm install laravel-echo pusher-js
    cd ..
    echo -e "${GREEN}✓ Frontend dependencies installed${NC}"
else
    echo -e "${YELLOW}⚠ npm not found. Please install manually:${NC}"
    echo "  cd frontend && npm install laravel-echo pusher-js"
fi
echo ""

echo "Step 3: Updating .env configuration..."
echo "----------------------------------------"
if [ ! -f ".env" ]; then
    echo -e "${YELLOW}⚠ .env not found. Copying from .env.example${NC}"
    cp .env.example .env
fi

# Check if WebSocket config exists
if grep -q "BROADCAST_DRIVER=pusher" .env; then
    echo -e "${GREEN}✓ WebSocket configuration already present${NC}"
else
    echo -e "${YELLOW}⚠ Adding WebSocket configuration to .env${NC}"
    cat >> .env << 'EOF'

# WebSocket / Soketi Configuration
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=pbxappkey
PUSHER_APP_SECRET=pbxappsecret
PUSHER_HOST=soketi
PUSHER_PORT=6001
PUSHER_SCHEME=http
PUSHER_APP_CLUSTER=mt1
SOKETI_PORT=6001
SOKETI_METRICS_PORT=9601
SOKETI_DEBUG=0

VITE_PUSHER_APP_KEY=pbxappkey
VITE_WS_HOST=localhost
VITE_WS_PORT=6001
VITE_WS_SCHEME=http
EOF
    echo -e "${GREEN}✓ WebSocket configuration added${NC}"
fi
echo ""

echo "Step 4: Starting Soketi WebSocket server..."
echo "----------------------------------------"
if command -v docker-compose &> /dev/null || command -v docker &> /dev/null; then
    docker-compose up -d soketi
    echo -e "${GREEN}✓ Soketi started${NC}"
    echo ""
    echo "Waiting for Soketi to be ready..."
    sleep 3

    # Check if Soketi is healthy
    if curl -s http://localhost:9601/ready > /dev/null 2>&1; then
        echo -e "${GREEN}✓ Soketi is ready and healthy${NC}"
    else
        echo -e "${YELLOW}⚠ Soketi may not be fully ready yet${NC}"
    fi
else
    echo -e "${YELLOW}⚠ Docker not found. Please start manually:${NC}"
    echo "  docker-compose up -d soketi"
fi
echo ""

echo "Step 5: Testing WebSocket connection..."
echo "----------------------------------------"
if curl -s http://localhost/api/v1/websocket/health > /dev/null 2>&1; then
    echo -e "${GREEN}✓ WebSocket health check passed${NC}"
else
    echo -e "${YELLOW}⚠ WebSocket health check failed (may need to start Laravel)${NC}"
fi
echo ""

echo "========================================"
echo "Setup Complete!"
echo "========================================"
echo ""
echo "Next steps:"
echo "1. Ensure Laravel app is running: docker-compose up -d"
echo "2. Ensure queue worker is running: docker-compose up -d queue-worker"
echo "3. Check WebSocket health: curl http://localhost/api/v1/websocket/health"
echo "4. Run tests: php artisan test --filter=CallPresenceTest"
echo ""
echo "Documentation:"
echo "- Full docs: REALTIME.md"
echo "- Implementation: WEBSOCKET_IMPLEMENTATION.md"
echo ""
echo "Soketi dashboard:"
echo "- WebSocket: ws://localhost:6001"
echo "- Metrics: http://localhost:9601/metrics"
echo "- Ready: http://localhost:9601/ready"
echo ""
echo -e "${GREEN}Happy coding!${NC}"
