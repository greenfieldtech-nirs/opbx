package circuitbreaker

import (
	"sync"
	"time"

	"github.com/rs/zerolog/log"
	"github.com/sony/gobreaker"
)

// Breaker implements circuit breaker pattern for AI agent error handling
type Breaker struct {
	cb      *gobreaker.CircuitBreaker
	mu      sync.RWMutex
	state   string
	onOpen  func()
	onClose func()
}

// Config holds circuit breaker configuration
type Config struct {
	MaxFailures int
	Timeout     time.Duration
	Interval    time.Duration
}

// DefaultConfig returns default circuit breaker configuration
func DefaultConfig() Config {
	return Config{
		MaxFailures: 5,
		Timeout:     5 * time.Minute,
		Interval:    10 * time.Second,
	}
}

// NewBreaker creates a new circuit breaker
func NewBreaker(cfg Config, onOpen, onClose func()) *Breaker {
	settings := gobreaker.Settings{
		Name:        "ai-agent-circuit-breaker",
		MaxRequests: uint32(cfg.MaxFailures),
		Interval:    cfg.Interval,
		Timeout:     cfg.Timeout,
		ReadyToTrip: func(counts gobreaker.Counts) bool {
			failureRatio := float64(counts.TotalFailures) / float64(counts.Requests)
			return counts.Requests >= 3 && failureRatio >= 0.6
		},
		OnStateChange: func(name string, from gobreaker.State, to gobreaker.State) {
			log.Info().
				Str("breaker_name", name).
				Str("from_state", stateToString(from)).
				Str("to_state", stateToString(to)).
				Msg("Circuit breaker state changed")

			b := &Breaker{}
			b.mu.Lock()
			b.state = stateToString(to)
			b.mu.Unlock()

			switch to {
			case gobreaker.StateOpen:
				if onOpen != nil {
					onOpen()
				}
			case gobreaker.StateClosed:
				if onClose != nil {
					onClose()
				}
			}
		},
	}

	b := &Breaker{
		cb:      gobreaker.NewCircuitBreaker(settings),
		state:   "closed",
		onOpen:  onOpen,
		onClose: onClose,
	}

	return b
}

// Execute runs the given function if the circuit is closed
func (b *Breaker) Execute(fn func() error) error {
	_, err := b.cb.Execute(func() (interface{}, error) {
		return nil, fn()
	})
	return err
}

// IsOpen returns true if the circuit is open
func (b *Breaker) IsOpen() bool {
	return b.cb.State() == gobreaker.StateOpen
}

// IsClosed returns true if the circuit is closed
func (b *Breaker) IsClosed() bool {
	return b.cb.State() == gobreaker.StateClosed
}

// RecordFailure records a failure in the circuit breaker
func (b *Breaker) RecordFailure() {
	// Execute a failing function to increment failure count
	b.cb.Execute(func() (interface{}, error) {
		return nil, gobreaker.ErrOpenState
	})
}

// RecordSuccess records a success in the circuit breaker
func (b *Breaker) RecordSuccess() {
	// Execute a successful function
	b.cb.Execute(func() (interface{}, error) {
		return nil, nil
	})
}

// GetState returns the current state as a string
func (b *Breaker) GetState() string {
	b.mu.RLock()
	defer b.mu.RUnlock()
	return b.state
}

// GetStats returns circuit breaker statistics
func (b *Breaker) GetStats() map[string]interface{} {
	return map[string]interface{}{
		"state":     b.GetState(),
		"is_open":   b.IsOpen(),
		"is_closed": b.IsClosed(),
	}
}

// stateToString converts gobreaker state to string
func stateToString(state gobreaker.State) string {
	switch state {
	case gobreaker.StateClosed:
		return "closed"
	case gobreaker.StateOpen:
		return "open"
	case gobreaker.StateHalfOpen:
		return "half-open"
	default:
		return "unknown"
	}
}
