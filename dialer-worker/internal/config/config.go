package config

import (
	"fmt"
	"os"
	"strconv"
	"time"
)

// Config holds all configuration for the worker
type Config struct {
	// Worker identity
	WorkerID string

	// Server port
	WorkerAPIPort int

	// Laravel API
	LaravelAPIURL   string
	LaravelAPIToken string

	// Worker settings
	MaxConcurrentCalls int
	DefaultCallTimeout int
	StateDir           string
	LogLevel           string

	// Circuit breaker
	CircuitBreakerThreshold      int
	CircuitBreakerTimeoutMinutes int
}

// Load loads configuration from environment variables
func Load() (*Config, error) {
	cfg := &Config{
		WorkerID:                     getEnv("WORKER_ID", "dialer-worker-1"),
		WorkerAPIPort:                getEnvInt("WORKER_API_PORT", 8080),
		LaravelAPIURL:                getEnv("LARAVEL_API_URL", "http://localhost:8000"),
		LaravelAPIToken:              getEnv("LARAVEL_API_TOKEN", ""),
		MaxConcurrentCalls:           getEnvInt("MAX_CONCURRENT_CALLS_GLOBAL", 10),
		DefaultCallTimeout:           getEnvInt("DEFAULT_CALL_TIMEOUT", 30),
		StateDir:                     getEnv("STATE_DIR", "/app/state"),
		LogLevel:                     getEnv("LOG_LEVEL", "info"),
		CircuitBreakerThreshold:      getEnvInt("CIRCUIT_BREAKER_THRESHOLD", 5),
		CircuitBreakerTimeoutMinutes: getEnvInt("CIRCUIT_BREAKER_TIMEOUT", 5),
	}

	// Validate required fields
	if cfg.WorkerID == "" {
		return nil, fmt.Errorf("WORKER_ID is required")
	}
	if cfg.LaravelAPIToken == "" {
		return nil, fmt.Errorf("LARAVEL_API_TOKEN is required")
	}

	return cfg, nil
}

// RetryConfig holds retry queue configuration
type RetryConfig struct {
	MaxRetries int
	BaseDelay  time.Duration
	MaxDelay   time.Duration
}

// GetRetryConfig returns retry configuration
func (c *Config) GetRetryConfig() RetryConfig {
	return RetryConfig{
		MaxRetries: 3,
		BaseDelay:  5 * time.Minute,
		MaxDelay:   4 * time.Hour,
	}
}

// CircuitBreakerConfig holds circuit breaker configuration
type CircuitBreakerConfig struct {
	MaxFailures int
	Timeout     time.Duration
	Interval    time.Duration
}

// GetCircuitBreakerConfig returns circuit breaker configuration
func (c *Config) GetCircuitBreakerConfig() CircuitBreakerConfig {
	return CircuitBreakerConfig{
		MaxFailures: c.CircuitBreakerThreshold,
		Timeout:     time.Duration(c.CircuitBreakerTimeoutMinutes) * time.Minute,
		Interval:    10 * time.Second,
	}
}

func getEnv(key, defaultValue string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return defaultValue
}

func getEnvInt(key string, defaultValue int) int {
	if value := os.Getenv(key); value != "" {
		if intVal, err := strconv.Atoi(value); err == nil {
			return intVal
		}
	}
	return defaultValue
}
