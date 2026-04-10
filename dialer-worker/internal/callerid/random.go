package callerid

import (
	"context"
	"fmt"

	"opbx/dialer-worker/internal/models"
)

// RandomStrategy implements weighted random Caller ID selection
type RandomStrategy struct{}

// NewRandomStrategy creates a new random strategy
func NewRandomStrategy() *RandomStrategy {
	return &RandomStrategy{}
}

// Name returns the strategy identifier
func (s *RandomStrategy) Name() string {
	return "random"
}

// Select chooses a random Caller ID from the pool using weighted random selection
func (s *RandomStrategy) Select(
	ctx context.Context,
	campaignID int64,
	pool []models.CallerIDPoolItem,
) (*models.CallerIDPoolItem, error) {
	return s.SelectWithRetry(ctx, campaignID, pool, nil)
}

// SelectWithRetry selects a Caller ID excluding already tried DIDs
func (s *RandomStrategy) SelectWithRetry(
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

	// Weighted random selection
	totalWeight := 0
	for _, item := range availablePool {
		weight := item.Weight
		if weight < 1 {
			weight = 1
		}
		totalWeight += weight
	}

	// Generate random number in range [1, totalWeight]
	target := randomInt(totalWeight) + 1

	// Find the selected item
	cumulativeWeight := 0
	for _, item := range availablePool {
		weight := item.Weight
		if weight < 1 {
			weight = 1
		}
		cumulativeWeight += weight
		if target <= cumulativeWeight {
			// Return copy to avoid pointer issues
			selected := item
			return &selected, nil
		}
	}

	// Fallback to last item (should not reach here)
	selected := availablePool[len(availablePool)-1]
	return &selected, nil
}
