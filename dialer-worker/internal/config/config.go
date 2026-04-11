package config

import (
	"fmt"
	"os"
	"strconv"
	"time"
)

// Config holds all configuration for the dialer worker
type Config struct {
	// Laravel API Configuration
	LaravelAPIURL   string
	LaravelAPIToken string

	// Redis Configuration
	RedisHost     string
	RedisPort     string
	RedisPassword string
	RedisDB       int

	// Worker Configuration
	WorkerID        string
	PollInterval    time.Duration
	HealthCheckPort string
	WebhookPort     string
	WebhookSecret   string

	// Retry Configuration (per spec)
	RetryIntervals []time.Duration
	MaxRetries     int
}

// Load loads configuration from environment variables
// Returns an error if required configuration values are missing or invalid
func Load() (*Config, error) {
	cfg := &Config{
		LaravelAPIURL:   getEnv("LARAVEL_API_URL", "http://localhost:8000"),
		LaravelAPIToken: getEnv("LARAVEL_API_TOKEN", ""),

		RedisHost:     getEnv("REDIS_HOST", "localhost"),
		RedisPort:     getEnv("REDIS_PORT", "6379"),
		RedisPassword: getEnv("REDIS_PASSWORD", ""),
		RedisDB:       getEnvInt("REDIS_DB", 0),

		WorkerID:        getEnv("WORKER_ID", "worker-1"),
		PollInterval:    getEnvDuration("POLL_INTERVAL", 10*time.Second),
		HealthCheckPort: getEnv("HEALTH_CHECK_PORT", "8080"),
		WebhookPort:     getEnv("WEBHOOK_PORT", "8081"),
		WebhookSecret:   getEnv("WEBHOOK_SECRET", ""),

		// Per spec: 5min → 15min → 60min → 24hr
		RetryIntervals: []time.Duration{
			5 * time.Minute,
			15 * time.Minute,
			60 * time.Minute,
			24 * time.Hour,
		},
		MaxRetries: 4,
	}

	if err := cfg.Validate(); err != nil {
		return nil, err
	}

	return cfg, nil
}

// Validate checks that all required configuration values are present and valid
func (c *Config) Validate() error {
	// Validate Laravel API configuration
	if c.LaravelAPIURL == "" {
		return fmt.Errorf("LARAVEL_API_URL is required")
	}
	if c.LaravelAPIToken == "" {
		return fmt.Errorf("LARAVEL_API_TOKEN is required")
	}

	// Validate Redis configuration
	if c.RedisHost == "" {
		return fmt.Errorf("REDIS_HOST is required")
	}
	if c.RedisPort == "" {
		return fmt.Errorf("REDIS_PORT is required")
	}
	if c.RedisDB < 0 {
		return fmt.Errorf("REDIS_DB must be non-negative")
	}

	// Validate Worker configuration
	if c.WorkerID == "" {
		return fmt.Errorf("WORKER_ID is required")
	}
	if c.PollInterval <= 0 {
		return fmt.Errorf("POLL_INTERVAL must be positive")
	}
	if c.HealthCheckPort == "" {
		return fmt.Errorf("HEALTH_CHECK_PORT is required")
	}
	if c.WebhookPort == "" {
		return fmt.Errorf("WEBHOOK_PORT is required")
	}
	if c.WebhookSecret == "" {
		return fmt.Errorf("WEBHOOK_SECRET is required")
	}

	return nil
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

func getEnvDuration(key string, defaultValue time.Duration) time.Duration {
	if value := os.Getenv(key); value != "" {
		if duration, err := time.ParseDuration(value); err == nil {
			return duration
		}
	}
	return defaultValue
}
