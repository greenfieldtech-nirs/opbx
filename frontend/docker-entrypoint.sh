#!/bin/sh
set -e

echo "🚀 Starting OPBX Frontend..."

# npm ci installs exactly what package-lock.json pins and never rewrites it
# (./frontend is bind-mounted: npm install here rewrote the host lockfile).
if [ ! -d "node_modules" ] || [ ! -f "node_modules/.package-lock.json" ]; then
    echo "📦 No node_modules found. Installing dependencies..."
    npm ci
elif [ "package.json" -nt "node_modules/.package-lock.json" ] || [ "package-lock.json" -nt "node_modules/.package-lock.json" ]; then
    echo "📦 package.json or package-lock.json changed. Reinstalling dependencies..."
    npm ci
else
    echo "✅ Dependencies up to date."
fi

echo "🔥 Starting Vite dev server..."
exec "$@"
