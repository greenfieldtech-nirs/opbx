package redis

import (
	"context"
	"fmt"
	"time"

	"github.com/go-redis/redis/v8"
	"opbx/dialer-worker/internal/config"
)

// Key prefixes for Redis
type KeyPrefix string

const (
	PrefixCampaignState KeyPrefix = "dialer:campaign"
	PrefixLock          KeyPrefix = "dialer:lock"
	PrefixRetry         KeyPrefix = "dialer:retry"
	PrefixCAC           KeyPrefix = "dialer:cac"
)

// Timeout and TTL constants
const (
	// DefaultConnectTimeout is the timeout for Redis connection attempts
	DefaultConnectTimeout = 5 * time.Second
	// DefaultLockTTL is the default TTL for distributed locks
	DefaultLockTTL = 30 * time.Second
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

	ctx, cancel := context.WithTimeout(context.Background(), DefaultConnectTimeout)
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

// DecrementActiveCalls decrements the active call count for a campaign, never below zero.
// Only used to roll back a slot reserved for a call that was never placed; Laravel
// releases slots for real calls and reconciles the count (dialer:reconcile-cac).
func (c *Client) DecrementActiveCalls(ctx context.Context, campaignID int64) (int64, error) {
	key := fmt.Sprintf("%s:%d:active", PrefixCAC, campaignID)
	return c.client.Eval(ctx,
		`local n = tonumber(redis.call('GET', KEYS[1]) or '0'); if n > 0 then return redis.call('DECR', KEYS[1]) end; return 0`,
		[]string{key}).Int64()
}

// IncrementIfBelow atomically increments a counter if it's below the max value
// Returns the new value and true if incremented, or current value and false if at/max
func (c *Client) IncrementIfBelow(ctx context.Context, key string, max int64) (int64, bool, error) {
	luaScript := `
		local current = tonumber(redis.call('GET', KEYS[1]) or 0)
		if current >= tonumber(ARGV[1]) then
			return {-1, current}
		end
		local new = redis.call('INCR', KEYS[1])
		return {new, new}
	`
	result, err := c.client.Eval(ctx, luaScript, []string{key}, max).Result()
	if err != nil {
		return 0, false, err
	}

	values := result.([]interface{})
	newVal := values[0].(int64)

	if newVal == -1 {
		return values[1].(int64), false, nil
	}
	return newVal, true, nil
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

// === Caller ID Pool Strategy Methods ===

// Incr atomically increments a key and returns the new value
func (c *Client) Incr(ctx context.Context, key string) (int64, error) {
	return c.client.Incr(ctx, key).Result()
}

// Expire sets a TTL on a key (in seconds)
func (c *Client) Expire(ctx context.Context, key string, seconds int) error {
	return c.client.Expire(ctx, key, time.Duration(seconds)*time.Second).Err()
}

// HGetAll gets all fields from a hash
func (c *Client) HGetAll(ctx context.Context, key string) (map[string]string, error) {
	return c.client.HGetAll(ctx, key).Result()
}

// HSet sets a field in a hash
func (c *Client) HSet(ctx context.Context, key string, field string, value interface{}) error {
	return c.client.HSet(ctx, key, field, value).Err()
}

// SAdd adds members to a set
func (c *Client) SAdd(ctx context.Context, key string, members ...interface{}) error {
	return c.client.SAdd(ctx, key, members...).Err()
}

// SMembers returns all members of a set
func (c *Client) SMembers(ctx context.Context, key string) ([]string, error) {
	return c.client.SMembers(ctx, key).Result()
}

// Del deletes a key
func (c *Client) Del(ctx context.Context, key string) error {
	return c.client.Del(ctx, key).Err()
}
