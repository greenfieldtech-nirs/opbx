package redis

import (
	"context"
	"fmt"
	"strconv"
	"time"

	"github.com/go-redis/redis/v8"
	"opbx/dialer-worker/internal/config"
)

// Key prefixes for Redis
type KeyPrefix string

const (
	PrefixCallState     KeyPrefix = "dialer:call"
	PrefixCampaignState KeyPrefix = "dialer:campaign"
	PrefixWorkerState   KeyPrefix = "dialer:worker"
	PrefixLock          KeyPrefix = "dialer:lock"
	PrefixIdempotency   KeyPrefix = "dialer:idem"
	PrefixRetry         KeyPrefix = "dialer:retry"
	PrefixCAC           KeyPrefix = "dialer:cac"
)

// Client handles Redis operations for the dialer worker
type Client struct {
	client *redis.Client
}

// NewClient creates a new Redis client
func NewClient(cfg *config.Config) (*Client, error) {
	client := redis.NewClient(&redis.Options{
		Addr:     fmt.Sprintf("%s:%s", cfg.RedisHost, cfg.RedisPort),
		Password: cfg.RedisPassword,
		DB:       cfg.RedisDB,
	})

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if err := client.Ping(ctx).Err(); err != nil {
		return nil, fmt.Errorf("failed to connect to Redis: %w", err)
	}

	return &Client{client: client}, nil
}

// Close closes the Redis connection
func (c *Client) Close() error {
	return c.client.Close()
}

// === Call State Management ===

// SetCallState stores call state in Redis with TTL
func (c *Client) SetCallState(ctx context.Context, callID string, state *CallState, ttl time.Duration) error {
	key := fmt.Sprintf("%s:%s", PrefixCallState, callID)
	// Use pipeline to set hash fields and TTL atomically
	pipe := c.client.Pipeline()
	pipe.HSet(ctx, key, state.ToMap())
	pipe.Expire(ctx, key, ttl)
	_, err := pipe.Exec(ctx)
	return err
}

// GetCallState retrieves call state from Redis
func (c *Client) GetCallState(ctx context.Context, callID string) (*CallState, error) {
	key := fmt.Sprintf("%s:%s", PrefixCallState, callID)
	result, err := c.client.HGetAll(ctx, key).Result()
	if err != nil {
		return nil, err
	}
	if len(result) == 0 {
		return nil, nil
	}
	return CallStateFromMap(result), nil
}

// DeleteCallState removes call state from Redis
func (c *Client) DeleteCallState(ctx context.Context, callID string) error {
	key := fmt.Sprintf("%s:%s", PrefixCallState, callID)
	return c.client.Del(ctx, key).Err()
}

// UpdateCallStateTTL updates the TTL of a call state based on session status
// - If status is "connected", TTL is removed (key persists until CDR)
// - Otherwise, TTL is extended by 60 seconds
func (c *Client) UpdateCallStateTTL(ctx context.Context, callID string, status string) error {
	key := fmt.Sprintf("%s:%s", PrefixCallState, callID)

	if status == "connected" {
		// Remove TTL when call is connected - will persist until CDR
		return c.client.Persist(ctx, key).Err()
	}

	// Extend TTL by 60 seconds for other statuses
	return c.client.Expire(ctx, key, 60*time.Second).Err()
}

// IncrementActiveCalls increments active call count for a campaign
func (c *Client) IncrementActiveCalls(ctx context.Context, campaignID int64) (int64, error) {
	key := fmt.Sprintf("%s:%d:active", PrefixCAC, campaignID)
	return c.client.Incr(ctx, key).Result()
}

// DecrementActiveCalls decrements active call count for a campaign
func (c *Client) DecrementActiveCalls(ctx context.Context, campaignID int64) (int64, error) {
	key := fmt.Sprintf("%s:%d:active", PrefixCAC, campaignID)
	return c.client.Decr(ctx, key).Result()
}

// GetActiveCalls gets the current active call count for a campaign
func (c *Client) GetActiveCalls(ctx context.Context, campaignID int64) (int64, error) {
	key := fmt.Sprintf("%s:%d:active", PrefixCAC, campaignID)
	result, err := c.client.Get(ctx, key).Int64()
	if err == redis.Nil {
		// Key doesn't exist, return 0
		return 0, nil
	}
	return result, err
}

// ResetActiveCalls sets the active call counter to zero for a campaign
func (c *Client) ResetActiveCalls(ctx context.Context, campaignID int64) error {
	key := fmt.Sprintf("%s:%d:active", PrefixCAC, campaignID)
	return c.client.Set(ctx, key, 0, 0).Err()
}

// CountCallStatesForCampaign counts how many dialer:call:* keys exist for a given campaign.
// This scans all call state hashes and checks the campaign_id field.
func (c *Client) CountCallStatesForCampaign(ctx context.Context, campaignID int64) (int64, error) {
	pattern := fmt.Sprintf("%s:*", PrefixCallState)
	var count int64
	campaignStr := strconv.FormatInt(campaignID, 10)

	iter := c.client.Scan(ctx, 0, pattern, 100).Iterator()
	for iter.Next(ctx) {
		key := iter.Val()
		cid, err := c.client.HGet(ctx, key, "campaign_id").Result()
		if err != nil {
			continue
		}
		if cid == campaignStr {
			count++
		}
	}
	if err := iter.Err(); err != nil {
		return count, err
	}
	return count, nil
}

