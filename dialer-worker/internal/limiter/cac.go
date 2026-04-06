package limiter

import (
	"context"
	"fmt"
	"math"
	"sync"
	"time"

	"opbx/dialer-worker/internal/redis"
)

// CACRateLimiter implements rate limiting based on Concurrent Active Calls (CAC)
// per the functional specification
type CACRateLimiter struct {
	redis     *redis.Client
	mu        sync.RWMutex
	campaigns map[int64]*CampaignLimiter
}

// CampaignLimiter tracks rate limiting state for a single campaign
type CampaignLimiter struct {
	CampaignID   int64
	CAC          int
	ActiveCalls  int
	LastCallTime time.Time
	MinInterval  time.Duration
}

// NewCACRateLimiter creates a new CAC-based rate limiter
func NewCACRateLimiter(redisClient *redis.Client) *CACRateLimiter {
	return &CACRateLimiter{
		redis:     redisClient,
		campaigns: make(map[int64]*CampaignLimiter),
	}
}

// RegisterCampaign registers a campaign for rate limiting
func (rl *CACRateLimiter) RegisterCampaign(campaignID int64, cac int) {
	rl.mu.Lock()
	defer rl.mu.Unlock()

	// Calculate minimum interval between calls: 60/CAC seconds
	// This ensures we don't exceed CAC calls per minute
	minInterval := time.Duration(math.Round(60.0/float64(cac))) * time.Second

	rl.campaigns[campaignID] = &CampaignLimiter{
		CampaignID:  campaignID,
		CAC:         cac,
		MinInterval: minInterval,
	}
}

// UnregisterCampaign removes a campaign from rate limiting
func (rl *CACRateLimiter) UnregisterCampaign(campaignID int64) {
	rl.mu.Lock()
	defer rl.mu.Unlock()
	delete(rl.campaigns, campaignID)
}

// CanDial checks if a call can be dialed for the given campaign
func (rl *CACRateLimiter) CanDial(ctx context.Context, campaignID int64) (bool, error) {
	rl.mu.RLock()
	limiter, exists := rl.campaigns[campaignID]
	rl.mu.RUnlock()

	if !exists {
		return false, fmt.Errorf("campaign %d not registered", campaignID)
	}

	// Check CAC limit against Redis
	activeCalls, err := rl.redis.GetActiveCalls(ctx, campaignID)
	if err != nil {
		return false, err
	}

	if int(activeCalls) >= limiter.CAC {
		return false, nil // CAC limit reached
	}

	// Check minimum interval between calls
	now := time.Now()
	if now.Sub(limiter.LastCallTime) < limiter.MinInterval {
		return false, nil // Rate limited by time
	}

	return true, nil
}

// WaitTime returns how long to wait before next call can be made
func (rl *CACRateLimiter) WaitTime(campaignID int64) time.Duration {
	rl.mu.RLock()
	limiter, exists := rl.campaigns[campaignID]
	rl.mu.RUnlock()

	if !exists {
		return 0
	}

	elapsed := time.Since(limiter.LastCallTime)
	if elapsed >= limiter.MinInterval {
		return 0
	}

	return limiter.MinInterval - elapsed
}

// RecordCall records that a call was made
func (rl *CACRateLimiter) RecordCall(campaignID int64) {
	rl.mu.Lock()
	defer rl.mu.Unlock()

	if limiter, exists := rl.campaigns[campaignID]; exists {
		limiter.LastCallTime = time.Now()
	}
}

// IncrementActive increments active call count in Redis
func (rl *CACRateLimiter) IncrementActive(ctx context.Context, campaignID int64) (int64, error) {
	return rl.redis.IncrementActiveCalls(ctx, campaignID)
}

// DecrementActive decrements active call count in Redis
func (rl *CACRateLimiter) DecrementActive(ctx context.Context, campaignID int64) (int64, error) {
	return rl.redis.DecrementActiveCalls(ctx, campaignID)
}

// GetActiveCount gets current active call count from Redis
func (rl *CACRateLimiter) GetActiveCount(ctx context.Context, campaignID int64) (int64, error) {
	return rl.redis.GetActiveCalls(ctx, campaignID)
}

// IsCACAvailable checks if CAC slots are available without recording
func (rl *CACRateLimiter) IsCACAvailable(ctx context.Context, campaignID int64) (bool, error) {
	rl.mu.RLock()
	limiter, exists := rl.campaigns[campaignID]
	rl.mu.RUnlock()

	if !exists {
		return false, nil
	}

	activeCalls, err := rl.redis.GetActiveCalls(ctx, campaignID)
	if err != nil {
		return false, err
	}

	return int(activeCalls) < limiter.CAC, nil
}
