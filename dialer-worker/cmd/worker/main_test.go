package main

import (
	"testing"
	"time"

	"github.com/nirsolutions/opbx-dialer-worker/internal/config"
	"github.com/stretchr/testify/assert"
)

func TestLoadConfig(t *testing.T) {
	// Set required environment variables
	t.Setenv("WORKER_ID", "test-worker")
	t.Setenv("WORKER_API_PORT", "8080")
	t.Setenv("METRICS_PORT", "9090")
	t.Setenv("LARAVEL_API_URL", "http://localhost:8000")
	t.Setenv("LARAVEL_API_TOKEN", "test-token")
	t.Setenv("CLOUDONIX_API_URL", "https://api.cloudonix.io")
	t.Setenv("CLOUDONIX_API_KEY", "test-key")
	t.Setenv("CLOUDONIX_DOMAIN", "test.domain.cloudonix.io")

	cfg, err := config.Load()
	assert.NoError(t, err)
	assert.NotNil(t, cfg)
	assert.Equal(t, "test-worker", cfg.WorkerID)
	assert.Equal(t, 8080, cfg.WorkerAPIPort)
	assert.Equal(t, 9090, cfg.MetricsPort)
}

func TestLoadConfigMissingRequired(t *testing.T) {
	// Clear all env vars
	t.Setenv("WORKER_ID", "")

	_, err := config.Load()
	assert.Error(t, err)
}
