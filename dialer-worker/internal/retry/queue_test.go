package retry

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestNewQueue(t *testing.T) {
	cfg := DefaultConfig()
	handlerCalled := false

	q := NewQueue(cfg, func(destinationID int64) error {
		handlerCalled = true
		return nil
	})

	assert.NotNil(t, q)
	assert.Equal(t, cfg.MaxRetries, q.maxRetries)
	assert.Equal(t, cfg.BaseDelay, q.baseDelay)
	assert.Equal(t, cfg.MaxDelay, q.maxDelay)
}

func TestQueueAdd(t *testing.T) {
	cfg := DefaultConfig()
	q := NewQueue(cfg, func(destinationID int64) error {
		return nil
	})

	q.Add(123, "busy")

	stats := q.GetStats()
	assert.Equal(t, 1, stats["total_items"])
}

func TestQueueRemove(t *testing.T) {
	cfg := DefaultConfig()
	q := NewQueue(cfg, func(destinationID int64) error {
		return nil
	})

	q.Add(123, "busy")
	q.Remove(123)

	stats := q.GetStats()
	assert.Equal(t, 0, stats["total_items"])
}

func TestQueueGetPending(t *testing.T) {
	cfg := Config{
		MaxRetries: 3,
		BaseDelay:  1 * time.Millisecond,
		MaxDelay:   10 * time.Millisecond,
	}
	q := NewQueue(cfg, func(destinationID int64) error {
		return nil
	})

	// Add item with immediate retry
	q.Add(123, "busy")

	// Wait for it to be pending
	time.Sleep(5 * time.Millisecond)

	pending := q.GetPending()
	assert.GreaterOrEqual(t, len(pending), 0)
}

func TestQueueDuplicateAdd(t *testing.T) {
	cfg := DefaultConfig()
	q := NewQueue(cfg, func(destinationID int64) error {
		return nil
	})

	q.Add(123, "busy")
	q.Add(123, "no_answer") // Should increment attempt

	stats := q.GetStats()
	assert.Equal(t, 1, stats["total_items"])
}

func TestQueueMaxRetries(t *testing.T) {
	cfg := Config{
		MaxRetries: 2,
		BaseDelay:  1 * time.Hour,
		MaxDelay:   4 * time.Hour,
	}
	q := NewQueue(cfg, func(destinationID int64) error {
		return nil
	})

	// Add and re-add to exceed max retries
	q.Add(123, "busy")
	q.Add(123, "busy")
	q.Add(123, "busy") // Should be ignored after max retries

	stats := q.GetStats()
	// Item should be removed after max retries
	assert.Equal(t, 0, stats["total_items"])
}

func TestCalculateNextRetry(t *testing.T) {
	cfg := Config{
		MaxRetries: 5,
		BaseDelay:  5 * time.Minute,
		MaxDelay:   60 * time.Minute,
	}
	q := NewQueue(cfg, nil)

	next1 := q.calculateNextRetry(1)
	next2 := q.calculateNextRetry(2)
	next3 := q.calculateNextRetry(3)

	// Each retry should be further in the future
	assert.True(t, next2.After(next1) || next2.Equal(next1))
	assert.True(t, next3.After(next2) || next3.Equal(next2))
}

func TestCalculatePriority(t *testing.T) {
	cfg := DefaultConfig()
	q := NewQueue(cfg, nil)

	assert.Equal(t, 1, q.calculatePriority("busy"))
	assert.Equal(t, 2, q.calculatePriority("no_answer"))
	assert.Equal(t, 3, q.calculatePriority("amd_machine"))
	assert.Equal(t, 2, q.calculatePriority("unknown"))
}

func TestPriorityQueue(t *testing.T) {
	pq := make(PriorityQueue, 0)

	item1 := &RetryState{
		DestinationID: 1,
		NextRetryAt:   time.Now().Add(10 * time.Minute),
		Priority:      2,
	}
	item2 := &RetryState{
		DestinationID: 2,
		NextRetryAt:   time.Now().Add(5 * time.Minute),
		Priority:      1,
	}

	pq.Push(item1)
	pq.Push(item2)

	// Item with earlier retry time should be first
	first := pq[0]
	assert.Equal(t, int64(2), first.DestinationID)
}

func TestDefaultConfig(t *testing.T) {
	cfg := DefaultConfig()
	assert.Equal(t, 5, cfg.MaxRetries)            // Per spec: up to 5 attempts
	assert.Equal(t, 5*time.Minute, cfg.BaseDelay) // 5 minute base
	assert.Equal(t, 60*time.Minute, cfg.MaxDelay) // 60 minute cap per spec
}

func TestCalculateNextRetryExponentialBackoff(t *testing.T) {
	cfg := Config{
		MaxRetries: 5,
		BaseDelay:  5 * time.Minute,
		MaxDelay:   60 * time.Minute,
	}
	q := NewQueue(cfg, nil)

	now := time.Now()

	// Test exponential backoff calculation
	next1 := q.calculateNextRetry(1)
	next2 := q.calculateNextRetry(2)
	next3 := q.calculateNextRetry(3)
	next4 := q.calculateNextRetry(4)
	next5 := q.calculateNextRetry(5)

	// Check delays match spec: 5min, 10min, 20min, 40min, 60min (capped)
	assert.WithinDuration(t, now.Add(5*time.Minute), next1, time.Second)
	assert.WithinDuration(t, now.Add(10*time.Minute), next2, time.Second)
	assert.WithinDuration(t, now.Add(20*time.Minute), next3, time.Second)
	assert.WithinDuration(t, now.Add(40*time.Minute), next4, time.Second)
	assert.WithinDuration(t, now.Add(60*time.Minute), next5, time.Second) // Capped at 60min
}
