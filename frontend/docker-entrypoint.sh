#!/bin/sh
set -e

echo "🚀 Starting OPBX Frontend..."

# Check if node_modules exists and package.json has changed
if [ ! -d "node_modules" ] || [ ! -f "node_modules/.package-lock.json" ]; then
    echo "📦 No node_modules found. Installing dependencies..."
    npm install
elif [ "package.json" -nt "node_modules/.package-lock.json" ]; then
    echo "📦 package.json changed. Updating dependencies..."
    npm install
else
    echo "✅ Dependencies up to date."
fi

echo "🔥 Starting Vite dev server..."
exec "$@"
