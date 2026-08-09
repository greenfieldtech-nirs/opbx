# Multi-stage Dockerfile for React Frontend

# Stage 1: Build the application
FROM node:20-alpine AS builder

WORKDIR /app

# Copy package files
COPY package.json package-lock.json* ./

# Install dependencies
RUN npm ci --silent

# Copy source code
COPY . .

# Vite inlines VITE_* variables at build time, so they must be provided as
# build args (runtime env vars have no effect on an already-built static SPA).
# Defaults keep same-origin/relative behaviour that works behind the reverse
# proxy; override per-environment via docker-compose build args.
ARG VITE_API_BASE_URL=/api/v1
ARG VITE_PUSHER_APP_KEY=pbxappkey
ARG VITE_PUSHER_APP_CLUSTER=mt1
ARG VITE_WS_HOST=localhost
ARG VITE_WS_PORT=443
ARG VITE_WS_SCHEME=https

ENV VITE_API_BASE_URL=${VITE_API_BASE_URL} \
    VITE_PUSHER_APP_KEY=${VITE_PUSHER_APP_KEY} \
    VITE_PUSHER_APP_CLUSTER=${VITE_PUSHER_APP_CLUSTER} \
    VITE_WS_HOST=${VITE_WS_HOST} \
    VITE_WS_PORT=${VITE_WS_PORT} \
    VITE_WS_SCHEME=${VITE_WS_SCHEME}

# Build the application (tsc + vite build)
RUN npm run build

# Stage 2: Serve with nginx
FROM nginx:alpine

# Copy custom nginx configuration
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

# Copy built files from builder stage
COPY --from=builder /app/dist /usr/share/nginx/html

# Add healthcheck
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget --no-verbose --tries=1 --spider http://localhost/ || exit 1

# Expose port
EXPOSE 80

# Start nginx
CMD ["nginx", "-g", "daemon off;"]
