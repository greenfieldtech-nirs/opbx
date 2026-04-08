# Build stage
FROM golang:1.21-alpine AS builder

WORKDIR /app

# Install dependencies
RUN apk add --no-cache git

# Copy go mod files
COPY go.mod go.sum ./
RUN go mod download

# Copy source code
COPY . .

# Tidy modules and build
RUN go mod tidy && \
    CGO_ENABLED=0 GOOS=linux go build -a -installsuffix cgo -o worker cmd/worker/main.go

# Final stage
FROM alpine:latest

RUN apk --no-cache add ca-certificates

WORKDIR /root/

# Copy binary from builder
COPY --from=builder /app/worker .

# Expose webhook port
EXPOSE 8081

CMD ["./worker"]