// ReconcileActiveCalls reconciles the CAC counter with actual call state keys.
// If the counter is higher than the number of live call states, it resets the
// counter to the actual count. Returns the reconciled count.
func (c *Client) ReconcileActiveCalls(ctx context.Context, campaignID int64) (int64, bool, error) {
	counter, err := c.GetActiveCalls(ctx, campaignID)
	if err != nil {
		return 0, false, err
	}

	actual, err := c.CountCallStatesForCampaign(ctx, campaignID)
	if err != nil {
		return counter, false, err
	}

	if counter > actual {
		// Counter is inflated — reset to actual
		key := fmt.Sprintf("%s:%d:active", PrefixCAC, campaignID)
		if err := c.client.Set(ctx, key, actual, 0).Err(); err != nil {
			return counter, false, err
		}
		return actual, true, nil
	}

	return counter, false, nil
}

// === Lock Management ===

// AcquireLock attempts to acquire a distributed lock
func (c *Client) AcquireLock(ctx context.Context, lockKey string, ttl time.Duration) (bool, error) {
	key := fmt.Sprintf("%s:%s", PrefixLock, lockKey)
	// Use SET with NX (only if not exists) and EX (expire)
	result, err := c.client.SetNX(ctx, key, "1", ttl).Result()
	return result, err
}

// ReleaseLock releases a distributed lock
func (c *Client) ReleaseLock(ctx context.Context, lockKey string) error {
	key := fmt.Sprintf("%s:%s", PrefixLock, lockKey)
	return c.client.Del(ctx, key).Err()
}

// === Idempotency ===

// IsIdempotent checks if a webhook has been processed
func (c *Client) IsIdempotent(ctx context.Context, eventID string) (bool, error) {
	key := fmt.Sprintf("%s:%s", PrefixIdempotency, eventID)
	exists, err := c.client.Exists(ctx, key).Result()
	return exists > 0, err
}

// MarkIdempotent marks an event as processed
func (c *Client) MarkIdempotent(ctx context.Context, eventID string, ttl time.Duration) error {
	key := fmt.Sprintf("%s:%s", PrefixIdempotency, eventID)
	return c.client.Set(ctx, key, "1", ttl).Err()
}

// === Retry Queue ===

// ScheduleRetry schedules a destination for retry after a delay
func (c *Client) ScheduleRetry(ctx context.Context, destinationID int64, campaignID int64, delay time.Duration) error {
	key := fmt.Sprintf("%s:%d", PrefixRetry, campaignID)
	score := time.Now().Add(delay).Unix()
	return c.client.ZAdd(ctx, key, &redis.Z{
		Score:  float64(score),
		Member: destinationID,
	}).Err()
}

// GetRetryableDestinations gets destinations ready for retry
func (c *Client) GetRetryableDestinations(ctx context.Context, campaignID int64, limit int) ([]int64, error) {
	key := fmt.Sprintf("%s:%d", PrefixRetry, campaignID)
	now := float64(time.Now().Unix())

	results, err := c.client.ZRangeByScore(ctx, key, &redis.ZRangeBy{
		Min:    "0",
		Max:    fmt.Sprintf("%f", now),
		Offset: 0,
		Count:  int64(limit),
	}).Result()

	if err != nil {
		return nil, err
	}

	destinations := make([]int64, len(results))
	for i, r := range results {
		// Parse the member (destinationID stored as string)
		fmt.Sscanf(r, "%d", &destinations[i])
	}

	return destinations, nil
}

// RemoveRetry removes a destination from the retry queue
func (c *Client) RemoveRetry(ctx context.Context, campaignID int64, destinationID int64) error {
	key := fmt.Sprintf("%s:%d", PrefixRetry, campaignID)
	return c.client.ZRem(ctx, key, destinationID).Err()
}

// === Worker State ===

// RegisterWorker registers the worker as active
func (c *Client) RegisterWorker(ctx context.Context, workerID string, ttl time.Duration) error {
	key := fmt.Sprintf("%s:%s", PrefixWorkerState, workerID)
	return c.client.Set(ctx, key, time.Now().Unix(), ttl).Err()
}

// GetWorkerCount returns the number of active workers
func (c *Client) GetWorkerCount(ctx context.Context) (int64, error) {
	pattern := fmt.Sprintf("%s:*", PrefixWorkerState)
	var count int64

	iter := c.client.Scan(ctx, 0, pattern, 0).Iterator()
	for iter.Next(ctx) {
		count++
	}

	return count, iter.Err()
}

// === CallState struct for Redis storage ===

type CallState struct {
	SessionID     int64
	CampaignID    int64
	DestinationID int64
	Status        string
	StartedAt     time.Time
}

func (cs *CallState) ToMap() map[string]interface{} {
	return map[string]interface{}{
		"session_id":     strconv.FormatInt(cs.SessionID, 10),
		"campaign_id":    strconv.FormatInt(cs.CampaignID, 10),
		"destination_id": strconv.FormatInt(cs.DestinationID, 10),
		"status":         cs.Status,
		"started_at":     cs.StartedAt.Format(time.RFC3339),
	}
}

func CallStateFromMap(m map[string]string) *CallState {
	startedAt, _ := time.Parse(time.RFC3339, m["started_at"])
	sessionID, _ := strconv.ParseInt(m["session_id"], 10, 64)
	campaignID, _ := strconv.ParseInt(m["campaign_id"], 10, 64)
	destinationID, _ := strconv.ParseInt(m["destination_id"], 10, 64)

	return &CallState{
		SessionID:     sessionID,
		CampaignID:    campaignID,
		DestinationID: destinationID,
		Status:        m["status"],
		StartedAt:     startedAt,
	}
}
