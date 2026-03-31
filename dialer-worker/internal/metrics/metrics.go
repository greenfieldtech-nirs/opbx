package metrics

import (
	"encoding/json"
	"net/http"
	"sync"
	"time"

	"github.com/rs/zerolog/log"
)

// SimpleMetrics holds basic metrics without external dependencies
type SimpleMetrics struct {
	mu sync.RWMutex

	// Call counts
	CallsInitiated int64 `json:"calls_initiated"`
	CallsCompleted int64 `json:"calls_completed"`
	CallsFailed    int64 `json:"calls_failed"`

	// Current state
	ActiveCalls     int64 `json:"active_calls"`
	ActiveCampaigns int64 `json:"active_campaigns"`

	// Circuit breaker
	CircuitBreakerState string `json:"circuit_breaker_state"`
	CircuitBreakerTrips int64  `json:"circuit_breaker_trips"`

	// Retry stats
	RetryAttempts int64 `json:"retry_attempts"`
	RetryFailures int64 `json:"retry_failures"`

	// Uptime
	StartedAt time.Time `json:"started_at"`
}

// Collector provides a simplified metrics interface
type Collector struct {
	metrics *SimpleMetrics
	mu      sync.RWMutex
}

// NewCollector creates a new simple metrics collector
func NewCollector() *Collector {
	return &Collector{
		metrics: &SimpleMetrics{
			StartedAt:           time.Now(),
			CircuitBreakerState: "closed",
		},
	}
}

// RecordCallInitiated increments the calls initiated counter
func (c *Collector) RecordCallInitiated(campaignID int64) {
	c.mu.Lock()
	c.metrics.CallsInitiated++
	c.metrics.ActiveCalls++
	c.mu.Unlock()
}

// RecordCallCompleted records a completed call
func (c *Collector) RecordCallCompleted(campaignID int64, duration float64) {
	c.mu.Lock()
	c.metrics.CallsCompleted++
	c.metrics.ActiveCalls--
	c.mu.Unlock()
}

// RecordCallFailed records a failed call
func (c *Collector) RecordCallFailed(campaignID int64) {
	c.mu.Lock()
	c.metrics.CallsFailed++
	c.metrics.ActiveCalls--
	c.mu.Unlock()
}

// RecordRetryAttempt records a retry attempt
func (c *Collector) RecordRetryAttempt(reason string) {
	c.mu.Lock()
	c.metrics.RetryAttempts++
	c.mu.Unlock()
}

// RecordRetryFailure records a retry failure
func (c *Collector) RecordRetryFailure(reason string) {
	c.mu.Lock()
	c.metrics.RetryFailures++
	c.mu.Unlock()
}

// SetCircuitBreakerState updates the circuit breaker state
func (c *Collector) SetCircuitBreakerState(state string) {
	c.mu.Lock()
	c.metrics.CircuitBreakerState = state
	c.mu.Unlock()
}

// RecordCircuitBreakerTrip records a circuit breaker trip
func (c *Collector) RecordCircuitBreakerTrip(reason string) {
	c.mu.Lock()
	c.metrics.CircuitBreakerTrips++
	c.mu.Unlock()
}

// SetActiveCampaigns sets the number of active campaigns
func (c *Collector) SetActiveCampaigns(count int) {
	c.mu.Lock()
	c.metrics.ActiveCampaigns = int64(count)
	c.mu.Unlock()
}

// GetMetrics returns a copy of current metrics
func (c *Collector) GetMetrics() SimpleMetrics {
	c.mu.RLock()
	defer c.mu.RUnlock()

	// Return a copy
	return *c.metrics
}

// HTTP handler for /status endpoint
func (c *Collector) StatusHandler(w http.ResponseWriter, r *http.Request) {
	metrics := c.GetMetrics()

	response := map[string]interface{}{
		"status":     "healthy",
		"uptime":     time.Since(metrics.StartedAt).String(),
		"started_at": metrics.StartedAt.Format(time.RFC3339),
		"metrics":    metrics,
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(response)
}

// StartMetricsServer is deprecated - metrics now served on main worker port
func (c *Collector) StartMetricsServer(addr string) {
	log.Warn().Msg("StartMetricsServer is deprecated, metrics are now available on /status endpoint")
}
