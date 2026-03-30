package metrics

import (
	"fmt"
	"net/http"
	"sync"
	"time"

	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/promauto"
	"github.com/prometheus/client_golang/prometheus/promhttp"
	"github.com/rs/zerolog/log"
)

// Collector holds all Prometheus metrics
type Collector struct {
	// Call metrics
	callsInitiated *prometheus.CounterVec
	callsCompleted *prometheus.CounterVec
	callsFailed    *prometheus.CounterVec
	callDuration   *prometheus.HistogramVec
	activeCalls    prometheus.Gauge

	// Campaign metrics
	campaignsActive prometheus.Gauge

	// Retry metrics
	retryAttempts *prometheus.CounterVec
	retryFailures *prometheus.CounterVec

	// Circuit breaker metrics
	circuitBreakerState prometheus.Gauge
	circuitBreakerTrips *prometheus.CounterVec

	// System metrics
	apiLatency *prometheus.HistogramVec
	errorRate  *prometheus.CounterVec

	mu sync.RWMutex
}

// NewCollector creates a new metrics collector
func NewCollector() *Collector {
	return &Collector{
		callsInitiated: promauto.NewCounterVec(
			prometheus.CounterOpts{
				Name: "dialer_calls_initiated_total",
				Help: "Total number of outbound calls initiated",
			},
			[]string{"campaign_id"},
		),
		callsCompleted: promauto.NewCounterVec(
			prometheus.CounterOpts{
				Name: "dialer_calls_completed_total",
				Help: "Total number of outbound calls completed",
			},
			[]string{"campaign_id", "status"},
		),
		callsFailed: promauto.NewCounterVec(
			prometheus.CounterOpts{
				Name: "dialer_calls_failed_total",
				Help: "Total number of outbound calls failed",
			},
			[]string{"campaign_id", "reason"},
		),
		callDuration: promauto.NewHistogramVec(
			prometheus.HistogramOpts{
				Name:    "dialer_call_duration_seconds",
				Help:    "Duration of outbound calls in seconds",
				Buckets: prometheus.DefBuckets,
			},
			[]string{"campaign_id"},
		),
		activeCalls: promauto.NewGauge(
			prometheus.GaugeOpts{
				Name: "dialer_active_calls",
				Help: "Number of currently active calls",
			},
		),
		campaignsActive: promauto.NewGauge(
			prometheus.GaugeOpts{
				Name: "dialer_active_campaigns",
				Help: "Number of currently active campaigns",
			},
		),
		retryAttempts: promauto.NewCounterVec(
			prometheus.CounterOpts{
				Name: "dialer_retry_attempts_total",
				Help: "Total number of retry attempts",
			},
			[]string{"reason"},
		),
		retryFailures: promauto.NewCounterVec(
			prometheus.CounterOpts{
				Name: "dialer_retry_failures_total",
				Help: "Total number of retry failures",
			},
			[]string{"reason"},
		),
		circuitBreakerState: promauto.NewGauge(
			prometheus.GaugeOpts{
				Name: "dialer_circuit_breaker_state",
				Help: "Current state of circuit breaker (0=closed, 1=open, 2=half-open)",
			},
		),
		circuitBreakerTrips: promauto.NewCounterVec(
			prometheus.CounterOpts{
				Name: "dialer_circuit_breaker_trips_total",
				Help: "Total number of circuit breaker trips",
			},
			[]string{"reason"},
		),
		apiLatency: promauto.NewHistogramVec(
			prometheus.HistogramOpts{
				Name:    "dialer_api_latency_seconds",
				Help:    "Latency of API calls in seconds",
				Buckets: []float64{.005, .01, .025, .05, .1, .25, .5, 1, 2.5, 5, 10},
			},
			[]string{"endpoint", "status"},
		),
		errorRate: promauto.NewCounterVec(
			prometheus.CounterOpts{
				Name: "dialer_errors_total",
				Help: "Total number of errors",
			},
			[]string{"type"},
		),
	}
}

// RecordCallInitiated increments the calls initiated counter
func (c *Collector) RecordCallInitiated(campaignID int64) {
	c.callsInitiated.WithLabelValues(fmt.Sprintf("%d", campaignID)).Inc()
	c.activeCalls.Inc()
}

// RecordCallCompleted records a completed call
func (c *Collector) RecordCallCompleted(campaignID int64, duration float64) {
	c.callsCompleted.WithLabelValues(fmt.Sprintf("%d", campaignID), "completed").Inc()
	c.callDuration.WithLabelValues(fmt.Sprintf("%d", campaignID)).Observe(duration)
	c.activeCalls.Dec()
}

// RecordCallFailed records a failed call
func (c *Collector) RecordCallFailed(campaignID int64) {
	c.callsFailed.WithLabelValues(fmt.Sprintf("%d", campaignID), "failed").Inc()
	c.activeCalls.Dec()
}

// RecordRetryAttempt records a retry attempt
func (c *Collector) RecordRetryAttempt(reason string) {
	c.retryAttempts.WithLabelValues(reason).Inc()
}

// RecordRetryFailure records a retry failure
func (c *Collector) RecordRetryFailure(reason string) {
	c.retryFailures.WithLabelValues(reason).Inc()
}

// SetCircuitBreakerState updates the circuit breaker state metric
func (c *Collector) SetCircuitBreakerState(state string) {
	var value float64
	switch state {
	case "closed":
		value = 0
	case "open":
		value = 1
	case "half-open":
		value = 2
	}
	c.circuitBreakerState.Set(value)
}

// RecordCircuitBreakerTrip records a circuit breaker trip
func (c *Collector) RecordCircuitBreakerTrip(reason string) {
	c.circuitBreakerTrips.WithLabelValues(reason).Inc()
}

// SetActiveCampaigns sets the number of active campaigns
func (c *Collector) SetActiveCampaigns(count int) {
	c.campaignsActive.Set(float64(count))
}

// RecordAPILatency records API call latency
func (c *Collector) RecordAPILatency(endpoint, status string, duration time.Duration) {
	c.apiLatency.WithLabelValues(endpoint, status).Observe(duration.Seconds())
}

// RecordError records an error
func (c *Collector) RecordError(errorType string) {
	c.errorRate.WithLabelValues(errorType).Inc()
}

// StartMetricsServer starts the Prometheus metrics HTTP server
func (c *Collector) StartMetricsServer(addr string) {
	mux := http.NewServeMux()
	mux.Handle("/metrics", promhttp.Handler())
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte(`{"status":"healthy"}`))
	})

	server := &http.Server{
		Addr:         addr,
		Handler:      mux,
		ReadTimeout:  10 * time.Second,
		WriteTimeout: 10 * time.Second,
	}

	log.Info().Str("addr", addr).Msg("Starting metrics server")
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Error().Err(err).Msg("Metrics server error")
	}
}

// GetRegistry returns the Prometheus registry
func (c *Collector) GetRegistry() *prometheus.Registry {
	return prometheus.NewRegistry()
}
