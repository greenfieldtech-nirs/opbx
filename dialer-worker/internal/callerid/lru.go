package callerid

import (
	"context"
	"fmt"
	"strconv"
	"time"

	"opbx/dialer-worker/internal/models"
	"opbx/dialer-worker/internal/redis"
)

const lruKeyTemplate = "dialer:lru:%d:timestamps"

// LRUStrategy implements least-recently-used Caller ID selection
type LRUStrategy struct {
	redisClient *redis.Client
}

// NewLRUStrategy creates a new LRU strategy
func NewLRUStrategy(redisClient *redis.Client) *LRUStrategy {
	return &LRUStrategy{redisClient: redisClient}
}

// Name returns the strategy identifier
func (s *LRUStrategy) Name() string {
	return "least_recently_used"
}

// Select chooses the least recently used Caller ID from the pool
func (s *LRUStrategy) Select(
	ctx context.Context,
	campaignID int64,
	pool []models.CallerIDPoolItem,
) (*models.CallerIDPoolItem, error) {
	return s.SelectWithRetry(ctx, campaignID, pool, nil)
}

// SelectWithRetry selects a Caller ID excluding already tried DIDs
func (s *LRUStrategy) SelectWithRetry(
	ctx context.Context,
	campaignID int64,
	pool []models.CallerIDPoolItem,
	triedDIDs []int64,
) (*models.CallerIDPoolItem, error) {
	if len(pool) == 0 {
		return nil, fmt.Errorf("caller ID pool is empty")
	}

	// Build exclusion set from tried DIDs
	excludedDIDs := make(map[int64]bool)
	for _, didID := range triedDIDs {
		excludedDIDs[didID] = true
	}

	// Filter pool to exclude tried DIDs
	availablePool := make([]models.CallerIDPoolItem, 0)
	for _, item := range pool {
		if !excludedDIDs[item.DIDID] {
			availablePool = append(availablePool, item)
		}
	}

	// If all DIDs have been tried, reset and use full pool
	if len(availablePool) == 0 {
		availablePool = pool
	}

	key := fmt.Sprintf(lruKeyTemplate, campaignID)

	// Build map of DID IDs in pool for quick lookup
	poolDIDs := make(map[int64]models.CallerIDPoolItem)
	for _, item := range availablePool {
		poolDIDs[item.DIDID] = item
	}

	// Get all timestamps from Redis
	timestamps, err := s.redisClient.HGetAll(ctx, key)
	if err != nil {
		// On Redis error, fall back to random
		return s.fallbackRandom(availablePool)
	}

	// Find the least recently used DID that's in our pool
	var selected *models.CallerIDPoolItem
	var oldestTime int64 = -1
	now := time.Now().Unix()

	// Check all items in pool
	for didID, item := range poolDIDs {
		timestampStr, exists := timestamps[strconv.FormatInt(didID, 10)]

		if !exists {
			// Never used - this is our candidate (use negative timestamp for sorting)
			if oldestTime == -1 || 0 < oldestTime {
				itemCopy := item
				selected = &itemCopy
				oldestTime = 0
			}
			continue
		}

		timestamp, err := strconv.ParseInt(timestampStr, 10, 64)
		if err != nil {
			continue
		}

		if oldestTime == -1 || timestamp < oldestTime {
			// Make a copy to avoid pointer issues
			itemCopy := item
			selected = &itemCopy
			oldestTime = timestamp
		}
	}

	// If nothing found (shouldn't happen), pick first
	if selected == nil {
		itemCopy := availablePool[0]
		selected = &itemCopy
	}

	// Update the timestamp for the selected DID
	if err := s.redisClient.HSet(ctx, key, strconv.FormatInt(selected.DIDID, 10), now); err != nil {
		// Error is intentionally ignored - call can proceed without LRU tracking
		_ = err
	}
	if err := s.redisClient.Expire(ctx, key, 24*60*60); err != nil { // 24 hour TTL
		// Error is intentionally ignored - key will work without TTL
		_ = err
	}

	return selected, nil
}

// fallbackRandom provides a simple random fallback on Redis errors
func (s *LRUStrategy) fallbackRandom(pool []models.CallerIDPoolItem) (*models.CallerIDPoolItem, error) {
	// Simple random fallback
	index := randomInt(len(pool))
	selected := pool[index]
	return &selected, nil
}

// MarkAsUsed updates the LRU timestamp for a DID (called after successful call initiation)
func (s *LRUStrategy) MarkAsUsed(ctx context.Context, campaignID, didID int64) error {
	key := fmt.Sprintf(lruKeyTemplate, campaignID)
	now := time.Now().Unix()
	return s.redisClient.HSet(ctx, key, strconv.FormatInt(didID, 10), now)
}
