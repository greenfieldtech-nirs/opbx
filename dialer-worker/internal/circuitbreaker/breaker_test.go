package circuitbreaker

import (
	"errors"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestNewBreaker(t *testing.T) {
	cfg := DefaultConfig()
	cfg.MaxFailures = 3
	cfg.Timeout = 100 * time.Millisecond

	openCalled := false
	closeCalled := false

	b := NewBreaker(cfg,
		func() { openCalled = true },
		func() { closeCalled = true },
	)

	assert.NotNil(t, b)
	assert.True(t, b.IsClosed())
	assert.False(t, b.IsOpen())
	assert.Equal(t, "closed", b.GetState())
}

func TestBreakerExecuteSuccess(t *testing.T) {
	cfg := DefaultConfig()
	b := NewBreaker(cfg, nil, nil)

	fn := func() error {
		return nil
	}

	err := b.Execute(fn)
	assert.NoError(t, err)
}

func TestBreakerExecuteFailure(t *testing.T) {
	cfg := DefaultConfig()
	b := NewBreaker(cfg, nil, nil)

	fn := func() error {
		return errors.New("test error")
	}

	err := b.Execute(fn)
	assert.Error(t, err)
}

func TestBreakerRecordFailure(t *testing.T) {
	cfg := DefaultConfig()
	cfg.MaxFailures = 1
	cfg.Timeout = 100 * time.Millisecond

	openCalled := false
	b := NewBreaker(cfg,
		func() { openCalled = true },
		nil,
	)

	// Record enough failures to trip the breaker
	for i := 0; i < 5; i++ {
		b.RecordFailure()
	}

	// Wait a bit for state change
	time.Sleep(50 * time.Millisecond)

	assert.True(t, b.IsOpen() || openCalled, "Expected circuit to be open or onOpen to be called")
}

func TestBreakerRecordSuccess(t *testing.T) {
	cfg := DefaultConfig()
	b := NewBreaker(cfg, nil, nil)

	b.RecordSuccess()
	assert.True(t, b.IsClosed())
}

func TestBreakerGetStats(t *testing.T) {
	cfg := DefaultConfig()
	b := NewBreaker(cfg, nil, nil)

	stats := b.GetStats()
	assert.NotNil(t, stats)
	assert.Contains(t, stats, "state")
	assert.Contains(t, stats, "is_open")
	assert.Contains(t, stats, "is_closed")
}

func TestStateToString(t *testing.T) {
	assert.Equal(t, "closed", stateToString(0))
	assert.Equal(t, "open", stateToString(1))
	assert.Equal(t, "half-open", stateToString(2))
	assert.Equal(t, "unknown", stateToString(99))
}
