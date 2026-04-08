.PHONY: build run test clean docker-build docker-run fmt lint

# Variables
BINARY_NAME=worker
DOCKER_IMAGE=opbx-dialer-worker

# Build the binary
build:
	go build -o $(BINARY_NAME) cmd/worker/main.go

# Run the worker locally
run:
	go run cmd/worker/main.go

# Run tests
test:
	go test -v ./...

# Run tests with coverage
test-coverage:
	go test -cover -coverprofile=coverage.out ./...
	go tool cover -html=coverage.out -o coverage.html

# Clean build artifacts
clean:
	rm -f $(BINARY_NAME)
	rm -f coverage.out coverage.html

# Format code
fmt:
	go fmt ./...

# Run linter (requires golangci-lint)
lint:
	golangci-lint run

# Download dependencies
deps:
	go mod download
	go mod tidy

# Docker commands
docker-build:
	docker build -t $(DOCKER_IMAGE):latest .

docker-run:
	docker run --rm -it --env-file .env $(DOCKER_IMAGE):latest

# Development setup
dev-setup:
	cp .env.example .env
	@echo "Please edit .env with your configuration"

# Health check
health:
	curl http://localhost:8081/webhooks/health
