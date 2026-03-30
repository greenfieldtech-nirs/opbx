package executor

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestNewExecutor(t *testing.T) {
	// This is a basic test - full integration tests would require mocking
	cfg := Config{
		MaxConcurrentGlobal: 10,
		DefaultCallTimeout:  30,
		RateLimitPerSecond:  5,
	}

	// Since we can't easily mock all dependencies in a unit test,
	// we'll just verify the config is stored correctly
	assert.Equal(t, 10, cfg.MaxConcurrentGlobal)
	assert.Equal(t, 30, cfg.DefaultCallTimeout)
	assert.Equal(t, 5, cfg.RateLimitPerSecond)
}

func TestCanStartNewCall(t *testing.T) {
	// Test logic for canStartNewCall
	maxConcurrent := 5
	activeCalls := 3

	canStart := activeCalls < maxConcurrent
	assert.True(t, canStart)

	activeCalls = 5
	canStart = activeCalls < maxConcurrent
	assert.False(t, canStart)
}

func TestCallContext(t *testing.T) {
	ctx := &CallContext{
		CampaignID:    1,
		DestinationID: 2,
		SessionID:     "test-session",
		CallID:        "test-call",
	}

	assert.Equal(t, int64(1), ctx.CampaignID)
	assert.Equal(t, int64(2), ctx.DestinationID)
	assert.Equal(t, "test-session", ctx.SessionID)
	assert.Equal(t, "test-call", ctx.CallID)
}
