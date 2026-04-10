package callerid

import (
	"context"
	"math/rand"
	"time"

	"opbx/dialer-worker/internal/models"
	"opbx/dialer-worker/internal/redis"
)

func init() {
	rand.Seed(time.Now().UnixNano())
}

// Strategy defines the interface for Caller ID selection strategies
type Strategy interface {
	// Select chooses the next Caller ID from the pool
	// Returns the selected Caller ID and its DID ID
	Select(ctx context.Context, campaignID int64, pool []models.CallerIDPoolItem) (*models.CallerIDPoolItem, error)

	// SelectWithRetry selects a Caller ID excluding already tried DIDs
	// Used when retrying a failed call to ensure a different Caller ID is used
	SelectWithRetry(ctx context.Context, campaignID int64, pool []models.CallerIDPoolItem, triedDIDs []int64) (*models.CallerIDPoolItem, error)

	// Name returns the strategy identifier
	Name() string
}

// StrategyFactory creates the appropriate strategy implementation
type StrategyFactory struct {
	redisClient *redis.Client
}

// NewStrategyFactory creates a new strategy factory
func NewStrategyFactory(redisClient *redis.Client) *StrategyFactory {
	return &StrategyFactory{redisClient: redisClient}
}

// Create returns the appropriate strategy implementation for the given strategy type
func (f *StrategyFactory) Create(strategy models.CallerIDStrategy) Strategy {
	switch strategy {
	case models.StrategyRoundRobin:
		return NewRoundRobinStrategy(f.redisClient)
	case models.StrategyRandom:
		return NewRandomStrategy()
	case models.StrategyLeastRecentlyUsed:
		return NewLRUStrategy(f.redisClient)
	default:
		// Default to round robin
		return NewRoundRobinStrategy(f.redisClient)
	}
}

// randomInt returns a random integer in range [0, max)
func randomInt(max int) int {
	if max <= 0 {
		return 0
	}
	return rand.Intn(max)
}
