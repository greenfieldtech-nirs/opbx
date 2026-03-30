package retry

import (
	"container/heap"
	"context"
	"sync"
	"time"

	"github.com/rs/zerolog/log"
)

// RetryState represents the state of a retry attempt
type RetryState struct {
	DestinationID int64
	Attempt       int
	NextRetryAt   time.Time
	Reason        string
	Priority      int // Lower is higher priority
}

// PriorityQueue implements a priority queue for retry items
type PriorityQueue []*RetryState

func (pq PriorityQueue) Len() int { return len(pq) }

func (pq PriorityQueue) Less(i, j int) bool {
	if pq[i].NextRetryAt.Equal(pq[j].NextRetryAt) {
		return pq[i].Priority < pq[j].Priority
	}
	return pq[i].NextRetryAt.Before(pq[j].NextRetryAt)
}

func (pq PriorityQueue) Swap(i, j int) {
	pq[i], pq[j] = pq[j], pq[i]
}

func (pq *PriorityQueue) Push(x interface{}) {
	item := x.(*RetryState)
	*pq = append(*pq, item)
}

func (pq *PriorityQueue) Pop() interface{} {
	old := *pq
	n := len(old)
	item := old[n-1]
	*pq = old[:n-1]
	return item
}

// Queue manages retry attempts with exponential backoff
type Queue struct {
	items       PriorityQueue
	mu          sync.RWMutex
	maxRetries  int
	baseDelay   time.Duration
	maxDelay    time.Duration
	handler     func(destinationID int64) error
	done        chan struct{}
	isRunning   bool
}

// Config holds retry queue configuration
type Config struct {
	MaxRetries int
	BaseDelay  time.Duration
	MaxDelay   time.Duration
}

// DefaultConfig returns default retry configuration
func DefaultConfig() Config {
	return Config{
		MaxRetries: 3,
		BaseDelay:  5 * time.Minute,
		MaxDelay:   4 * time.Hour,
	}
}

// NewQueue creates a new retry queue
func NewQueue(cfg Config, handler func(destinationID int64) error) *Queue {
	return &Queue{
		items:      make(PriorityQueue, 0),
		maxRetries: cfg.MaxRetries,
		baseDelay:  cfg.BaseDelay,
		maxDelay:   cfg.MaxDelay,
		handler:    handler,
		done:       make(chan struct{}),
	}
}

// Start begins processing the retry queue
func (q *Queue) Start(ctx context.Context) {
	q.mu.Lock()
	if q.isRunning {
		q.mu.Unlock()
		return
	}
	q.isRunning = true
	q.mu.Unlock()

	log.Info().Msg("Starting retry queue processor")

	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			q.Stop()
			return
		case <-q.done:
			return
		case <-ticker.C:
			q.processRetries(ctx)
		}
	}
}

// Stop stops the retry queue processor
func (q *Queue) Stop() {
	q.mu.Lock()
	if !q.isRunning {
		q.mu.Unlock()
		return
	}
	q.isRunning = false
	q.mu.Unlock()

	close(q.done)
	log.Info().Msg("Retry queue processor stopped")
}

// Add adds a destination to the retry queue
func (q *Queue) Add(destinationID int64, reason string) {
	q.mu.Lock()
	defer q.mu.Unlock()

	// Check if already in queue
	for _, item := range q.items {
		if item.DestinationID == destinationID {
			// Update attempt count
			item.Attempt++
			if item.Attempt > q.maxRetries {
				log.Warn().
					Int64("destination_id", destinationID).
					Int("attempts", item.Attempt).
					Msg("Max retries exceeded, removing from queue")
				return
			}
			item.NextRetryAt = q.calculateNextRetry(item.Attempt)
			item.Reason = reason
			heap.Fix(&q.items, q.findIndex(destinationID))
			return
		}
	}

	// Add new item
	item := &RetryState{
		DestinationID: destinationID,
		Attempt:       1,
		NextRetryAt:   q.calculateNextRetry(1),
		Reason:        reason,
		Priority:      q.calculatePriority(reason),
	}

	heap.Push(&q.items, item)

	log.Info().
		Int64("destination_id", destinationID).
		Time("next_retry", item.NextRetryAt).
		Str("reason", reason).
		Int("attempt", item.Attempt).
		Msg("Added to retry queue")
}

// Remove removes a destination from the retry queue
func (q *Queue) Remove(destinationID int64) {
	q.mu.Lock()
	defer q.mu.Unlock()

	idx := q.findIndex(destinationID)
	if idx >= 0 {
		heap.Remove(&q.items, idx)
		log.Debug().
			Int64("destination_id", destinationID).
			Msg("Removed from retry queue")
	}
}

// GetPending returns items that are ready for retry
func (q *Queue) GetPending() []*RetryState {
	q.mu.RLock()
	defer q.mu.RUnlock()

	now := time.Now()
	var pending []*RetryState

	for _, item := range q.items {
		if item.NextRetryAt.Before(now) || item.NextRetryAt.Equal(now) {
			pending = append(pending, item)
		}
	}

	return pending
}

// GetStats returns queue statistics
func (q *Queue) GetStats() map[string]interface{} {
	q.mu.RLock()
	defer q.mu.RUnlock()

	return map[string]interface{}{
		"total_items": len(q.items),
		"max_retries": q.maxRetries,
		"base_delay":  q.baseDelay.String(),
		"max_delay":   q.maxDelay.String(),
	}
}

// processRetries processes items ready for retry
func (q *Queue) processRetries(ctx context.Context) {
	pending := q.GetPending()

	for _, item := range pending {
		select {
		case <-ctx.Done():
			return
		default:
		}

		log.Info().
			Int64("destination_id", item.DestinationID).
			Int("attempt", item.Attempt).
			Msg("Processing retry")

		if err := q.handler(item.DestinationID); err != nil {
			log.Error().
				Err(err).
				Int64("destination_id", item.DestinationID).
				Msg("Retry failed")

			// Re-add for next retry
			q.Add(item.DestinationID, item.Reason)
		} else {
			// Success - remove from queue
			q.Remove(item.DestinationID)
			log.Info().
				Int64("destination_id", item.DestinationID).
				Msg("Retry succeeded")
		}
	}
}

// calculateNextRetry calculates the next retry time with exponential backoff
func (q *Queue) calculateNextRetry(attempt int) time.Time {
	delay := q.baseDelay * time.Duration(1<>(uint(attempt-1)))
	if delay > q.maxDelay {
		delay = q.maxDelay
	}
	return time.Now().Add(delay)
}

// calculatePriority assigns priority based on failure reason
func (q *Queue) calculatePriority(reason string) int {
	switch reason {
	case "amd_machine":
		return 3 // Lower priority
	case "no_answer":
		return 2 // Medium priority
	case "busy":
		return 1 // Higher priority
	default:
		return 2
	}
}

// findIndex finds the index of a destination in the queue
func (q *Queue) findIndex(destinationID int64) int {
	for i, item := range q.items {
		if item.DestinationID == destinationID {
			return i
		}
	}
	return -1
}
