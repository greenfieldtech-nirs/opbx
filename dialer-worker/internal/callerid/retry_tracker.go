package callerid

import (
	"context"
	"fmt"
	"strconv"
	"time"

	"opbx/dialer-worker/internal/redis"
)

const retryTrackerKeyTemplate = "dialer:retry:%d:%d"
const retryTrackerTTL = 1 * time.Hour

// RetryTracker tracks which Caller IDs have been tried for a destination
type RetryTracker struct {
	redisClient *redis.Client
}

// NewRetryTracker creates a new retry tracker
func NewRetryTracker(redisClient *redis.Client) *RetryTracker {
	return &RetryTracker{redisClient: redisClient}
}

// GetTriedDIDs returns the list of DIDs already tried for a destination
func (rt *RetryTracker) GetTriedDIDs(ctx context.Context, campaignID, destinationID int64) ([]int64, error) {
	key := fmt.Sprintf(retryTrackerKeyTemplate, campaignID, destinationID)
	members, err := rt.redisClient.SMembers(ctx, key)
	if err != nil {
		return nil, err
	}

	triedDIDs := make([]int64, 0, len(members))
	for _, member := range members {
		didID, err := strconv.ParseInt(member, 10, 64)
		if err != nil {
			continue
		}
		triedDIDs = append(triedDIDs, didID)
	}

	return triedDIDs, nil
}

// MarkDIDAsTried adds a DID to the tried set for a destination
func (rt *RetryTracker) MarkDIDAsTried(ctx context.Context, campaignID, destinationID, didID int64) error {
	key := fmt.Sprintf(retryTrackerKeyTemplate, campaignID, destinationID)
	if err := rt.redisClient.SAdd(ctx, key, didID); err != nil {
		return err
	}
	// Set/refresh TTL on the set
	return rt.redisClient.Expire(ctx, key, int(retryTrackerTTL.Seconds()))
}

// ClearTriedDIDs removes the retry tracking for a destination (call succeeded or max retries reached)
func (rt *RetryTracker) ClearTriedDIDs(ctx context.Context, campaignID, destinationID int64) error {
	key := fmt.Sprintf(retryTrackerKeyTemplate, campaignID, destinationID)
	return rt.redisClient.Del(ctx, key)
}
