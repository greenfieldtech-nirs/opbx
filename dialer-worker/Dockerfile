# Build stage
FROM golang:1.23-alpine AS builder

# Install build dependencies
RUN apk add --no-cache git ca-certificates tzdata

# Set working directory
WORKDIR /build

# Copy go mod files
COPY go.mod go.sum ./

# Download dependencies
RUN go mod download

# Copy source code
COPY . .

# Build the application
RUN CGO_ENABLED=0 GOOS=linux go build -a -installsuffix cgo -o worker \
    -ldflags="-w -s -X main.version=$(git describe --tags --always || echo 'dev')" \
    ./cmd/worker

# Final stage
FROM alpine:3.19

# Install runtime dependencies
RUN apk add --no-cache ca-certificates tzdata

# Create non-root user
RUN addgroup -g 1000 -S dialer && \
    adduser -u 1000 -S dialer -G dialer

# Set working directory
WORKDIR /app

# Copy binary from builder
COPY --from=builder /build/worker /app/worker

# Create state directory
RUN mkdir -p /app/state && chown -R dialer:dialer /app

# Switch to non-root user
USER dialer

# Expose ports
EXPOSE 8080 9090

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://localhost:8080/health || exit 1

# Run the worker
ENTRYPOINT ["/app/worker"]
