package callerid

import (
	"context"
	"fmt"

	"opbx/dialer-worker/internal/models"
	"opbx/dialer-worker/internal/redis"
)

const roundRobinKeyTemplate = "dialer:rr:%d:position"

// RoundRobinStrategy implements weighted round-robin Caller ID selection
type RoundRobinStrategy struct {
	redisClient *redis.Client
}

// NewRoundRobinStrategy creates a new round-robin strategy
func NewRoundRobinStrategy(redisClient *redis.Client) *RoundRobinStrategy {
	return &RoundRobinStrategy{redisClient: redisClient}
}

// Name returns the strategy identifier
func (s *RoundRobinStrategy) Name() string {
	return "round_robin"
}

// Select chooses the next Caller ID from the pool using round-robin
func (s *RoundRobinStrategy) Select(
	ctx context.Context,
	campaignID int64,
	pool []models.CallerIDPoolItem,
) (*models.CallerIDPoolItem, error) {
	return s.SelectWithRetry(ctx, campaignID, pool, nil)
}

// SelectWithRetry selects a Caller ID excluding already tried DIDs
func (s *RoundRobinStrategy) SelectWithRetry(
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

	// Handle weighted round robin
	// Expand pool based on weights: [A:1, B:2] -> [A, B, B]
	expandedPool := s.expandPoolByWeight(availablePool)
	poolSize := len(expandedPool)

	// Get current position from Redis
	key := fmt.Sprintf(roundRobinKeyTemplate, campaignID)

	// Atomically increment and get new value
	position, err := s.redisClient.Incr(ctx, key)
	if err != nil {
		// Fallback to random on Redis error
		position = int64(randomInt(poolSize))
	}

	// Calculate index (1-based position to 0-based index)
	index := int((position - 1) % int64(poolSize))
	selected := expandedPool[index]

	// Set TTL on the key (24 hours)
	if err := s.redisClient.Expire(ctx, key, 24*60*60); err != nil {
		// Error is intentionally ignored - key will work without TTL
		_ = err
	}

	return &selected, nil
}

// expandPoolByWeight creates a weighted slice for fair distribution
func (s *RoundRobinStrategy) expandPoolByWeight(pool []models.CallerIDPoolItem) []models.CallerIDPoolItem {
	var expanded []models.CallerIDPoolItem
	for _, item := range pool {
		weight := item.Weight
		if weight < 1 {
			weight = 1
		}
		for i := 0; i < weight; i++ {
			expanded = append(expanded, item)
		}
	}
	return expanded
}
