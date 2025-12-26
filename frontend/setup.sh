#!/bin/bash

###############################################################################
# OPBX Frontend Setup Script
#
# This script installs dependencies and sets up the frontend development environment
###############################################################################

set -e

echo "========================================="
echo " OPBX Frontend Setup"
echo "========================================="
echo ""

# Check for Node.js
if ! command -v node &> /dev/null; then
    echo "ERROR: Node.js is not installed"
    echo "Please install Node.js 18+ from https://nodejs.org/"
    exit 1
fi

NODE_VERSION=$(node -v | cut -d 'v' -f 2 | cut -d '.' -f 1)
if [ "$NODE_VERSION" -lt 18 ]; then
    echo "ERROR: Node.js version 18+ is required (current: $(node -v))"
    exit 1
fi

echo "✓ Node.js $(node -v) detected"

# Check for npm
if ! command -v npm &> /dev/null; then
    echo "ERROR: npm is not installed"
    exit 1
fi

echo "✓ npm $(npm -v) detected"
echo ""

# Navigate to frontend directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "Installing dependencies..."
npm install

echo ""
echo "✓ Dependencies installed successfully"
echo ""

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "Creating .env file from .env.example..."
    cp .env.example .env
    echo "✓ Created .env file"
    echo ""
    echo "IMPORTANT: Please edit .env and configure:"
    echo "  - VITE_API_BASE_URL (backend API URL)"
    echo "  - VITE_WS_URL (WebSocket server URL)"
    echo ""
else
    echo "✓ .env file already exists"
    echo ""
fi

echo "========================================="
echo " Setup Complete!"
echo "========================================="
echo ""
echo "Next steps:"
echo ""
echo "  1. Configure environment variables:"
echo "     $ nano .env"
echo ""
echo "  2. Start development server:"
echo "     $ npm run dev"
echo ""
echo "  3. Open in browser:"
echo "     http://localhost:3000"
echo ""
echo "  4. Build for production:"
echo "     $ npm run build"
echo ""
echo "For more information, see README.md"
echo ""
