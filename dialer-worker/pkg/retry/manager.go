package retry

import (
	"time"

	"opbx/dialer-worker/internal/config"
)

// Manager handles retry logic for destinations
type Manager struct {
	intervals  []time.Duration
	maxRetries int
}

// NewManager creates a new retry manager
func NewManager(cfg *config.Config) *Manager {
	return &Manager{
		intervals:  cfg.RetryIntervals,
		maxRetries: cfg.MaxRetries,
	}
}

// ShouldRetry determines if a destination should be retried
func (m *Manager) ShouldRetry(attemptCount int, disposition string) bool {
	// Check if disposition is retryable
	if !IsRetryableDisposition(disposition) {
		return false
	}

	// Check if max retries exceeded
	return attemptCount < m.maxRetries
}

// GetRetryDelay returns the delay for the next retry attempt
// Per spec: 5min → 15min → 60min → 24hr
func (m *Manager) GetRetryDelay(attemptCount int) time.Duration {
	if attemptCount < 0 {
		attemptCount = 0
	}
	if attemptCount >= len(m.intervals) {
		return m.intervals[len(m.intervals)-1]
	}
	return m.intervals[attemptCount]
}

// GetRetryIntervals returns all retry intervals
func (m *Manager) GetRetryIntervals() []time.Duration {
	return m.intervals
}

// IsRetryableDisposition checks if a disposition allows retry
// Per Laravel: busy, no-answer, cancelled
func IsRetryableDisposition(disposition string) bool {
	switch disposition {
	case "busy", "no-answer", "cancelled":
		return true
	default:
		return false
	}
}

// CalculateNextRetry calculates the next retry timestamp
func (m *Manager) CalculateNextRetry(attemptCount int) time.Time {
	delay := m.GetRetryDelay(attemptCount)
	return time.Now().Add(delay)
}
