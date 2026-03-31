// Package concurrency provides Redis-backed concurrency management for campaigns.
//
// The concurrency manager tracks:
//   - Concurrency Counter: Current number of active calls per campaign
//   - Active Sessions: Set of session tokens for active calls
//
// This enables the dialer to enforce CAC (Concurrent Active Calls) limits
// and properly manage call slots across multiple worker instances.
package concurrency

import (
	"context"
	"fmt"
	"time"

	"github.com/go-redis/redis/v8"
	"github.com/rs/zerolog/log"
)

// Manager handles concurrency tracking via Redis.
// All state is stored in Redis to enable coordination across multiple
// worker instances.
type Manager struct {
	redisClient *redis.Client
}

// NewManager creates a new concurrency manager with the given Redis client.
//
// The Redis client should be configured to connect to the shared Redis
// instance that all workers use for coordination.
func NewManager(redisClient *redis.Client) *Manager {
	return &Manager{
		redisClient: redisClient,
	}
}

// CanStartCall checks if a new call can be started for the given campaign.
//
// Returns true if the current concurrency count is less than the CAC limit.
// This check is performed atomically using Redis.
//
// Parameters:
//   - ctx: Context for the Redis operation
//   - campaignID: The campaign ID to check
//   - cac: The Concurrent Active Calls limit
//
// Returns true if a new call can be started, false otherwise.
func (m *Manager) CanStartCall(ctx context.Context, campaignID int64, cac int) bool {
	counter, err := m.GetActiveCount(ctx, campaignID)
	if err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaignID).
			Msg("Failed to get active count, assuming cannot start call")
		return false
	}

	canStart := counter < cac

	log.Debug().
		Int64("campaign_id", campaignID).
		Int("active_calls", counter).
		Int("cac_limit", cac).
		Int("available_slots", cac-counter).
		Bool("can_start", canStart).
		Msg("Checked campaign concurrency")

	return canStart
}

// StartCall increments the concurrency counter and adds the session to active sessions.
//
// This method should be called AFTER a successful Cloudonix API call to initiate
// a call. It uses a Redis pipeline to atomically update both the counter and
// the active sessions set.
//
// Parameters:
//   - ctx: Context for the Redis operation
//   - campaignID: The campaign ID
//   - sessionToken: The Cloudonix session token (call_id)
//
// Returns an error if the Redis operation fails.
func (m *Manager) StartCall(ctx context.Context, campaignID int64, sessionToken string) error {
	pipe := m.redisClient.Pipeline()

	// Increment the concurrency counter
	counterKey := m.counterKey(campaignID)
	pipe.Incr(ctx, counterKey)

	// Add session to active sessions set
	sessionsKey := m.sessionsKey(campaignID)
	pipe.SAdd(ctx, sessionsKey, sessionToken)

	// Set expiration on keys to prevent orphaned data (7 days)
	pipe.Expire(ctx, counterKey, 7*24*time.Hour)
	pipe.Expire(ctx, sessionsKey, 7*24*time.Hour)

	_, err := pipe.Exec(ctx)
	if err != nil {
		return fmt.Errorf("failed to start call in Redis: %w", err)
	}

	log.Debug().
		Int64("campaign_id", campaignID).
		Str("session_token", sessionToken).
		Msg("Started call - incremented concurrency counter")

	return nil
}

// CompleteCall decrements the concurrency counter and removes the session from active sessions.
//
// This method should be called when a CDR is received, indicating the call has
// completed. It uses a Redis pipeline to atomically update both the counter and
// the active sessions set.
//
// Parameters:
//   - ctx: Context for the Redis operation
//   - campaignID: The campaign ID
//   - sessionToken: The Cloudonix session token (call_id)
//
// Returns an error if the Redis operation fails.
func (m *Manager) CompleteCall(ctx context.Context, campaignID int64, sessionToken string) error {
	pipe := m.redisClient.Pipeline()

	// Decrement the concurrency counter (don't go below 0)
	counterKey := m.counterKey(campaignID)
	pipe.Decr(ctx, counterKey)

	// Remove session from active sessions set
	sessionsKey := m.sessionsKey(campaignID)
	pipe.SRem(ctx, sessionsKey, sessionToken)

	_, err := pipe.Exec(ctx)
	if err != nil {
		return fmt.Errorf("failed to complete call in Redis: %w", err)
	}

	// Ensure counter doesn't go below 0
	current, _ := m.redisClient.Get(ctx, counterKey).Int()
	if current < 0 {
		m.redisClient.Set(ctx, counterKey, 0, 7*24*time.Hour)
	}

	log.Info().
		Int64("campaign_id", campaignID).
		Str("session_token", sessionToken).
		Int("new_count", max(0, current)).
		Msg("Completed call - decremented concurrency counter")

	return nil
}

// GetActiveCount returns the current number of active calls for a campaign.
//
// Parameters:
//   - ctx: Context for the Redis operation
//   - campaignID: The campaign ID
//
// Returns the current active call count, or 0 if there's an error.
func (m *Manager) GetActiveCount(ctx context.Context, campaignID int64) (int, error) {
	counterKey := m.counterKey(campaignID)
	count, err := m.redisClient.Get(ctx, counterKey).Int()
	if err == redis.Nil {
		// Key doesn't exist, means 0 active calls
		return 0, nil
	}
	if err != nil {
		return 0, fmt.Errorf("failed to get active count: %w", err)
	}

	if count < 0 {
		return 0, nil
	}

	return count, nil
}

// GetActiveSessions returns the set of active session tokens for a campaign.
//
// Parameters:
//   - ctx: Context for the Redis operation
//   - campaignID: The campaign ID
//
// Returns a slice of session tokens, or an empty slice if there's an error.
func (m *Manager) GetActiveSessions(ctx context.Context, campaignID int64) ([]string, error) {
	sessionsKey := m.sessionsKey(campaignID)
	sessions, err := m.redisClient.SMembers(ctx, sessionsKey).Result()
	if err == redis.Nil {
		return []string{}, nil
	}
	if err != nil {
		return nil, fmt.Errorf("failed to get active sessions: %w", err)
	}

	return sessions, nil
}

// ResetCampaign resets the concurrency state for a campaign.
//
// This should be called when a campaign is stopped or reset.
// It clears both the counter and active sessions.
//
// Parameters:
//   - ctx: Context for the Redis operation
//   - campaignID: The campaign ID to reset
//
// Returns an error if the Redis operation fails.
func (m *Manager) ResetCampaign(ctx context.Context, campaignID int64) error {
	counterKey := m.counterKey(campaignID)
	sessionsKey := m.sessionsKey(campaignID)

	pipe := m.redisClient.Pipeline()
	pipe.Del(ctx, counterKey)
	pipe.Del(ctx, sessionsKey)

	_, err := pipe.Exec(ctx)
	if err != nil {
		return fmt.Errorf("failed to reset campaign: %w", err)
	}

	log.Info().
		Int64("campaign_id", campaignID).
		Msg("Reset campaign concurrency state")

	return nil
}

// counterKey returns the Redis key for the concurrency counter.
func (m *Manager) counterKey(campaignID int64) string {
	return fmt.Sprintf("campaign:%d:concurrency_counter", campaignID)
}

// sessionsKey returns the Redis key for the active sessions set.
func (m *Manager) sessionsKey(campaignID int64) string {
	return fmt.Sprintf("campaign:%d:active_sessions", campaignID)
}

// max returns the maximum of two integers.
func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}
