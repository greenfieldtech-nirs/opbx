# Build stage
FROM golang:1.25-alpine AS builder

WORKDIR /app

# Cache module downloads in their own layer
COPY go.mod go.sum ./
RUN go mod download

COPY . .

# go.mod/go.sum are committed; no `go mod tidy` at build time (reproducible builds)
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /worker ./cmd/worker

# Final stage
FROM alpine:3.24

RUN apk --no-cache add ca-certificates wget \
    && addgroup -S -g 10001 app && adduser -S -u 10001 -G app app

WORKDIR /app
COPY --from=builder /worker /app/worker

USER app

# Health-check port (see WEBHOOK_PORT)
EXPOSE 8081

CMD ["/app/worker"]
