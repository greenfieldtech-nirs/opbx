# syntax=docker/dockerfile:1

# ---- Build stage ----
FROM node:24-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY tsconfig.json tsconfig.build.json ./
COPY src ./src
RUN npm run build && npm prune --omit=dev

# ---- Runtime stage ----
FROM node:24-alpine AS runtime
ENV NODE_ENV=production
WORKDIR /app

RUN addgroup -S opbx && adduser -S opbx -G opbx

COPY --from=build --chown=opbx:opbx /app/node_modules ./node_modules
COPY --from=build --chown=opbx:opbx /app/dist ./dist
COPY --from=build --chown=opbx:opbx /app/package.json ./

USER opbx
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8080/health >/dev/null 2>&1 || exit 1

# Node handles SIGTERM directly; index.ts closes Fastify + OTel gracefully.
CMD ["node", "dist/index.js"]
