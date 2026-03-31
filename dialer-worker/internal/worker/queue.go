package worker

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
	"github.com/rs/zerolog/log"
)

// WorkQueue distributes destinations across multiple workers using Redis
type WorkQueue struct {
	redis         *redis.Client
	workerID      string
	queueKey      string // "dialer:destinations:pending"
	processingKey string // "dialer:destinations:processing"
	leaseDuration time.Duration
}

// DestinationTask represents a destination to be called
type DestinationTask struct {
	DestinationID  int64                  `json:"destination_id"`
	CampaignID     int64                  `json:"campaign_id"`
	PhoneNumber    string                 `json:"phone_number"`
	ContactData    map[string]interface{} `json:"contact_data,omitempty"`
	CloudonixCreds CloudonixCredentials   `json:"cloudonix_creds"`
	AddedAt        time.Time              `json:"added_at"`
	Priority       int                    `json:"priority"`
}

// CloudonixCredentials for the organization
type CloudonixCredentials struct {
	APIKey string `json:"api_key"`
	Domain string `json:"domain"`
	APIURL string `json:"api_url,omitempty"`
}

// NewWorkQueue creates a new distributed work queue
func NewWorkQueue(redisAddr, workerID string) (*WorkQueue, error) {
	client := redis.NewClient(&redis.Options{
		Addr:     redisAddr,
		Password: "", // Use env var in production
		DB:       0,
	})

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if err := client.Ping(ctx).Err(); err != nil {
		return nil, fmt.Errorf("failed to connect to redis: %w", err)
	}

	return &WorkQueue{
		redis:         client,
		workerID:      workerID,
		queueKey:      "dialer:destinations:pending",
		processingKey: fmt.Sprintf("dialer:destinations:processing:%s", workerID),
		leaseDuration: 5 * time.Minute, // Must complete call within this time
	}, nil
}

// ClaimDestination claims a destination from the queue for this worker
func (w *WorkQueue) ClaimDestination(ctx context.Context) (*DestinationTask, error) {
	// Use Redis RPOPLPUSH for reliable queue pattern
	// Atomically move from pending to processing list
	result, err := w.redis.BRPopLPush(ctx, w.queueKey, w.processingKey, 5*time.Second).Result()
	if err == redis.Nil {
		return nil, nil // No work available
	}
	if err != nil {
		return nil, fmt.Errorf("failed to claim destination: %w", err)
	}

	var task DestinationTask
	if err := json.Unmarshal([]byte(result), &task); err != nil {
		return nil, fmt.Errorf("failed to unmarshal task: %w", err)
	}

	// Set expiry on processing key (in case worker dies)
	w.redis.Expire(ctx, w.processingKey, w.leaseDuration)

	log.Debug().
		Str("worker_id", w.workerID).
		Int64("destination_id", task.DestinationID).
		Msg("Claimed destination")

	return &task, nil
}

// CompleteDestination marks a destination as completed and removes from processing
func (w *WorkQueue) CompleteDestination(ctx context.Context, destinationID int64) error {
	// Remove from processing queue
	// First, find and remove the specific task
	processing, err := w.redis.LRange(ctx, w.processingKey, 0, -1).Result()
	if err != nil {
		return err
	}

	for _, item := range processing {
		var task DestinationTask
		if err := json.Unmarshal([]byte(item), &task); err != nil {
			continue
		}
		if task.DestinationID == destinationID {
			return w.redis.LRem(ctx, w.processingKey, 0, item).Err()
		}
	}

	return nil
}

// RequeueDestination returns a destination to the pending queue (for retries)
func (w *WorkQueue) RequeueDestination(ctx context.Context, task *DestinationTask, delay time.Duration) error {
	// Remove from processing
	if err := w.CompleteDestination(ctx, task.DestinationID); err != nil {
		return err
	}

	// Re-add to pending with delay
	data, err := json.Marshal(task)
	if err != nil {
		return err
	}

	if delay > 0 {
		// Use delayed queue with ZSET (score = timestamp)
		score := float64(time.Now().Add(delay).Unix())
		return w.redis.ZAdd(ctx, "dialer:destinations:delayed", redis.Z{
			Score:  score,
			Member: data,
		}).Err()
	}

	// Add to front of queue (LPush for LIFO, RPush for FIFO)
	return w.redis.LPush(ctx, w.queueKey, data).Err()
}

// StartHeartbeat starts a goroutine that keeps the worker alive in Redis
func (w *WorkQueue) StartHeartbeat(ctx context.Context) {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			w.redis.Set(ctx,
				fmt.Sprintf("dialer:workers:%s:last_seen", w.workerID),
				time.Now().Unix(),
				2*time.Minute)
		}
	}
}

// GetActiveWorkers returns list of active workers
func (w *WorkQueue) GetActiveWorkers(ctx context.Context) ([]string, error) {
	pattern := "dialer:workers:*:last_seen"
	keys, err := w.redis.Keys(ctx, pattern).Result()
	if err != nil {
		return nil, err
	}

	var workers []string
	now := time.Now().Unix()
	for _, key := range keys {
		lastSeen, err := w.redis.Get(ctx, key).Int64()
		if err != nil {
			continue
		}
		// Consider active if seen in last 2 minutes
		if now-lastSeen < 120 {
			// Extract worker ID from key
			var workerID string
			fmt.Sscanf(key, "dialer:workers:%s:last_seen", &workerID)
			if workerID != "" {
				workers = append(workers, workerID)
			}
		}
	}

	return workers, nil
}

// Close closes the Redis connection
func (w *WorkQueue) Close() error {
	return w.redis.Close()
}
